<?php

namespace BitApps\AiKit\Audit;

/**
 * An audit trail an activity screen can page through and filter.
 *
 * The kit only needs record() and recent() to work; a logger that also
 * implements this interface powers the activity log's paging, its filter menus
 * and its "clear" button (Rest\McpAdminController, Kit::auditSearch()).
 * OptionsAuditLogger implements it in memory; a table-backed logger in SQL.
 */
interface SearchableAuditLog extends AuditLoggerInterface
{
    /** The columns a search may filter on, each an exact match. */
    public const FILTERS = ['tool', 'status', 'channel', 'client_ref', 'user_id', 'resource_id'];

    /**
     * One page of events, newest first.
     *
     * @param array<string, mixed> $filters FILTERS plus `from` / `to` (`Y-m-d` or `Y-m-d H:i:s`, UTC)
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $page = 1, int $perPage = 25): array;

    /**
     * The distinct values of each filter column, for the filter menus.
     *
     * @return array<string, array<int, mixed>> column => values
     */
    public function facets(): array;

    /** Delete every event. */
    public function clear(): bool;
}
