<?php

namespace BitApps\AiKit\Tools\Confirmation;

use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Security\Digest;
use BitApps\AiKit\Tools\ToolDefinition;

/**
 * Two-step execution for destructive tools.
 *
 * The first call returns a single-use token bound to the tool, the exact
 * arguments and the user; the client must show the action to the user and call
 * again with `confirmation_token`. A token cannot be replayed, used for other
 * arguments, by another user or after it expires.
 */
final class ConfirmationGate
{
    public const ARGUMENT = 'confirmation_token';

    private int $ttl;

    public function __construct(private PluginContext $context, int $ttl = 600)
    {
        $this->ttl = max(30, $ttl);
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /** @param array<string, mixed> $args */
    public function issue(ToolDefinition $tool, array $args, int $userId): string
    {
        $token = bin2hex(random_bytes(16));

        set_transient($this->key($token), [
            'tool'   => $tool->name(),
            'digest' => Digest::of($args),
            'user'   => $userId,
        ], $this->ttl);

        return $token;
    }

    /**
     * Valid only once, only for the same tool, arguments and user.
     *
     * @param array<string, mixed> $args
     */
    public function consume(string $token, ToolDefinition $tool, array $args, int $userId): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return false;
        }

        $key = $this->key($token);
        $record = get_transient($key);

        // Only the request whose delete succeeds may use the token: two parallel
        // "Allow" clicks must not run the action twice.
        return \is_array($record)
            && delete_transient($key)
            && ($record['tool'] ?? null) === $tool->name()
            && ($record['digest'] ?? null) === Digest::of($args)
            && (int) ($record['user'] ?? 0) === $userId;
    }

    private function key(string $token): string
    {
        return $this->context->optionName('ai_confirm_' . substr(hash('sha256', $token), 0, 32));
    }
}
