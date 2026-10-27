<?php

namespace BitApps\AiKit\Storage\Model;

/**
 * One assistant conversation: `{wp_prefix}{option_prefix}aikit_conversations`.
 * `context`, `messages` and `pending` hold JSON the kit encodes itself.
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $title
 * @property null|string $context
 * @property string      $messages
 * @property null|string $pending
 * @property int         $message_count
 * @property string      $created_at
 * @property string      $updated_at
 */
final class Conversation extends StorageModel
{
    public const TABLE = 'aikit_conversations';

    /** What the history list reads: everything but the transcript. */
    public const SUMMARY_COLUMNS = ['id', 'title', 'context', 'message_count', 'created_at', 'updated_at'];

    protected $table = self::TABLE;

    protected $fillable = ['user_id', 'title', 'context', 'messages', 'pending', 'message_count', 'created_at', 'updated_at'];
}
