<?php

namespace BitApps\AiKit\Audit;

/**
 * Where a plugin keeps its AI/MCP audit trail.
 *
 * The kit records who called which tool, through which channel and client, on
 * which resource, and how it ended — never arguments or results (only a digest).
 * Plugins with a table supply their own logger as the `audit` service on the
 * PluginContext; OptionsAuditLogger is the default.
 */
interface AuditLoggerInterface
{
    /**
     * @param array{
     *   tool: string,
     *   status: string,
     *   channel: string,
     *   user_id: int,
     *   client_ref: ?string,
     *   resource_id: ?string,
     *   item_count: int,
     *   args_digest: ?string,
     *   error_code: ?string,
     *   duration_ms: int,
     *   created_at: string,
     * } $event
     */
    public function record(array $event): void;

    /**
     * Most recent first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 50): array;
}
