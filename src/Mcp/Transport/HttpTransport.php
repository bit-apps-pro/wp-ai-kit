<?php

namespace BitApps\AiKit\Mcp\Transport;

use BitApps\AiKit\Mcp\Auth\BearerGrant;
use BitApps\AiKit\Mcp\Auth\BearerResolverInterface;
use BitApps\AiKit\Mcp\Auth\WwwAuthenticate;
use BitApps\AiKit\Mcp\Server\JsonRpc;
use BitApps\AiKit\Mcp\Server\McpServer;
use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Tools\ToolContext;

/**
 * Streamable HTTP for MCP on the plugin's REST namespace: `POST <ns>/mcp`.
 *
 * Stateless (no Mcp-Session-Id), JSON responses only (no SSE), as the spec
 * permits. Registered with native register_rest_route(): MCP needs raw JSON-RPC
 * bodies, and wp-kit's router would wrap them in its envelope.
 *
 * Authentication: WordPress itself has already resolved Application Passwords
 * (HTTP Basic) and cookie + REST nonce before the permission callback runs, so a
 * logged-in user here is authenticated by core and is not scope-limited.
 * Otherwise a `Bearer` token is offered to each resolver (OAuth, API keys).
 * Authorization — may this user use the plugin's AI features, which tools — is
 * the ToolGuard's job on every call.
 */
final class HttpTransport
{
    public const ROUTE = '/mcp';

    public const ORIGIN_FILTER = 'bitapps_ai_allowed_origins';

    /** @var BearerResolverInterface[] */
    private array $resolvers;

    private ?BearerGrant $grant = null;

    private ?string $failure = null;

    /** @var callable(): bool */
    private $personalDataAllowed;

    /**
     * @param BearerResolverInterface[] $resolvers
     * @param null|callable(): ?string  $resourceMetadataUrl RFC 9728 document URL when OAuth is on
     * @param null|callable(): bool     $personalDataAllowed site setting for non-scoped sessions
     */
    public function __construct(
        private PluginContext $context,
        private McpServer $server,
        array $resolvers = [],
        private $resourceMetadataUrl = null,
        ?callable $personalDataAllowed = null
    ) {
        $this->resolvers = array_values(array_filter($resolvers, static fn ($r) => $r instanceof BearerResolverInterface));
        $this->personalDataAllowed = $personalDataAllowed ?? static fn () => false;
    }

    /** Called on rest_api_init. */
    public function registerRoutes(): void
    {
        self::registerLazily($this->context->restNamespace(), fn (): self => $this);
    }

