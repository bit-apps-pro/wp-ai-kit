<?php

namespace BitApps\AiKit\Storage;

use BitApps\AiKit\Ai\ConversationQueryInterface;
use BitApps\AiKit\Ai\ConversationRepositoryInterface;
use BitApps\AiKit\Ai\UserMetaConversationRepository;
use BitApps\AiKit\Storage\Model\Conversation;

/**
 * Chat history in `{prefix}aikit_conversations` through the Conversation model,
 * every query scoped to its user.
 *
 * Until the table exists the user-meta store stands in. Its id (`current`) is not
 * numeric, which is how a conversation started there is found again and moved
 * into the table on its next save.
 */
final class TableConversationRepository implements ConversationRepositoryInterface, ConversationQueryInterface
{
    public function __construct(private Tables $tables, private UserMetaConversationRepository $fallback) {}

    public function find(int $userId, string $id): ?array
    {
        if (!self::isRowId($id) || !$this->ready()) {
            return $this->fallback->find($userId, $id);
        }
        if ($userId <= 0) {
            return null;
        }

        $row = $this->model()->where('id', (int) $id)->where('user_id', $userId)->first();
        if (null === $row) {
            return null;
        }

        return [
            'id'       => (string) $row->getAttribute('id'),
            'title'    => (string) $row->getAttribute('title'),
            'messages' => array_values(Conversation::decode($row->getAttribute('messages')) ?? []),
            'pending'  => Conversation::decode($row->getAttribute('pending')),
            'context'  => Conversation::decode($row->getAttribute('context')) ?? [],
            'created'  => Conversation::timestamp($row->getAttribute('created_at')),
            'updated'  => Conversation::timestamp($row->getAttribute('updated_at')),
        ];
    }

    public function save(int $userId, ?string $id, string $title, array $messages, ?array $pending, array $context): string
    {
        if (!$this->ready() || $userId <= 0) {
            return $this->fallback->save($userId, $id, $title, $messages, $pending, $context);
        }

        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'title'         => mb_substr($title, 0, 191),
            'context'       => [] === $context ? null : (string) wp_json_encode($context),
            'messages'      => (string) wp_json_encode(array_values($messages)),
            'pending'       => null === $pending ? null : (string) wp_json_encode($pending),
            'message_count' => \count($messages),
            'updated_at'    => $now,
        ];

        $rowId = null !== $id && self::isRowId($id) ? (int) $id : 0;
        if ($rowId > 0 && $this->model()->where('id', $rowId)->where('user_id', $userId)->count() > 0) {
            $this->model()->where('id', $rowId)->where('user_id', $userId)->update($values);
            if (!Conversation::failed()) {
                return (string) $rowId;
            }
        }

        $this->model()->insert([['user_id' => $userId, 'created_at' => $now] + $values]);
        $inserted = Conversation::failed() ? 0 : Conversation::insertedId();
        if ($inserted <= 0) {
            // Never lose the turn: keep it where the kit keeps it without a table.
            return $this->fallback->save($userId, $id, $title, $messages, $pending, $context);
        }
        if (null !== $id && !self::isRowId($id)) {
            // A conversation from before the table: it lives on as a row now.
            $this->fallback->delete($userId, $id);
        }

