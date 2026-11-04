<?php

namespace BitApps\AiKit\Kit;

use BitApps\AiKit\Ai\Attachments\AttachmentStore;
use BitApps\AiKit\Kit;
use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Storage\TableAuditLogger;
use BitApps\AiKit\Storage\Tables;

/**
 * What the kit keeps on a site over time: scheduled work, the purges it runs, and
 * removing all of it on deactivation and uninstall.
 *
 * @internal plugins call these through Kit
 */
final class Lifecycle
{
    public function __construct(private Kit $kit) {}

    /** Stop the kit's scheduled work. */
    public function deactivate(): void
    {
        if (\function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook($this->kit->purgeHook());
            wp_clear_scheduled_hook($this->kit->attachmentsPurgeHook());
        }
    }

    /**
     * Everything the kit keeps on the current site, removed: its options (settings,
     * provider keys, API keys, OAuth clients and grants, the option-stored activity
     * log), each user's chats and open-chat pointer in user meta, attached files,
     * scheduled work and, with $dropTables, its tables (chat history and activity
     * log) with their version stamp. Short-lived transients (rate limits, one-time
     * codes, reply locks) are left to expire.
     *
     * Static so a plugin's uninstall can call it without booting the kit: it reads
     * only the context's prefix and storage. On a network, call it for each site.
     * A plugin that lets users keep their data passes its own setting as $dropTables.
     */
    public static function removeSiteData(PluginContext $context, bool $dropTables = true): void
    {
        if (\function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook($context->optionName('aikit_purge'));
            wp_clear_scheduled_hook($context->optionName('aikit_attachments_purge'));
        }
        if ($dropTables) {
            (new Tables($context))->uninstall();
        }
        (new AttachmentStore($context))->removeAll();

        foreach (Kit::OWNED_OPTIONS as $option) {
            delete_option($context->optionName($option));
        }
        $metaKey = Kit::conversationMetaKey($context);
        delete_metadata('user', 0, $metaKey, '', true);
        delete_metadata('user', 0, $metaKey . '_active', '', true);
    }

    /** init: keep the tables current and the daily purge scheduled (storage `tables` only). */
    public function onStorageInit(): void
    {
        $this->kit->tables()->maybeInstall();
        if (\function_exists('wp_next_scheduled') && !wp_next_scheduled($this->kit->purgeHook())) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', $this->kit->purgeHook());
        }
    }

    /** The daily purge: activity older than its retention, conversations untouched for longer than the history keeps them. */
    public function onPurge(): void
    {
        $logger = $this->kit->auditLogger();
        if ($logger instanceof TableAuditLogger) {
            $logger->purgeOlderThan((int) ($this->kit->mcpSettings()->read()['audit_retention_days'] ?? 90));
        }
        if (\in_array(Tables::CONVERSATIONS, $this->kit->tables()->wanted(), true)) {
            $days = max(1, (int) ($this->kit->aiSettings()->read()['history_days'] ?? 30));
            $this->kit->conversations()->repository()->purgeOlderThan($days * DAY_IN_SECONDS);
        }
    }

    /** init: keep the hourly removal of expired attachments scheduled. */
    public function onAttachmentsInit(): void
    {
        if (\function_exists('wp_next_scheduled') && !wp_next_scheduled($this->kit->attachmentsPurgeHook())) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', $this->kit->attachmentsPurgeHook());
        }
    }

    /** The hourly purge of attachments older than their time to live. */
    public function onPurgeAttachments(): void
    {
        $this->kit->attachments()?->purgeExpired();
    }
}
