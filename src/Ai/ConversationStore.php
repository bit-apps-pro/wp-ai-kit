<?php

namespace BitApps\AiKit\Ai;

/**
 * Each user's assistant conversations, server-side, and which one is open.
 *
 * The browser only ever sends the new message: tool results come from here, so
 * a page script cannot forge what a tool returned or replay a confirmation.
 * "New chat" starts another conversation; earlier ones stay in the history
 * (when the plugin's repository keeps more than one) until they age out.
 * Old turns are dropped from the front, always at a user message so tool calls
 * stay paired with results.
 */
final class ConversationStore
{
    public const MAX_ENTRIES = 60;

    /** Stored transcript size; the oldest turns go first. Every model call re-sends it. */
    public const MAX_BYTES = 200000;

    /** Default age after which an untouched conversation is dropped (it may hold entry data). */
    public const MAX_AGE = 30 * DAY_IN_SECONDS;

    public const TITLE_CHARS = 80;

    /** Stored as the open conversation after "New chat": none, not the default one. */
    private const NONE = 'new';

    /**
     * @param string      $activeKey     user meta key holding the open conversation's id
     * @param null|string $defaultActive the id to open when none was chosen (the user-meta store's only one)
     */
    public function __construct(
        private ConversationRepositoryInterface $repository,
        private string $activeKey,
        private ?string $defaultActive = null,
        private int $maxAge = self::MAX_AGE
    ) {}

    /**
     * The open conversation, or an empty one.
     *
     * @return array{id: ?string, title: string, messages: array<int, array<string, mixed>>, pending: ?array<string, mixed>, context: array<string, mixed>, updated: int}
     */
    public function load(int $userId): array
    {
        $empty = ['id' => null, 'title' => '', 'messages' => [], 'pending' => null, 'context' => [], 'updated' => 0];
        $id = $this->activeId($userId);
        if (null === $id) {
            return $empty;
        }

        $conversation = $this->repository->find($userId, $id);
        if (null === $conversation) {
            return $empty;
        }
        if ($conversation['updated'] < time() - $this->maxAge) {
            $this->repository->delete($userId, $id);

            return $empty;
        }

        return [
            'id'       => $conversation['id'],
            'title'    => $conversation['title'],
            'messages' => $conversation['messages'],
            'pending'  => $conversation['pending'],
            'context'  => $conversation['context'],
            'updated'  => $conversation['updated'],
        ];
    }

    /**
     * Store the open conversation (creating it on its first message).
     *
     * @param array<int, array<string, mixed>> $messages
     * @param null|array<string, mixed>        $pending
     * @param array<string, mixed>             $context
     */
    public function save(int $userId, array $messages, ?array $pending, array $context = []): void
    {
        if ($userId <= 0) {
            return;
        }

        $current = $this->load($userId);
        $title = '' !== $current['title'] ? $current['title'] : self::titleOf($messages);
        $id = $this->repository->save($userId, $current['id'], $title, self::trimmed($messages), $pending, [] !== $context ? $context : $current['context']);
        update_user_meta($userId, $this->activeKey, $id);
    }

    /** Start a new conversation; the current one stays in the history. */
    public function clear(int $userId): void
    {
        if ($userId > 0) {
            update_user_meta($userId, $this->activeKey, self::NONE);
        }
    }

    /** Make an earlier conversation the open one. */
    public function open(int $userId, string $id): bool
    {
        if ($userId <= 0 || null === $this->repository->find($userId, $id)) {
            return false;
        }
        update_user_meta($userId, $this->activeKey, $id);

        return true;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function list(int $userId, string $search = '', int $limit = 20, int $offset = 0): array
    {
        return $this->repository->list($userId, $search, max(1, min(100, $limit)), max(0, $offset));
    }

    public function delete(int $userId, string $id): void
    {
        $this->repository->delete($userId, $id);
        if ($this->activeId($userId) === $id) {
            $this->clear($userId);
        }
    }

    public function deleteAll(int $userId): void
    {
        $this->repository->deleteAll($userId);
        $this->clear($userId);
    }

    public function repository(): ConversationRepositoryInterface
    {
        return $this->repository;
    }

    public function activeKey(): string
    {
        return $this->activeKey;
    }

    private function activeId(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        $stored = get_user_meta($userId, $this->activeKey, true);
        if (self::NONE === $stored) {
            return null;
        }

        // Nothing chosen yet: the default (the user-meta store's only conversation).
        return \is_string($stored) && '' !== $stored ? $stored : $this->defaultActive;
    }

    /**
     * The first message, shortened: what the history list shows.
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private static function titleOf(array $messages): string
    {
        foreach ($messages as $message) {
            if ('user' === ($message['role'] ?? '') && \is_string($message['content'] ?? null)) {
                $text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($message['content'])));

                return mb_strlen($text) > self::TITLE_CHARS ? rtrim(mb_substr($text, 0, self::TITLE_CHARS - 1)) . '…' : $text;
            }
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private static function trimmed(array $messages): array
    {
        $messages = array_values($messages);
        while (\count($messages) > self::MAX_ENTRIES || \strlen((string) wp_json_encode($messages)) > self::MAX_BYTES) {
            // Drop the oldest exchange, never the latest one (it may wait for a confirmation).
            $next = null;
            foreach ($messages as $index => $message) {
                if ($index > 0 && 'user' === ($message['role'] ?? '')) {
                    $next = $index;

                    break;
                }
            }
            if (null === $next) {
                break;
            }
            $messages = \array_slice($messages, $next);
        }

        return $messages;
    }
}
