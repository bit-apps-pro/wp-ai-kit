<?php

namespace BitApps\AiKit\Mcp\OAuth;

use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Tools\Scopes;

/**
 * Validation of an /authorize request.
 *
 * Every error is shown on the consent page, never sent back to the redirect URI:
 * with open client registration anyone can register any redirect URI, so a
 * redirect before the user decided would make the site an open redirector.
 */
final class AuthorizeRequest
{
    public const PARAMS = ['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'scope', 'state', 'resource'];

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>|\WP_Error the validated request
     */
    public static function validate(array $params, ClientRegistry $clients, OAuthEndpoints $endpoints, PluginContext $context)
    {
        $client = $clients->find((string) ($params['client_id'] ?? ''));
        if (null === $client) {
            return new \WP_Error('invalid_client', 'This AI client is unknown. Remove the connection in the AI app and add it again.');
        }

        $redirectUri = (string) ($params['redirect_uri'] ?? '');
        if (!ClientRegistry::redirectUriRegistered($client, $redirectUri)) {
            return new \WP_Error('invalid_request', 'The redirect address does not match the one this AI client registered.');
        }

        if ('code' !== ($params['response_type'] ?? '')) {
            return new \WP_Error('unsupported_response_type', 'Only the authorization code flow is supported.');
        }

        $challenge = (string) ($params['code_challenge'] ?? '');
        if ('S256' !== ($params['code_challenge_method'] ?? '') || !preg_match('/^[A-Za-z0-9\-_]{43}$/', $challenge)) {
            return new \WP_Error('invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }

        $resource = (string) ($params['resource'] ?? '');
        if ('' !== $resource && untrailingslashit($resource) !== untrailingslashit($endpoints->resource())) {
            return new \WP_Error('invalid_target', 'The requested resource is not this MCP server.');
        }

        return [
            'client_id'      => (string) $client['client_id'],
            'client_name'    => (string) ($client['client_name'] ?? ''),
            'client_kind'    => (string) ($client['kind'] ?? ClientRegistry::KIND_DCR),
            'client_host'    => (string) ($client['host'] ?? ''),
            'redirect_uri'   => $redirectUri,
            'redirect_host'  => (string) parse_url($redirectUri, PHP_URL_HOST),
            'code_challenge' => $challenge,
            'requested'      => self::requestedLevel((string) ($params['scope'] ?? ''), $context),
            'state'          => (string) ($params['state'] ?? ''),
            'resource'       => $endpoints->resource(),
        ];
    }

    /**
     * The highest access level a scope string asks for, after the plugin's scope
     * map (Bit CRM's `mcp` → `write`). Nothing recognised means "whatever the
     * site allows", which the consent screen then caps.
     */
    public static function requestedLevel(string $scope, PluginContext $context): ?string
    {
        $levels = [];
        foreach (preg_split('/\s+/', trim($scope)) ?: [] as $part) {
            $canonical = $context->canonicalScope($part);
            if (\in_array($canonical, Scopes::levels(), true)) {
                $levels[] = $canonical;
            }
        }

        if ([] === $levels) {
            return null;
        }

        usort($levels, static fn (string $a, string $b) => array_search($a, Scopes::levels(), true) <=> array_search($b, Scopes::levels(), true));

        return end($levels);
    }

    /** The lower of two levels. */
    public static function lowerLevel(string $a, string $b): string
    {
        return Scopes::withinLevel($a, $b) ? $a : $b;
    }
}
