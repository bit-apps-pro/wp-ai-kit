<?php

namespace BitApps\AiKit\Mcp\OAuth;

/**
 * Authorization codes: single use, 60 seconds, kept in a transient keyed by the
 * code's SHA-256 so a leaked options row cannot be replayed.
 */
final class AuthorizationCodes
{
    public const TTL = 60;

    public function __construct(private string $transientPrefix) {}

    /**
     * @param array<string, mixed> $grant what the code stands for (client, redirect, PKCE challenge, scope, resource, user)
     */
    public function issue(array $grant): string
    {
        $code = bin2hex(random_bytes(32));
        set_transient($this->key($code), $grant, self::TTL);

        return $code;
    }

    /**
     * Read and delete in one step, so a replay finds nothing even when the
     * first exchange fails verification.
     *
     * @return null|array<string, mixed>
     */
    public function consume(string $code): ?array
    {
        if ('' === $code || \strlen($code) > 256) {
            return null;
        }

        $key = $this->key($code);
        $grant = get_transient($key);

        // The delete decides who wins when two token requests race for one code.
        return \is_array($grant) && delete_transient($key) ? $grant : null;
    }

    /** PKCE S256: BASE64URL(SHA256(verifier)) equals the stored challenge (RFC 7636 §4.6). */
    public static function verifierMatches(string $verifier, string $challenge): bool
    {
        // RFC 7636 §4.1: 43–128 characters from the unreserved set.
        if ('' === $challenge || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)) {
            return false;
        }

        return hash_equals($challenge, self::challengeFor($verifier));
    }

    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function key(string $code): string
    {
        return $this->transientPrefix . 'oauth_code_' . hash('sha256', $code);
    }
}
