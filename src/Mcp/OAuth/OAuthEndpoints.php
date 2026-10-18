<?php

namespace BitApps\AiKit\Mcp\OAuth;

use BitApps\AiKit\Contract;
use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Tools\Scopes;

/**
 * Every URL and discovery document of one plugin's authorization server.
 *
 * The issuer is the plugin's MCP endpoint itself, so the discovery documents sit
 * at path-suffixed well-known URLs (RFC 8414 §3.1, RFC 9728 §3.1):
 * `/.well-known/oauth-authorization-server/wp-json/bitform/v1/mcp`. Several Bit
 * plugins on one site therefore never compete for the bare
 * `/.well-known/oauth-authorization-server` slot, which other MCP plugins also claim.
 */
final class OAuthEndpoints
{
    public const PROTECTED_RESOURCE = 'oauth-protected-resource';

    public const AUTHORIZATION_SERVER = 'oauth-authorization-server';

    public const ROUTE_AUTHORIZE = '/mcp/oauth/authorize';

    public const ROUTE_TOKEN = '/mcp/oauth/token';

    public const ROUTE_REGISTER = '/mcp/oauth/register';

    public const ROUTE_REVOKE = '/mcp/oauth/revoke';

    /** `offline_access` is advertised because Claude asks for it to get a refresh token. */
    public const OFFLINE_ACCESS = 'offline_access';

    public function __construct(private PluginContext $context, private string $resource) {}

    /** The MCP endpoint: the resource identifier and the issuer. */
    public function resource(): string
    {
        return $this->resource;
    }

    public function issuer(): string
    {
        return $this->resource;
    }

    public function authorizeUrl(): string
    {
        return $this->restUrl(self::ROUTE_AUTHORIZE);
    }

    public function tokenUrl(): string
    {
        return $this->restUrl(self::ROUTE_TOKEN);
    }

    public function registerUrl(): string
    {
        return $this->restUrl(self::ROUTE_REGISTER);
    }

    public function revokeUrl(): string
    {
        return $this->restUrl(self::ROUTE_REVOKE);
    }

    /**
     * Where the consent screen lives: admin-post.php, because it needs the
     * WordPress login cookie (a REST route would need a nonce the browser does
     * not have after the login round-trip) and works without rewrite rules.
     *
     * @param array<string, string> $params
     */
    public function consentUrl(array $params = []): string
    {
        $url = admin_url('admin-post.php');
        $args = ['action' => $this->consentAction()] + $params;

        return $url . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
    }

    /** `admin_post_<this>` hook name of the consent screen, e.g. `bitform_mcp_authorize`. */
    public function consentAction(): string
    {
        return self::consentActionFor($this->context->slug());
    }

    /** The consent action for a plugin slug; usable before rest_url() works (plugins_loaded). */
    public static function consentActionFor(string $slug): string
    {
        return Contract::consentAction($slug);
    }

    /** The URL the WWW-Authenticate challenge points at. */
    public function protectedResourceMetadataUrl(): string
    {
        return $this->wellKnownUrl(self::PROTECTED_RESOURCE);
    }

    public function authorizationServerMetadataUrl(): string
    {
        return $this->wellKnownUrl(self::AUTHORIZATION_SERVER);
    }

    /** Path of the resource below the site root, e.g. `wp-json/bitform/v1/mcp`. */
    public function resourcePath(): string
    {
        $resourcePath = trim((string) parse_url($this->resource, PHP_URL_PATH), '/');
        $homePath = trim((string) parse_url(home_url(), PHP_URL_PATH), '/');

        if ('' !== $homePath && str_starts_with($resourcePath, $homePath . '/')) {
            $resourcePath = substr($resourcePath, \strlen($homePath) + 1);
        }

        return $resourcePath;
    }

    /**
     * The well-known document a request path asks for, or null when it is not one
     * of ours. Exact match only: never shadow another plugin's discovery URLs.
     */
    public function documentFor(string $requestPath): ?string
    {
        $path = trim($requestPath, '/');
        $homePath = trim((string) parse_url(home_url(), PHP_URL_PATH), '/');

        if ('' !== $homePath && str_starts_with($path, $homePath . '/')) {
            $path = substr($path, \strlen($homePath) + 1);
        }

        foreach ([self::PROTECTED_RESOURCE, self::AUTHORIZATION_SERVER] as $document) {
            if ($path === '.well-known/' . $document . '/' . $this->resourcePath()) {
                return $document;
            }
        }

        return null;
    }

    /** @return string[] */
    public function scopesSupported(): array
    {
        return array_merge(Scopes::levels(), [self::OFFLINE_ACCESS]);
    }

    /**
     * RFC 9728 protected-resource metadata.
     *
     * @return array<string, mixed>
     */
    public function protectedResourceMetadata(): array
    {
        return [
            'resource'                 => $this->resource(),
            'resource_name'            => $this->context->name(),
            'authorization_servers'    => [$this->issuer()],
            'scopes_supported'         => $this->scopesSupported(),
            'bearer_methods_supported' => ['header'],
        ];
    }

    /**
     * RFC 8414 authorization-server metadata.
     *
     * @return array<string, mixed>
     */
    public function authorizationServerMetadata(): array
    {
        return [
            'issuer'                                         => $this->issuer(),
            'authorization_endpoint'                         => $this->authorizeUrl(),
            'token_endpoint'                                 => $this->tokenUrl(),
            'registration_endpoint'                          => $this->registerUrl(),
            'revocation_endpoint'                            => $this->revokeUrl(),
            'scopes_supported'                               => $this->scopesSupported(),
            'response_types_supported'                       => ['code'],
            'response_modes_supported'                       => ['query'],
            'grant_types_supported'                          => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'               => ['S256'],
            'token_endpoint_auth_methods_supported'          => ['none'],
            'revocation_endpoint_auth_methods_supported'     => ['none'],
            'client_id_metadata_document_supported'          => true,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /**
     * Discovery needs pretty permalinks: with the plain structure WordPress never
     * routes `/.well-known/*` to PHP, so clients cannot find the server.
     */
    public static function prettyPermalinks(): bool
    {
        return '' !== (string) get_option('permalink_structure');
    }

    private function wellKnownUrl(string $document): string
    {
        return home_url('/.well-known/' . $document . '/' . $this->resourcePath());
    }

    private function restUrl(string $route): string
    {
        return rest_url($this->context->restNamespace() . $route);
    }
}
