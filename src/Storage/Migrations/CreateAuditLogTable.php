<?php

namespace BitApps\AiKit\Storage\Migrations;

use BitApps\AiKit\Storage\Model\AuditEvent;
use BitApps\AiKit\Storage\Tables;
use BitApps\WPDatabase\Blueprint;
use BitApps\WPDatabase\Schema;

/** `{prefix}aikit_audit_log`: the activity log, one row per tool call; no arguments or results. */
final class CreateAuditLogTable implements Migration
{
    public function part(): string
    {
        return Tables::AUDIT;
    }

    public function up(string $prefix): void
    {
        Schema::withPrefix($prefix)->create(AuditEvent::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->datetime('created_at')->index();
            $table->bigint('user_id')->unsigned()->defaultValue(0)->index();
            $table->varchar('channel', 20);
            $table->varchar('client_ref', 191)->nullable();
            $table->varchar('tool', 100)->index();
            $table->varchar('resource_id', 64)->nullable();
            $table->int('item_count')->unsigned()->defaultValue(0);
            $table->varchar('status', 30)->index();
            $table->varchar('error_code', 64)->nullable();
            $table->int('duration_ms')->unsigned()->defaultValue(0);
            $table->char('args_digest', 64)->nullable();
        });
    }

    public function down(string $prefix): void
    {
        Schema::withPrefix($prefix)->drop(AuditEvent::TABLE);
    }
}
