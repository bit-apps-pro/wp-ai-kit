<?php

namespace BitApps\AiKit\Ai;

/**
 * The fallback store: one conversation per user, in user meta (what the kit did
 * before conversation history). Plugins that want history supply a table-backed
 * repository; user meta is loaded whole on every request, so it holds one.
 */
final class UserMetaConversationRepository implements ConversationRepositoryInterface
{
    private const ID = 'current';

    public function __construct(private string $metaKey) {}

    public function find(int $userId, string $id): ?array
    {
        $stored = $userId > 0 && self::ID === $id ? get_user_meta($userId, $this->metaKey, true) : null;
        if (!\is_array($stored) || [] === $stored) {
            return null;
        }

        return [
            'id'       => self::ID,
            'title'    => (string) ($stored['title'] ?? ''),
            'messages' => \is_array($stored['messages'] ?? null) ? array_values($stored['messages']) : [],
            'pending'  => \is_array($stored['pending'] ?? null) ? $stored['pending'] : null,
            'context'  => \is_array($stored['context'] ?? null) ? $stored['context'] : [],
            'created'  => (int) ($stored['created'] ?? $stored['updated'] ?? 0),
            'updated'  => (int) ($stored['updated'] ?? 0),
        ];
    }

    public function save(int $userId, ?string $id, string $title, array $messages, ?array $pending, array $context): string
    {
        if ($userId > 0) {
            $existing = $this->find($userId, self::ID);
            update_user_meta($userId, $this->metaKey, [
                'title'    => $title,
                'messages' => $messages,
                'pending'  => $pending,
                'context'  => $context,
                'created'  => null !== $existing && null !== $id ? $existing['created'] : time(),
                'updated'  => time(),
            ]);
        }

        return self::ID;
    }

    public function list(int $userId, string $search = '', int $limit = 20, int $offset = 0): array
    {
        $conversation = $this->find($userId, self::ID);
        if (null === $conversation || ('' !== $search && false === mb_stripos($conversation['title'], $search)) || $offset > 0) {
            return ['items' => [], 'total' => null === $conversation ? 0 : 1];
        }

        return ['items' => [[
            'id'       => self::ID,
            'title'    => $conversation['title'],
            'context'  => $conversation['context'],
            'messages' => \count($conversation['messages']),
            'created'  => $conversation['created'],
            'updated'  => $conversation['updated'],
        ]], 'total' => 1];
    }

    public function delete(int $userId, string $id): void
    {
        if ($userId > 0 && self::ID === $id) {
            delete_user_meta($userId, $this->metaKey);
        }
    }

    public function deleteAll(int $userId): void
    {
        $this->delete($userId, self::ID);
    }

    public function purgeOlderThan(int $seconds): int
    {
        // Each user's conversation is checked as it is read (ConversationStore).
        return 0;
    }
}
