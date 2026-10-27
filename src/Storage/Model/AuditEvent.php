<?php

namespace BitApps\AiKit\Storage\Model;

/**
 * One tool call in the activity log: `{wp_prefix}{option_prefix}aikit_audit_log`.
 * Arguments and results are never stored, only a digest of the arguments.
 *
 * @property int         $id
 * @property string      $created_at  UTC
 * @property int         $user_id
 * @property string      $channel
 * @property null|string $client_ref
 * @property string      $tool
 * @property null|string $resource_id
 * @property int         $item_count
 * @property string      $status
 * @property null|string $error_code
 * @property int         $duration_ms
 * @property null|string $args_digest
 */
final class AuditEvent extends StorageModel
{
    public const TABLE = 'aikit_audit_log';

    public const COLUMNS = ['created_at', 'user_id', 'channel', 'client_ref', 'tool', 'resource_id', 'item_count', 'status', 'error_code', 'duration_ms', 'args_digest'];

    protected $table = self::TABLE;

    protected $fillable = self::COLUMNS;
}
