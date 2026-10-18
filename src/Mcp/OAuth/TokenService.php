<?php

namespace BitApps\AiKit\Mcp\OAuth;

/**
 * Access and refresh tokens. Only SHA-256 digests are stored; the raw tokens are
 * returned once. A grant keeps a stable `id` across refresh rotations, which is
 * what the connections screen lists and revokes.
 *
 * Refresh tokens rotate on every use (OAuth 2.1 §4.3.1 for public clients). The
 * last few rotated-away refresh digests stay on the grant: presenting one again
 * means a copy of the token is in someone else's hands, so the whole grant is
 * revoked (RFC 9700 §4.14.2 refresh-token reuse detection). A grant cannot be
 * refreshed past MAX_LIFETIME after it was approved.
 *
 * The grants live in one stored array, read and written whole. Two requests that
 * overlap can each write back what they read, so a lost write must never bring a
 * revoked or rotated-away token back: every revocation and rotation also leaves a
 * tombstone (a transient per digest) that validation checks, and nothing writes
 * the array for bookkeeping ("last used" lives in its own transient).
 */
final class TokenService
{
    public const ACCESS_TTL = 3600;

    public const REFRESH_TTL = 2592000;

    /** Longest a grant lives from approval, however often it is refreshed. */
    public const MAX_LIFETIME = 7776000;

    /** How stale "last used" may get before it is recorded again. */
    private const LAST_USED_RESOLUTION = 300;

    private const KEEP_PREVIOUS_REFRESH = 5;

    public function __construct(private OAuthStorageInterface $storage, private string $transientPrefix = '') {}

    /**
     * Create a grant and return the RFC 6749 token response.
     *
     * @param array{client_id: string, client_name?: string, client_kind?: string, client_host?: string, scope: string, user_id: int, resource: string, personal_data?: bool} $grant
     *
     * @return array<string, mixed>
     */
    public function mint(array $grant, ?string $id = null, array $previousRefreshHashes = [], ?int $created = null): array
    {
        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $now = time();
        $created ??= $now;

        $grants = $this->storage->grants();
        $grants[self::hash($access)] = [
            'id'                      => $id ?? bin2hex(random_bytes(8)),
            'refresh_hash'            => self::hash($refresh),
            'previous_refresh_hashes' => \array_slice($previousRefreshHashes, -self::KEEP_PREVIOUS_REFRESH),
            'client_id'               => (string) $grant['client_id'],
            'client_name'             => (string) ($grant['client_name'] ?? ''),
            'client_kind'             => (string) ($grant['client_kind'] ?? ''),
            'client_host'             => (string) ($grant['client_host'] ?? ''),
            'scope'                   => (string) $grant['scope'],
            'user_id'                 => (int) $grant['user_id'],
            'resource'                => (string) $grant['resource'],
            'personal_data'           => !empty($grant['personal_data']),
            'access_expires'          => $now + self::ACCESS_TTL,
            'refresh_expires'         => min($now + self::REFRESH_TTL, $created + self::MAX_LIFETIME),
            'created'                 => $created,
            'last_used'               => null,
        ];
        $this->storage->saveGrants(self::live($grants));

        return [
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope'         => (string) $grant['scope'],
        ];
    }

    /**
     * The grant behind a presented access token, if live, not revoked and minted
     * for this resource (audience binding, RFC 8707). Adds `access_hash`.
     *
     * @return null|array<string, mixed>
     */
    public function validateAccess(string $token, string $resource): ?array
    {
        if ('' === $token) {
            return null;
        }

        $hash = self::hash($token);
        $grant = $this->storage->grants()[$hash] ?? null;

        if (!\is_array($grant) || (int) ($grant['access_expires'] ?? 0) < time() || $this->isDead($hash)) {
            return null;
        }
        if (!hash_equals(untrailingslashit((string) ($grant['resource'] ?? '')), untrailingslashit($resource))) {
            return null;
        }

        $grant['access_hash'] = $hash;

        return $grant;
    }

    /**
     * Rotate a refresh token. The presented token is always burned first, so a
     * replay cannot mint a second pair; a token seen before revokes its grant.
     *
     * @return null|array<string, mixed> the new token response
     */
    public function rotate(string $refreshToken, string $clientId, string $resource = ''): ?array
    {
        if ('' === $refreshToken) {
            return null;
        }

        $hash = self::hash($refreshToken);
        $grants = $this->storage->grants();
        $found = null;
        $accessHash = '';

        foreach ($grants as $candidateHash => $grant) {
            $current = hash_equals((string) ($grant['refresh_hash'] ?? ''), $hash);
            $rotatedAway = \in_array($hash, (array) ($grant['previous_refresh_hashes'] ?? []), true);

            // Seen before (or restored by a racing write after it was used): someone else holds a copy.
            if ($rotatedAway || ($current && $this->isDead($hash))) {
                $this->revoke(self::idOf($grant, (string) $candidateHash));

                return null;
            }

            if ($current) {
                $found = $grant;
                $accessHash = (string) $candidateHash;
                unset($grants[$candidateHash]);

                break;
            }
        }

        if (null === $found) {
            return null;
        }

        $this->bury($accessHash, $hash);
        $this->storage->saveGrants($grants);

        if ('' !== $clientId && !hash_equals((string) $found['client_id'], $clientId)) {
            return null;
        }
        if ((int) ($found['refresh_expires'] ?? 0) < time()) {
            return null;
        }
        if ('' !== $resource && untrailingslashit($resource) !== untrailingslashit((string) $found['resource'])) {
            return null;
        }

        $previous = (array) ($found['previous_refresh_hashes'] ?? []);
        $previous[] = $hash;

        return $this->mint($found, self::idOf($found, $accessHash), $previous, (int) ($found['created'] ?? time()));
    }

