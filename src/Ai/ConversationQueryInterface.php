<?php

namespace BitApps\AiKit\Ai;

/**
 * A conversation repository that can answer the history screen's richer query
 * in storage: archived or not, pinned first, searched and paged together.
 *
 * Optional. Without it the ConversationStore asks list() and sorts and filters
 * the page in PHP, which is exact for the one-conversation user-meta store and
 * approximate (per page) for anything larger — so a table-backed repository
 * should implement this.
 */
interface ConversationQueryInterface
{
    /**
     * @param array{search?: string, limit?: int, offset?: int, archived?: bool} $args
     *                                                                                 `archived` false (default) lists the live conversations, pinned first;
     *                                                                                 true lists the archived ones
     *
     * @return array{items: array<int, array<string, mixed>>, total: int} items as list() gives them,
     *                                                                    each with `pinned` and `archived` booleans
     */
    public function query(int $userId, array $args): array;
}