    /**
     * Register the endpoint without building the server: `$transport` is only
     * called when a request reaches this route, so other REST requests never
     * load the tool registry and the plugin's tools.
     *
     * @param \Closure(): self $transport
     */
    public static function registerLazily(string $namespace, \Closure $transport): void
    {
        register_rest_route($namespace, self::ROUTE, [
            [
                'methods'             => 'POST',
                'callback'            => static fn ($request) => $transport()->handle($request),
                'permission_callback' => static fn ($request) => $transport()->authorize($request),
            ],
            [
                // Streamable HTTP: GET (SSE stream) and DELETE (session end) are optional; this server offers neither.
                'methods'             => 'GET, DELETE, PUT, PATCH',
                'callback'            => static fn () => $transport()->methodNotAllowed(),
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    /**
     * REST permission callback: authenticate, check Origin, check the plugin's
     * "may use AI" rule. Returns true or a WP_Error carrying the status.
     *
     * @param \WP_REST_Request $request
     *
     * @return bool|\WP_Error
     */
    public function authorize($request)
    {
        $this->grant = null;
        $this->failure = null;

        if (!$this->originAllowed((string) $request->get_header('origin'))) {
            $this->failure = 'origin';

            return new \WP_Error('mcp_origin_forbidden', 'This Origin may not call the MCP endpoint.', ['status' => 403]);
        }

        // Types an HTML form can send without a CORS preflight: never valid MCP, refused before any auth.
        $type = strtolower(trim(explode(';', (string) $request->get_header('content_type'))[0]));
        if (\in_array($type, ['application/x-www-form-urlencoded', 'multipart/form-data', 'text/plain'], true)) {
            $this->failure = 'content_type';

            return new \WP_Error('mcp_unsupported_media_type', 'The MCP endpoint accepts application/json only.', ['status' => 415]);
        }

        $userId = \function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;

        if ($userId <= 0) {
            $token = self::bearerToken((string) $request->get_header('authorization'));
            if ('' !== $token) {
                foreach ($this->resolvers as $resolver) {
                    $grant = $resolver->resolve($token);
                    if (null !== $grant) {
                        $this->grant = $grant;

                        break;
                    }
                }
            }

            if (null === $this->grant || $this->grant->userId() <= 0 || !self::userExists($this->grant->userId())) {
                $this->grant = null;
                $this->failure = '' === $token ? 'missing' : 'invalid';

                return new \WP_Error('mcp_unauthorized', '' === $token ? 'Authentication required.' : 'The access token is invalid, expired or revoked.', ['status' => 401]);
            }

            wp_set_current_user($this->grant->userId());
            $userId = $this->grant->userId();
        }

        if (!$this->context->canUse($userId)) {
            $this->failure = 'forbidden';

            return new \WP_Error('mcp_forbidden', 'This account may not use AI tools on this site.', ['status' => 403]);
        }

        return true;
    }

    /**
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function handle($request)
    {
        $version = (string) $request->get_header('mcp-protocol-version');
        if ('' !== $version && !McpServer::supports($version)) {
            return new \WP_REST_Response(JsonRpc::error(null, JsonRpc::INVALID_REQUEST, "Unsupported MCP-Protocol-Version: {$version}"), 400);
        }

        $decoded = JsonRpc::decode((string) $request->get_body());
        if (!$decoded['ok']) {
            return new \WP_REST_Response($decoded['error'], 400);
        }

        if ($decoded['batch'] && !McpServer::allowsBatches($version)) {
            return new \WP_REST_Response(JsonRpc::error(null, JsonRpc::INVALID_REQUEST, 'JSON-RPC batches are not supported in this protocol version.'), 400);
        }

        $call = $this->toolContext($request);
        $responses = [];
        $stepUp = null;

        foreach ($decoded['messages'] as $message) {
            $response = $this->server->handle($message, $call);
            if (null !== $response) {
                $responses[] = $response;
            }

            $failure = $this->server->lastToolFailure();
            if (!$decoded['batch'] && null !== $failure && 'insufficient_scope' === $failure['code'] && null !== $this->grant) {
                $stepUp = $failure['scope'];
            }
        }

        if ([] === $responses) {
            return new \WP_REST_Response(null, 202);
        }

        $rest = new \WP_REST_Response($decoded['batch'] ? $responses : $responses[0], 200);
        // Core decides its no-cache headers before a bearer token signs the user in.
        $rest->header('Cache-Control', 'no-store');

        if (null !== $stepUp) {
            $rest->set_status(403);
            $rest->header('WWW-Authenticate', WwwAuthenticate::bearer($this->metadataUrl(), $stepUp, 'insufficient_scope', 'This connection needs the "' . $stepUp . '" scope.'));
        }

        return $rest;
    }

    /** @return \WP_REST_Response */
    public function methodNotAllowed()
    {
        $response = new \WP_REST_Response(['code' => 'mcp_method_not_allowed', 'message' => 'The MCP endpoint accepts POST only.'], 405);
        $response->header('Allow', 'POST');

        return $response;
    }

    /**
     * rest_post_dispatch filter: add the challenge to our own 401s.
     *
     * @param mixed $result
     * @param mixed $server
     * @param mixed $request
     *
     * @return mixed
     */
    public function addChallenge($result, $server = null, $request = null)
    {
        if (!$result instanceof \WP_REST_Response || 401 !== $result->get_status() || !$request instanceof \WP_REST_Request) {
            return $result;
        }

        if ($request->get_route() !== '/' . $this->context->restNamespace() . self::ROUTE) {
            return $result;
        }

        $url = $this->metadataUrl();
        $result->header('WWW-Authenticate', null !== $url
            ? WwwAuthenticate::bearer($url, null, 'invalid' === $this->failure ? 'invalid_token' : null)
            : WwwAuthenticate::basic($this->context->name() . ' MCP'));

        return $result;
    }

    /** The full endpoint URL clients connect to. */
    public function endpointUrl(): string
    {
        return rest_url($this->context->restNamespace() . self::ROUTE);
    }

    /** @param \WP_REST_Request $request */
    private function toolContext($request): ToolContext
    {
        if (null !== $this->grant) {
            return new ToolContext(
                ToolContext::CHANNEL_MCP,
                $this->grant->userId(),
                $this->grant->scopes(),
                $this->grant->clientRef(),
                null,
                $this->grant->isUnattended(),
                $this->grant->personalDataAllowed()
            );
        }

        // The User-Agent is whatever the caller sends: shown as a hint, labelled as such.
        $client = trim((string) $request->get_header('user_agent'));

        return new ToolContext(
            ToolContext::CHANNEL_MCP,
            (int) get_current_user_id(),
            null,
            'Application password' . ('' !== $client ? ' · ' . mb_substr($client, 0, 100) : ''),
            null,
            false,
            (bool) ($this->personalDataAllowed)()
        );
    }

    private function metadataUrl(): ?string
    {
        if (null === $this->resourceMetadataUrl) {
            return null;
        }

        $url = ($this->resourceMetadataUrl)();

        return \is_string($url) && '' !== $url ? $url : null;
    }

    /**
     * Browsers send Origin; server-to-server clients (claude.ai, ChatGPT, CLIs)
     * usually do not. A present Origin must be this site or explicitly allowed
     * (DNS-rebinding protection the MCP transport spec asks for).
     */
    private function originAllowed(string $origin): bool
    {
        if ('' === $origin) {
            return true;
        }

        // `null` comes from sandboxed frames and file: pages: never this site.
        if ('null' === $origin) {
            return false;
        }

        if (self::originOf($origin) === self::originOf(home_url())) {
            return true;
        }

        $allowed = apply_filters(self::ORIGIN_FILTER, [], $this->context->slug());

        return \is_array($allowed) && \in_array(rtrim($origin, '/'), array_map(static fn ($o) => rtrim((string) $o, '/'), $allowed), true);
    }

    /** scheme://host:port, lower-cased, default ports filled in. */
    private static function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = (int) ($parts['port'] ?? ('http' === $scheme ? 80 : 443));

        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }

    private static function bearerToken(string $header): string
    {
        return preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) ? $m[1] : '';
    }

    private static function userExists(int $userId): bool
    {
        return !\function_exists('get_userdata') || false !== get_userdata($userId);
    }
}
