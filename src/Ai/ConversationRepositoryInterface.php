<?php

namespace BitApps\AiKit\Ai;

/**
 * Where the assistant keeps each user's conversations. A plugin with its own
 * table supplies one through the `conversations` service of its PluginContext;
 * without one the kit keeps a single conversation per user in user meta.
 *
 * Every method is scoped to a user: an id from one user never reaches another's
 * conversation. Ids are strings so storage can choose its own.
 */
interface ConversationRepositoryInterface
{
    /**
     * @return null|array{id: string, title: string, messages: array<int, array<string, mixed>>, pending: ?array<string, mixed>, context: array<string, mixed>, created: int, updated: int}
     */
    public function find(int $userId, string $id): ?array;

    /**
     * Store a conversation; a null id creates one. Returns its id.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param null|array<string, mixed>        $pending
     * @param array<string, mixed>             $context  e.g. the form it is about
     */
    public function save(int $userId, ?string $id, string $title, array $messages, ?array $pending, array $context): string;

    /**
     * The user's conversations, most recent first, without their messages.
     *
     * @return array{items: array<int, array{id: string, title: string, context: array<string, mixed>, messages: int, created: int, updated: int}>, total: int}
     */
    public function list(int $userId, string $search = '', int $limit = 20, int $offset = 0): array;

    public function delete(int $userId, string $id): void;

    public function deleteAll(int $userId): void;

    /** Remove conversations untouched for longer than this many seconds; returns how many. */
    public function purgeOlderThan(int $seconds): int;
}