    /**
     * Record that a grant was used, at most every few minutes. Kept outside the
     * grants array so a busy client never rewrites it.
     *
     * @param array<string, mixed> $grant one returned by validateAccess()
     */
    public function touch(array $grant): void
    {
        $key = $this->seenKey(self::idOf($grant, (string) ($grant['access_hash'] ?? '')));
        $seen = (int) get_transient($key);

        if (time() - $seen >= self::LAST_USED_RESOLUTION) {
            set_transient($key, time(), self::REFRESH_TTL);
        }
    }

    /**
     * Connections that can still authenticate (live access, or a refresh token
     * that can rotate), newest first, keyed by grant id. Read-only; never
     * includes digests.
     *
     * @return array<string, array<string, mixed>>
     */
    public function connections(?int $userId = null): array
    {
        $out = [];
        foreach (self::live($this->storage->grants()) as $accessHash => $grant) {
            if ((null !== $userId && (int) $grant['user_id'] !== $userId) || $this->isDead((string) $accessHash)) {
                continue;
            }

            $id = self::idOf($grant, (string) $accessHash);
            $seen = (int) get_transient($this->seenKey($id));
            $stored = null !== ($grant['last_used'] ?? null) ? (int) $grant['last_used'] : 0;

            $out[$id] = [
                'id'            => $id,
                'client_id'     => (string) $grant['client_id'],
                'client_name'   => (string) ($grant['client_name'] ?? ''),
                'client_kind'   => (string) ($grant['client_kind'] ?? ''),
                'client_host'   => (string) ($grant['client_host'] ?? ''),
                'scope'         => (string) $grant['scope'],
                'user_id'       => (int) $grant['user_id'],
                'personal_data' => !empty($grant['personal_data']),
                'created'       => (int) ($grant['created'] ?? 0),
                'last_used'     => max($seen, $stored) > 0 ? max($seen, $stored) : null,
            ];
        }

        uasort($out, static fn (array $a, array $b) => $b['created'] <=> $a['created']);

        return $out;
    }

    /** Revoke one connection by grant id; a non-null $userId limits it to that user's own. */
    public function revoke(string $id, ?int $userId = null): bool
    {
        return '' !== $id && $this->removeWhere(static fn (array $g, string $accessHash) => self::idOf($g, $accessHash) === $id && (null === $userId || (int) $g['user_id'] === $userId)) > 0;
    }

    /** RFC 7009: revoke the grant an access or refresh token belongs to. */
    public function revokeToken(string $token): bool
    {
        if ('' === $token) {
            return false;
        }

        $hash = self::hash($token);

        return $this->removeWhere(static fn (array $g, string $accessHash) => hash_equals($accessHash, $hash) || hash_equals((string) ($g['refresh_hash'] ?? ''), $hash)) > 0;
    }

    public function revokeAllForUser(int $userId): int
    {
        return $this->removeWhere(static fn (array $g) => (int) ($g['user_id'] ?? 0) === $userId);
    }

    public function revokeAll(): int
    {
        return $this->removeWhere(static fn () => true);
    }

    private function removeWhere(callable $match): int
    {
        $grants = $this->storage->grants();
        $removed = 0;

        foreach ($grants as $accessHash => $grant) {
            if ($match($grant, (string) $accessHash)) {
                $this->bury((string) $accessHash, (string) ($grant['refresh_hash'] ?? ''));
                unset($grants[$accessHash]);
                ++$removed;
            }
        }

        if ($removed > 0) {
            $this->storage->saveGrants($grants);
        }

        return $removed;
    }

    /** Tombstones for digests that must never work again, whatever a racing write restores. */
    private function bury(string ...$hashes): void
    {
        foreach ($hashes as $hash) {
            if ('' !== $hash) {
                set_transient($this->transientPrefix . 'oauth_dead_' . substr($hash, 0, 40), 1, self::REFRESH_TTL);
            }
        }
    }

    private function isDead(string $hash): bool
    {
        return '' !== $hash && false !== get_transient($this->transientPrefix . 'oauth_dead_' . substr($hash, 0, 40));
    }

    private function seenKey(string $id): string
    {
        return $this->transientPrefix . 'oauth_seen_' . $id;
    }

    /**
     * @param array<string, array<string, mixed>> $grants
     *
     * @return array<string, array<string, mixed>>
     */
    private static function live(array $grants): array
    {
        $now = time();

        return array_filter($grants, static fn ($g) => \is_array($g) && (int) ($g['refresh_expires'] ?? 0) >= $now);
    }

    /**
     * A grant's stable id. Records written before ids existed (Bit CRM's rows)
     * get one derived from their access digest until their next rotation.
     *
     * @param array<string, mixed> $grant
     */
    private static function idOf(array $grant, string $accessHash): string
    {
        $id = (string) ($grant['id'] ?? '');

        return '' !== $id ? $id : substr($accessHash, 0, 16);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
