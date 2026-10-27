<?php

namespace BitApps\AiKit\Storage\Migrations;

use BitApps\AiKit\Storage\Model\Conversation;
use BitApps\AiKit\Storage\Tables;
use BitApps\WPDatabase\Blueprint;
use BitApps\WPDatabase\Schema;

/** `{prefix}aikit_conversations`: the assistant's chat history, one row per conversation. */
final class CreateConversationsTable implements Migration
{
    public function part(): string
    {
        return Tables::CONVERSATIONS;
    }

    public function up(string $prefix): void
    {
        Schema::withPrefix($prefix)->create(Conversation::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->bigint('user_id')->unsigned()->index();
            $table->varchar('title', 191)->defaultValue('');
            $table->text('context')->nullable();
            $table->longtext('messages');
            $table->longtext('pending')->nullable();
            $table->int('message_count')->unsigned()->defaultValue(0);
            $table->datetime('created_at');
            $table->datetime('updated_at')->index();
        });
    }

    public function down(string $prefix): void
    {
        Schema::withPrefix($prefix)->drop(Conversation::TABLE);
    }
}
