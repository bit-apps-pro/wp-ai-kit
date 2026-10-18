<?php

namespace BitApps\AiKit\Mcp\Auth;

use BitApps\AiKit\Contract;
use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Tools\Scopes;

/**
 * Long-lived keys for MCP clients that cannot run OAuth (CLI tools, automation).
 *
 * Each key belongs to one WordPress user and acts with that user's capabilities,
 * narrowed by its scopes. Only a SHA-256 hash is stored; the plaintext is shown
 * once. Keys can expire, be revoked, and be marked "unattended" (automation that
 * never pauses for confirmation). Stored in one non-autoloaded option per plugin.
 */
final class ApiKeyService implements BearerResolverInterface
{
    private const LAST_USED_THROTTLE = 300;

    private string $option;

    private string $keyPrefix;

    public function __construct(private PluginContext $context)
    {
        $this->option = $context->optionName('mcp_api_keys');
        $prefix = $context->service('api_key_prefix');
        $this->keyPrefix = \is_string($prefix) && '' !== $prefix ? $prefix : str_replace('-', '', Contract::token($context->slug())) . '_key_';
    }

    /**
     * Create a key; the plaintext is returned once and never stored.
     *
     * @param string[] $scopes
     *
     * @return array{key: string, record: array<string, mixed>}
     */
    public function create(int $userId, string $name, array $scopes = [Scopes::READ], ?int $expiresInDays = 30, bool $unattended = false): array
    {
        $plain = $this->keyPrefix . bin2hex(random_bytes(24));
        $now = time();

        $record = [
            'id'           => bin2hex(random_bytes(8)),
            'name'         => mb_substr(trim($name), 0, 100),
            'user_id'      => $userId,
            'hash'         => hash('sha256', $plain),
            'display'      => substr($plain, 0, \strlen($this->keyPrefix) + 6),
            'scopes'       => array_values(array_unique(array_filter($scopes, 'is_string'))),
            'unattended'   => $unattended,
            'created_at'   => $now,
            'expires_at'   => null !== $expiresInDays && $expiresInDays > 0 ? $now + $expiresInDays * DAY_IN_SECONDS : null,
            'last_used_at' => null,
            'revoked_at'   => null,
        ];

        $records = $this->records();
        $records[$record['id']] = $record;
        $this->save($records);

        return ['key' => $plain, 'record' => self::publicView($record)];
    }

    public function resolve(string $token): ?BearerGrant
    {
        if (!str_starts_with($token, $this->keyPrefix)) {
            return null;
        }

        $hash = hash('sha256', $token);
        $records = $this->records();

        foreach ($records as $id => $record) {
            if (!hash_equals((string) ($record['hash'] ?? ''), $hash)) {
                continue;
            }

            if (!empty($record['revoked_at']) || (!empty($record['expires_at']) && (int) $record['expires_at'] < time())) {
                return null;
            }

            // Recorded outside the key list: rewriting the list on every call could undo a revocation saved meanwhile.
            $seenKey = $this->option . '_seen_' . $id;
            if ((int) get_transient($seenKey) < time() - self::LAST_USED_THROTTLE) {
                set_transient($seenKey, time(), 90 * DAY_IN_SECONDS);
            }

            return new BearerGrant(
                (int) $record['user_id'],
                (array) $record['scopes'],
                'api-key:' . ($record['name'] ?: $id),
                'api_key',
                (bool) ($record['unattended'] ?? false)
            );
        }

        return null;
    }

    /** Revoke a key; with $userId, only if it is that user's own. */
    public function revoke(string $id, ?int $userId = null): bool
    {
        $records = $this->records();
        if (!isset($records[$id]) || (null !== $userId && (int) $records[$id]['user_id'] !== $userId)) {
            return false;
        }

        $records[$id]['revoked_at'] = time();
        $this->save($records);

        return true;
    }

    /** Revoke every key a user holds (account deleted or demoted). */
    public function revokeAllForUser(int $userId): int
    {
        $records = $this->records();
        $count = 0;
        foreach ($records as $id => $record) {
            if ((int) $record['user_id'] === $userId && empty($record['revoked_at'])) {
                $records[$id]['revoked_at'] = time();
                ++$count;
            }
        }
        if ($count > 0) {
            $this->save($records);
        }

        return $count;
    }

    /**
     * Keys without their hashes, newest first; optionally only one user's.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(?int $userId = null): array
    {
        $records = array_values(array_filter(
            $this->records(),
            static fn (array $record) => null === $userId || (int) $record['user_id'] === $userId
        ));

        usort($records, static fn ($a, $b) => (int) $b['created_at'] <=> (int) $a['created_at']);

        foreach ($records as &$record) {
            $seen = (int) get_transient($this->option . '_seen_' . $record['id']);
            $record['last_used_at'] = max($seen, (int) ($record['last_used_at'] ?? 0)) ?: null;
        }

        return array_map([self::class, 'publicView'], $records);
    }

    /** @return array<string, array<string, mixed>> */
    private function records(): array
    {
        $records = get_option($this->option, []);

        return \is_array($records) ? array_filter($records, 'is_array') : [];
    }

    /** @param array<string, array<string, mixed>> $records */
    private function save(array $records): void
    {
        if (!add_option($this->option, $records, '', 'no')) {
            update_option($this->option, $records, false);
        }
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private static function publicView(array $record): array
    {
        unset($record['hash']);
        $record['status'] = !empty($record['revoked_at']) ? 'revoked' : (!empty($record['expires_at']) && (int) $record['expires_at'] < time() ? 'expired' : 'active');

        return $record;
    }
}
