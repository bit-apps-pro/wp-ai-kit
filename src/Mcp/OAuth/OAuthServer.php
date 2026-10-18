<?php

namespace BitApps\AiKit\Mcp\OAuth;

use BitApps\AiKit\Mcp\Auth\BearerGrant;
use BitApps\AiKit\Mcp\Auth\BearerResolverInterface;
use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Security\RateLimiter;
use BitApps\AiKit\Tools\Scopes;

/**
 * One plugin's OAuth 2.1 authorization server for its MCP endpoint, as the MCP
 * authorization spec describes it: RFC 9728 + RFC 8414 discovery, Client ID
 * Metadata Documents and RFC 7591 registration, authorization code + PKCE S256,
 * RFC 8707 audience binding, rotating refresh tokens, RFC 7009 revocation.
 *
 * Machine-facing endpoints are native REST routes on the plugin's namespace
 * (public by design: an OAuth client has no WordPress session). The consent
 * screen is on admin-post.php; the discovery documents are answered on `init`
 * by exact path match. It also resolves the bearer tokens it issued for the MCP
 * transport.
 */
final class OAuthServer implements BearerResolverInterface
{
    private const REGISTRATIONS_PER_HOUR = 20;

    private const TOKEN_REQUESTS_PER_MINUTE = 60;

    private OAuthEndpoints $endpoints;

    private ClientRegistry $clients;

    private AuthorizationCodes $codes;

    private TokenService $tokens;

    /** @var callable(): string */
    private $ceiling;

    /** @var callable(): bool */
    private $offersPersonalData;

    /** @var callable(): int */
    private $toolCount;

    /**
     * @param string             $resource           the MCP endpoint URL (resource identifier and issuer)
     * @param callable(): string $ceiling            highest access level the site allows
     * @param callable(): bool   $offersPersonalData whether any tool returns personal data
     * @param callable(): int    $toolCount          tools the current user can use (consent screen)
     */
    public function __construct(
        private PluginContext $context,
        string $resource,
        private OAuthStorageInterface $storage,
        private RateLimiter $limiter,
        callable $ceiling,
        callable $offersPersonalData,
        callable $toolCount
    ) {
        $this->endpoints = new OAuthEndpoints($context, $resource);
        $this->clients = new ClientRegistry($storage, str_replace('-', '', $context->slug()) . '_', $context->optionName(''), $limiter);
        $this->codes = new AuthorizationCodes($context->optionName(''));
        $this->tokens = new TokenService($storage, $context->optionName(''));
        $this->ceiling = $ceiling;
        $this->offersPersonalData = $offersPersonalData;
        $this->toolCount = $toolCount;
    }

    public function endpoints(): OAuthEndpoints
    {
        return $this->endpoints;
    }

    public function clients(): ClientRegistry
    {
        return $this->clients;
    }

    public function tokens(): TokenService
    {
        return $this->tokens;
    }

    public function codes(): AuthorizationCodes
    {
        return $this->codes;
    }

    public function registerRoutes(): void
    {
        self::registerLazily($this->context->restNamespace(), fn (): self => $this);
    }

    /**
     * Register the OAuth endpoints without building the server: `$server` is
     * only called when a request reaches one of them.
     *
     * @param \Closure(): self $server
     */
    public static function registerLazily(string $namespace, \Closure $server): void
    {
        $public = '__return_true';
        $routes = [
            OAuthEndpoints::ROUTE_AUTHORIZE => ['GET', 'handleAuthorize'],
            OAuthEndpoints::ROUTE_TOKEN     => ['POST', 'handleToken'],
            OAuthEndpoints::ROUTE_REGISTER  => ['POST', 'handleRegister'],
            OAuthEndpoints::ROUTE_REVOKE    => ['POST', 'handleRevoke'],
        ];
        foreach ($routes as $route => [$method, $handler]) {
            register_rest_route($namespace, $route, [
                'methods'             => $method,
                'callback'            => static fn ($request) => $server()->{$handler}($request),
                'permission_callback' => $public,
            ]);
        }
    }

