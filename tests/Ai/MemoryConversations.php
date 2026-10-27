<?php

namespace BitApps\AiKit\Tests\Ai;

/** An in-memory conversation repository that keeps every conversation, for tests. */
final class MemoryConversations implements \BitApps\AiKit\Ai\ConversationRepositoryInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    private int $next = 0;

    public function find(int $userId, string $id): ?array
    {
        $row = $this->rows[$id] ?? null;

        return null !== $row && $row['user'] === $userId ? $row + ['id' => $id] : null;
    }

    public function save(int $userId, ?string $id, string $title, array $messages, ?array $pending, array $context): string
    {
        $id = $id ?? (string) ++$this->next;
        $this->rows[$id] = ['user' => $userId, 'title' => $title, 'messages' => $messages, 'pending' => $pending, 'context' => $context, 'created' => $this->rows[$id]['created'] ?? time(), 'updated' => time()];

        return $id;
    }

    public function list(int $userId, string $search = '', int $limit = 20, int $offset = 0): array
    {
        $items = [];
        foreach (array_reverse($this->rows, true) as $id => $row) {
            if ($row['user'] === $userId && ('' === $search || false !== mb_stripos($row['title'], $search))) {
                $items[] = ['id' => (string) $id, 'title' => $row['title'], 'context' => $row['context'], 'messages' => \count($row['messages']), 'created' => $row['created'], 'updated' => $row['updated']];
            }
        }

        return ['items' => \array_slice($items, $offset, $limit), 'total' => \count($items)];
    }

    public function delete(int $userId, string $id): void
    {
        if (null !== $this->find($userId, $id)) {
            unset($this->rows[$id]);
        }
    }

    public function deleteAll(int $userId): void
    {
        $this->rows = array_filter($this->rows, static fn ($row) => $row['user'] !== $userId);
    }

    public function purgeOlderThan(int $seconds): int
    {
        return 0;
    }
}