        return (string) $inserted;
    }

    public function list(int $userId, string $search = '', int $limit = 20, int $offset = 0): array
    {
        if (!$this->ready()) {
            return $this->fallback->list($userId, $search, $limit, $offset);
        }

        return $this->page($userId, $search, null, $limit, $offset);
    }

    public function query(int $userId, array $args): array
    {
        $search = (string) ($args['search'] ?? '');
        $limit = (int) ($args['limit'] ?? 20);
        $offset = (int) ($args['offset'] ?? 0);
        $archived = !empty($args['archived']);

        if (!$this->ready()) {
            $result = $this->fallback->list($userId, $search, $limit, $offset);
            $result['items'] = array_values(array_filter($result['items'], static fn ($item) => $archived === !empty($item['context']['archived'])));
            $result['total'] = \count($result['items']);

            return $result;
        }

        return $this->page($userId, $search, $archived, $limit, $offset);
    }

    public function delete(int $userId, string $id): void
    {
        if (!self::isRowId($id) || !$this->ready()) {
            $this->fallback->delete($userId, $id);

            return;
        }
        if ($userId > 0) {
            $this->model()->where('id', (int) $id)->where('user_id', $userId)->delete();
        }
    }

    public function deleteAll(int $userId): void
    {
        $this->fallback->deleteAll($userId);
        if ($userId > 0 && $this->ready()) {
            $this->model()->where('user_id', $userId)->delete();
        }
    }

    public function purgeOlderThan(int $seconds): int
    {
        if (!$this->ready()) {
            return $this->fallback->purgeOlderThan($seconds);
        }

        $deleted = $this->model()->where('updated_at', '<', gmdate('Y-m-d H:i:s', time() - max(0, $seconds)))->delete();

        return Conversation::failed() ? 0 : (int) $deleted;
    }

    /**
     * A page of summaries. `$archived` null lists everything newest first (list());
     * true / false one side, live ones pinned first (query()). The flags are keys of
     * the context JSON this class encodes, so a LIKE on `"pinned":true` is exact.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    private function page(int $userId, string $search, ?bool $archived, int $limit, int $offset): array
    {
        if ($userId <= 0) {
            return ['items' => [], 'total' => 0];
        }

        $total = $this->scope($userId, $search, $archived)->count();
        $query = $this->scope($userId, $search, $archived)->select(Conversation::SUMMARY_COLUMNS);
        $query = null === $archived
            ? $query->orderBy('updated_at')->desc()->orderBy('id')->desc()
            : $query->orderByRaw("(COALESCE(context, '') LIKE %s) DESC, updated_at DESC, id DESC", ['%"pinned":true%']);
        $rows = $query->skip(max(0, $offset))->take(max(1, min(100, $limit)))->get();
        if (Conversation::failed()) {
            return ['items' => [], 'total' => 0];
        }

        $items = [];
        foreach ($rows as $row) {
            $context = Conversation::decode($row->getAttribute('context')) ?? [];
            $items[] = [
                'id'       => (string) $row->getAttribute('id'),
                'title'    => (string) $row->getAttribute('title'),
                'context'  => $context,
                'messages' => (int) $row->getAttribute('message_count'),
                'created'  => Conversation::timestamp($row->getAttribute('created_at')),
                'updated'  => Conversation::timestamp($row->getAttribute('updated_at')),
                'pinned'   => !empty($context['pinned']),
                'archived' => !empty($context['archived']),
            ];
        }

        return ['items' => $items, 'total' => (int) $total];
    }

    /** The user's rows, searched, on one side of archived (null: both). */
    private function scope(int $userId, string $search, ?bool $archived)
    {
        $query = $this->model()->where('user_id', $userId);
        $search = trim($search);
        if ('' !== $search) {
            global $wpdb;
            $query->where('title', 'LIKE', '%' . $wpdb->esc_like(mb_substr($search, 0, 100)) . '%');
        }
        if (true === $archived) {
            $query->whereRaw('context LIKE %s', ['%"archived":true%']);
        } elseif (false === $archived) {
            $query->whereRaw('(context IS NULL OR context NOT LIKE %s)', ['%"archived":true%']);
        }

        return $query;
    }

    /** A fresh query on this plugin's table. */
    private function model(): Conversation
    {
        return Conversation::for($this->tables->optionPrefix());
    }

    private function ready(): bool
    {
        return $this->tables->ready(Tables::CONVERSATIONS);
    }

    private static function isRowId(string $id): bool
    {
        return '' !== $id && ctype_digit($id);
    }
}
