<?php

namespace BitApps\AiKit\Storage;

use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Storage\Migrations\CreateAuditLogTable;
use BitApps\AiKit\Storage\Migrations\CreateConversationsTable;
use BitApps\AiKit\Storage\Migrations\Migration;
use BitApps\AiKit\Storage\Model\AuditEvent;
use BitApps\AiKit\Storage\Model\Conversation;
use BitApps\WPDatabase\Connection;

/**
 * The kit's own tables for plugins booted with `storage => tables`:
 * `{wp_prefix}{option_prefix}aikit_conversations` (chat history) and
 * `{wp_prefix}{option_prefix}aikit_audit_log` (activity log), built by the
 * migrations in `Storage\Migrations` on the Bit Apps ORM.
 *
 * Only the tables for parts the plugin ships are created: no conversations table
 * without the assistant's history, no log table without the activity log. They are
 * created on the plugin's activation (Kit::installTables()) and, because updates do
 * not run activation hooks, again on the first request after the schema or the
 * shipped parts change — on any request, not only an admin's, so nothing reads a
 * table that is not there yet. Until a table exists its store falls back to the
 * option or user meta, so no call is lost.
 */
final class Tables
{
    /** Bump with every new migration; the stamp also lists which tables were wanted. */
    public const SCHEMA_VERSION = 1;

    public const CONVERSATIONS = 'conversations';

    public const AUDIT = 'audit';

    /** @var array<string, bool> table name => exists, for this request */
    private static array $exists = [];

    public function __construct(private PluginContext $context) {}

    /**
     * Every schema step, oldest first. A new column or index is a new Migration
     * here (and a SCHEMA_VERSION bump), never an edit to a released one.
     *
     * @return Migration[]
     */
    public static function migrations(): array
    {
        return [new CreateConversationsTable(), new CreateAuditLogTable()];
    }

    /** The plugin's option prefix: the ORM models put the site's table prefix before it. */
    public function optionPrefix(): string
    {
        return $this->context->optionPrefix();
    }

    /** `{wp_prefix}{option_prefix}`: the site's prefix on multisite. */
    public function prefix(): string
    {
        return (self::connected() ? (string) Connection::wpPrefix() : 'wp_') . $this->context->optionPrefix();
    }

    public function conversationsTable(): string
    {
        return $this->prefix() . Conversation::TABLE;
    }

    public function auditTable(): string
    {
        return $this->prefix() . AuditEvent::TABLE;
    }

    /**
     * Which tables this plugin needs, from what it ships.
     *
     * @return string[] CONVERSATIONS and/or AUDIT
     */
    public function wanted(): array
    {
        if (PluginContext::STORAGE_TABLES !== $this->context->storage()) {
            return [];
        }

        $wanted = [];
        if ($this->context->provides(PluginContext::FEATURE_ASSISTANT) && $this->context->provides(PluginContext::FEATURE_HISTORY)) {
            $wanted[] = self::CONVERSATIONS;
        }
        if ($this->context->provides(PluginContext::FEATURE_AUDIT)
            && ($this->context->provides(PluginContext::FEATURE_MCP) || $this->context->provides(PluginContext::FEATURE_ASSISTANT))) {
            $wanted[] = self::AUDIT;
        }

        return $wanted;
    }

    /** Whether a part's table is wanted and present. */
    public function ready(string $part): bool
    {
        return \in_array($part, $this->wanted(), true)
            && $this->exists(self::CONVERSATIONS === $part ? $this->conversationsTable() : $this->auditTable());
    }

    /** Run the migrations when the schema or the shipped parts changed. Cheap otherwise: one autoloaded option. */
    public function maybeInstall(): void
    {
        if ([] === $this->wanted() || !\function_exists('get_option')) {
            return;
        }
        if (get_option($this->versionOption()) !== $this->versionStamp()) {
            $this->install();
        }
    }

    /**
     * Run every migration of a wanted part (each safe to run again). Never drops a
     * table a plugin stopped wanting; uninstall() does.
     *
     * @return bool every wanted table exists afterwards
     */
    public function install(): bool
    {
        $wanted = $this->wanted();
        if ([] === $wanted || !self::connected()) {
            return false;
        }

        foreach (self::migrations() as $migration) {
            if (!\in_array($migration->part(), $wanted, true)) {
                continue;
            }

            try {
                $migration->up($this->prefix());
            } catch (\Throwable $error) {
                // No CREATE privilege, say: the stores keep using the option and user meta.
                $this->context->log('error', 'AI kit table migration failed: ' . $error->getMessage(), ['migration' => $migration::class]);
            }
        }
        self::$exists = [];

        $ok = true;
        foreach ($wanted as $part) {
            $ok = $this->ready($part) && $ok;
        }
        // Stamped only when it worked, so a failed attempt retries on the next request.
        if ($ok && \function_exists('update_option')) {
            update_option($this->versionOption(), $this->versionStamp(), true);
        }

        return $ok;
    }

    /** Drop both tables (wanted now or not) and the stamp. For the plugin's uninstall. */
    public function uninstall(): void
    {
        if (self::connected()) {
            foreach (array_reverse(self::migrations()) as $migration) {
                try {
                    $migration->down($this->prefix());
                } catch (\Throwable $error) {
                    $this->context->log('error', 'AI kit table removal failed: ' . $error->getMessage(), ['migration' => $migration::class]);
                }
            }
        }
        if (\function_exists('delete_option')) {
            delete_option($this->versionOption());
        }
        self::$exists = [];
    }

    public function exists(string $table): bool
    {
        if (isset(self::$exists[$table])) {
            return self::$exists[$table];
        }
        if (!self::connected()) {
            return false;
        }

        $found = Connection::get_var(Connection::prepare('SHOW TABLES LIKE %s', Connection::esc_like($table)));

        return self::$exists[$table] = $found === $table;
    }

    public function versionOption(): string
    {
        return $this->context->optionName('aikit_db_version');
    }

    /** Whether WordPress's database object is there (it is not in the kit's own unit tests). */
    public static function connected(): bool
    {
        global $wpdb;

        return isset($wpdb) && \is_object($wpdb);
    }

    /** @internal tests reset the per-request cache */
    public static function forget(): void
    {
        self::$exists = [];
    }

    private function versionStamp(): string
    {
        return self::SCHEMA_VERSION . ':' . implode(',', $this->wanted());
    }
}
