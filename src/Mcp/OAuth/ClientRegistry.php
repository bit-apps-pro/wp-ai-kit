<?php

namespace BitApps\AiKit\Mcp\OAuth;

use BitApps\AiKit\Security\RateLimiter;

/**
 * The OAuth clients an authorization server knows: registered through RFC 7591
 * dynamic registration (DCR), or identified by a Client ID Metadata Document
 * (CIMD) — a `client_id` that is an HTTPS URL serving the client's metadata,
 * which the MCP authorization spec now prefers over DCR.
 *
 * Every client is public: no secret is issued; PKCE proves that the token
 * request comes from whoever started the flow. Redirect URIs are exact-matched at
 * /authorize, except that a loopback redirect may differ in port (RFC 8252 §7.3).
 */
final class ClientRegistry
{
    public const KIND_DCR = 'dcr';

    public const KIND_CIMD = 'cimd';

    public const MAX_REDIRECT_URIS = 10;

    /**
     * Registration is unauthenticated by design, so the stored set is capped:
     * past this, registering evicts the oldest clients without a grant.
     */
    public const MAX_CLIENTS = 50;

    private const CIMD_CACHE_TTL = 86400;

    private const CIMD_MAX_BYTES = 65536;

    private const CIMD_FETCHES_PER_MINUTE = 30;

    private const CIMD_MAX_REDIRECT_BYTES = 2048;

    /** Schemes a browser may treat as code or local file access. */
    private const BLOCKED_SCHEMES = ['about', 'blob', 'data', 'file', 'filesystem', 'jar', 'javascript', 'view-source', 'vbscript'];

    public function __construct(
        private OAuthStorageInterface $storage,
        private string $clientIdPrefix,
        private string $cachePrefix,
        private RateLimiter $limiter
    ) {}

    /**
     * RFC 7591 registration.
     *
     * @param array<string, mixed> $body
     *
     * @return null|array<string, mixed> the registration response, or null without a valid redirect URI
     */
    public function register(array $body): ?array
    {
        $redirectUris = self::sanitizeRedirectUris($body['redirect_uris'] ?? []);
        if ([] === $redirectUris) {
            return null;
        }

        $name = isset($body['client_name']) && \is_string($body['client_name']) ? sanitize_text_field($body['client_name']) : '';
        $name = '' !== $name ? mb_substr($name, 0, 100) : 'MCP client';
        $clientId = $this->clientIdPrefix . bin2hex(random_bytes(16));

        $clients = $this->storage->clients();
        $clients[$clientId] = [
            'kind'          => self::KIND_DCR,
            'client_name'   => $name,
            'redirect_uris' => $redirectUris,
            'created'       => time(),
        ];
        $this->storage->saveClients($this->pruned($clients, $clientId));

        return [
            'client_id'                  => $clientId,
            'client_id_issued_at'        => time(),
            'client_name'                => $name,
            'redirect_uris'              => $redirectUris,
            'token_endpoint_auth_method' => 'none',
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
        ];
    }

    /**
     * A known client by id, with `client_id`, `kind` and, for CIMD clients, the
     * verified `host`. CIMD documents are fetched on first use and cached.
     *
     * @return null|array<string, mixed>
     */
    public function find(string $clientId): ?array
    {
        if ('' === $clientId) {
            return null;
        }

        if (self::isMetadataDocumentId($clientId)) {
            return $this->resolveMetadataDocument($clientId);
        }

        $client = $this->storage->clients()[$clientId] ?? null;
        if (!\is_array($client)) {
            return null;
        }

        $client['client_id'] = $clientId;
        $client['kind'] = (string) ($client['kind'] ?? self::KIND_DCR);

        return $client;
    }

    /** @param array<string, mixed> $client */
    public static function redirectUriRegistered(array $client, string $redirectUri): bool
    {
        if ('' === $redirectUri) {
            return false;
        }

        foreach ((array) ($client['redirect_uris'] ?? []) as $candidate) {
            $candidate = (string) $candidate;
            if (hash_equals($candidate, $redirectUri)) {
                return true;
            }
            if (self::isLoopback($candidate) && self::isLoopback($redirectUri) && self::withoutPort($candidate) === self::withoutPort($redirectUri)) {
                return true;
            }
        }

        return false;
    }

    /** A CIMD client id: an https URL with a path (draft-ietf-oauth-client-id-metadata-document). */
    public static function isMetadataDocumentId(string $clientId): bool
    {
        if (!str_starts_with($clientId, 'https://') || \strlen($clientId) > 2000) {
            return false;
        }

        $parts = parse_url($clientId);

        return \is_array($parts) && !empty($parts['host']) && !empty($parts['path']) && '/' !== $parts['path']
            && !isset($parts['fragment']) && !isset($parts['user']) && !isset($parts['pass']);
    }