    /**
     * GET /authorize: hand the browser to the consent screen with the same
     * parameters. The REST API cannot see the login cookie without a nonce;
     * admin-post.php can.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function handleAuthorize($request)
    {
        $params = ConsentScreen::params((array) $request->get_query_params());
        $response = new \WP_REST_Response(null, 302);
        $response->header('Location', $this->endpoints->consentUrl(array_filter($params, static fn ($v) => '' !== $v)));
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * RFC 7591 registration.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function handleRegister($request)
    {
        if (!$this->limiter->attempt('oauth_register_' . self::clientIp(), self::REGISTRATIONS_PER_HOUR, 3600)['allowed']) {
            return self::error('slow_down', 'Too many registrations from this address. Try again later.', 429);
        }

        $body = $request->get_json_params();
        $body = \is_array($body) ? $body : (array) $request->get_body_params();

        $client = $this->clients->register($body);
        if (null === $client) {
            return self::error('invalid_redirect_uri', 'At least one https, loopback or app redirect_uri is required.', 400);
        }

        return self::json($client, 201);
    }

    /**
     * RFC 6749 token endpoint: code exchange and refresh.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function handleToken($request)
    {
        if (!$this->limiter->attempt('oauth_token_' . self::clientIp(), self::TOKEN_REQUESTS_PER_MINUTE, 60)['allowed']) {
            return self::error('slow_down', 'Too many token requests. Try again in a minute.', 429);
        }

        $body = self::body($request);
        $grantType = (string) ($body['grant_type'] ?? '');

        if ('authorization_code' === $grantType) {
            return $this->exchangeCode($body);
        }

        if ('refresh_token' === $grantType) {
            $tokens = $this->tokens->rotate((string) ($body['refresh_token'] ?? ''), (string) ($body['client_id'] ?? ''), (string) ($body['resource'] ?? ''));

            // invalid_grant specifically: clients re-run authorization on it, and treat other codes as fatal.
            return null === $tokens ? self::error('invalid_grant', 'Unknown, expired or reused refresh token.', 400) : self::json($tokens);
        }

        return self::error('unsupported_grant_type', 'Only authorization_code and refresh_token are supported.', 400);
    }

    /**
     * RFC 7009: always 200, whether or not the token was known.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function handleRevoke($request)
    {
        $body = self::body($request);
        $this->tokens->revokeToken((string) ($body['token'] ?? ''));

        return self::json([]);
    }

    /**
     * The discovery document for a request path, or null when the path is not ours.
     *
     * @return null|array<string, mixed>
     */
    public function wellKnownDocument(string $requestPath): ?array
    {
        $document = $this->endpoints->documentFor($requestPath);
        if (null === $document) {
            return null;
        }

        return OAuthEndpoints::AUTHORIZATION_SERVER === $document
            ? $this->endpoints->authorizationServerMetadata()
            : $this->endpoints->protectedResourceMetadata();
    }

    /** The consent screen, for admin-post.php. */
    public function consentScreen(): ConsentScreen
    {
        return new ConsentScreen($this->context, $this->endpoints, $this->clients, $this->codes, $this->ceiling, $this->offersPersonalData, $this->toolCount);
    }

    public function resolve(string $token): ?BearerGrant
    {
        $grant = $this->tokens->validateAccess($token, $this->endpoints->resource());
        if (null === $grant) {
            return null;
        }

        $this->tokens->touch($grant);

        $scopes = [];
        foreach (preg_split('/\s+/', trim((string) $grant['scope'])) ?: [] as $scope) {
            $scope = $this->context->canonicalScope($scope);
            if (\in_array($scope, Scopes::levels(), true)) {
                $scopes[] = $scope;
            }
        }

        $name = (string) ($grant['client_name'] ?? '');

        return new BearerGrant(
            (int) $grant['user_id'],
            [] !== $scopes ? $scopes : [Scopes::READ],
            '' !== $name ? $name : (string) $grant['client_id'],
            'oauth',
            false,
            !empty($grant['personal_data'])
        );
    }

    /** @param array<string, mixed> $body */
    private function exchangeCode(array $body)
    {
        $grant = $this->codes->consume((string) ($body['code'] ?? ''));
        if (null === $grant) {
            return self::error('invalid_grant', 'Unknown, expired or already used authorization code.', 400);
        }

        if (!hash_equals((string) $grant['client_id'], (string) ($body['client_id'] ?? ''))) {
            return self::error('invalid_grant', 'client_id does not match the authorization.', 400);
        }
        if (!hash_equals((string) $grant['redirect_uri'], (string) ($body['redirect_uri'] ?? ''))) {
            return self::error('invalid_grant', 'redirect_uri does not match the authorization.', 400);
        }
        if (!AuthorizationCodes::verifierMatches((string) ($body['code_verifier'] ?? ''), (string) $grant['code_challenge'])) {
            return self::error('invalid_grant', 'PKCE verification failed.', 400);
        }

        $resource = (string) ($body['resource'] ?? '');
        if ('' !== $resource && untrailingslashit($resource) !== untrailingslashit((string) $grant['resource'])) {
            return self::error('invalid_target', 'The requested resource is not this MCP server.', 400);
        }

        return self::json($this->tokens->mint($grant));
    }

    /**
     * Clients send form-encoded bodies (RFC 6749); JSON is accepted too.
     *
     * @param \WP_REST_Request $request
     *
     * @return array<string, mixed>
     */
    private static function body($request): array
    {
        $body = (array) $request->get_body_params();
        if ([] === $body) {
            $json = $request->get_json_params();
            $body = \is_array($json) ? $json : [];
        }

        return array_map(static fn ($v) => \is_scalar($v) ? (string) $v : '', $body);
    }

    /**
     * The address rate limits count against. Behind a proxy or CDN every client
     * shares REMOTE_ADDR; sites there return the real address from the
     * `bitapps_ai_client_ip` filter (only for a header their proxy sets).
     */
    private static function clientIp(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) && \is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $ip = apply_filters('bitapps_ai_client_ip', $ip);

        return \is_string($ip) && false !== filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }

    /** @return \WP_REST_Response */
    private static function json(array $data, int $status = 200)
    {
        $response = new \WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store');
        $response->header('Pragma', 'no-cache');

        return $response;
    }

    /** RFC 6749 §5.2 error body. */
    private static function error(string $code, string $description, int $status)
    {
        return self::json(['error' => $code, 'error_description' => $description], $status);
    }
}