    /**
     * Fetch, validate and cache a client's metadata document. The fetch goes
     * through wp_safe_remote_get (no private addresses, no redirects, size and
     * time capped) and a site-wide budget, since anyone can make the site fetch
     * any https URL by starting an authorization.
     *
     * @return null|array<string, mixed>
     */
    private function resolveMetadataDocument(string $clientId): ?array
    {
        $cacheKey = $this->cachePrefix . 'cimd_' . md5($clientId);
        $cached = get_transient($cacheKey);
        if (\is_array($cached)) {
            return $cached;
        }

        if (!$this->limiter->attempt('oauth_cimd_fetch', self::CIMD_FETCHES_PER_MINUTE, 60)['allowed']) {
            return null;
        }

        $response = wp_safe_remote_get($clientId, [
            'timeout'             => 5,
            'redirection'         => 0,
            'limit_response_size' => self::CIMD_MAX_BYTES,
            'headers'             => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }

        $document = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!\is_array($document) || ($document['client_id'] ?? null) !== $clientId) {
            return null;
        }

        // A public client: a document claiming a secret-based method is not one we can serve.
        $method = $document['token_endpoint_auth_method'] ?? 'none';
        if ('none' !== $method || isset($document['client_secret'])) {
            return null;
        }

        $redirectUris = self::sanitizeRedirectUris($document['redirect_uris'] ?? []);
        // Cached for a day per client id; keep each cached entry small.
        if ([] === $redirectUris || \strlen(implode('', $redirectUris)) > self::CIMD_MAX_REDIRECT_BYTES) {
            return null;
        }

        $host = strtolower((string) parse_url($clientId, PHP_URL_HOST));
        $name = isset($document['client_name']) && \is_string($document['client_name']) ? sanitize_text_field($document['client_name']) : '';

        $client = [
            'client_id'     => $clientId,
            'kind'          => self::KIND_CIMD,
            'client_name'   => '' !== $name ? mb_substr($name, 0, 100) : $host,
            'redirect_uris' => $redirectUris,
            'host'          => $host,
            'client_uri'    => isset($document['client_uri']) && \is_string($document['client_uri']) ? esc_url_raw($document['client_uri']) : '',
            'created'       => time(),
        ];

        set_transient($cacheKey, $client, self::CIMD_CACHE_TTL);

        return $client;
    }

    /**
     * Evict the oldest grant-less clients past MAX_CLIENTS. The client registered
     * right now is exempt, and so is every client holding a grant: minting a
     * grant needs a signed-in user approving the consent screen, so hammering
     * /register alone cannot push out live connections.
     *
     * @param array<string, array<string, mixed>> $clients
     *
     * @return array<string, array<string, mixed>>
     */
    private function pruned(array $clients, string $keep): array
    {
        if (\count($clients) <= self::MAX_CLIENTS) {
            return $clients;
        }

        $inUse = [$keep => true];
        foreach ($this->storage->grants() as $grant) {
            $inUse[(string) ($grant['client_id'] ?? '')] = true;
        }

        uasort($clients, static fn (array $a, array $b) => ($a['created'] ?? 0) <=> ($b['created'] ?? 0));
        foreach (array_keys($clients) as $clientId) {
            if (\count($clients) <= self::MAX_CLIENTS) {
                break;
            }
            if (!isset($inUse[$clientId])) {
                unset($clients[$clientId]);
            }
        }

        return $clients;
    }

    /**
     * @param mixed $value
     *
     * @return string[]
     */
    private static function sanitizeRedirectUris($value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $uris = [];
        foreach ($value as $uri) {
            if (\is_string($uri) && self::validRedirectUri(trim($uri))) {
                $uris[] = trim($uri);
            }
        }

        return \array_slice(array_values(array_unique($uris)), 0, self::MAX_REDIRECT_URIS);
    }

    /**
     * https (web clients), http on loopback (CLIs, RFC 8252 §7.3), or a private-use
     * scheme such as `cursor://` (desktop apps, RFC 8252 §7.1). Never a fragment,
     * plain http to a remote host, or a scheme a browser would execute.
     */
    private static function validRedirectUri(string $uri): bool
    {
        if ('' === $uri || \strlen($uri) > 2000) {
            return false;
        }

        $parts = parse_url($uri);
        if (!\is_array($parts) || empty($parts['scheme']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ('https' === $scheme) {
            return !empty($parts['host']);
        }
        if ('http' === $scheme) {
            return self::isLoopback($uri);
        }
        if (\in_array($scheme, self::BLOCKED_SCHEMES, true)) {
            return false;
        }

        return (bool) preg_match('#^[a-z][a-z0-9+.\-]*:/*[^\s/?\#]#i', $uri);
    }

    private static function isLoopback(string $uri): bool
    {
        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));

        return \in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    private static function withoutPort(string $uri): string
    {
        $parts = parse_url($uri);
        if (!\is_array($parts)) {
            return $uri;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) . '://' . strtolower((string) ($parts['host'] ?? ''))
            . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
