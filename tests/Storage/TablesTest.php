<?php

namespace BitApps\AiKit\Tests\Storage;

use BitApps\AiKit\Kit;
use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Storage\TableAuditLogger;
use BitApps\AiKit\Storage\Tables;
use BitApps\AiKit\Tests\TestCase;

/**
 * Which tables a plugin gets for `storage => tables`, and how the kit behaves
 * before they exist. The SQL itself runs against MySQL in a consumer's
 * WordPress suite; these tests have no database, which is the "table not
 * there yet" case every store must survive.
 *
 * @internal
 */
final class TablesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tables::forget();
    }

    public function testOptionsStorageWantsNoTablesAndRegistersNothing(): void
    {
        $kit = Kit::boot($this->context());

        self::assertSame([], $kit->tables()->wanted());
        self::assertFalse(has_action('init', [$kit, 'onStorageInit']));
        self::assertNotInstanceOf(TableAuditLogger::class, $kit->auditLogger(), 'the option store, as before');
    }

    public function testTablesFollowWhatThePluginShips(): void
    {
        $all = Kit::boot($this->context(['storage' => PluginContext::STORAGE_TABLES]));
        self::assertSame([Tables::CONVERSATIONS, Tables::AUDIT], $all->tables()->wanted());
        self::assertSame('wp_bit_demo_aikit_conversations', $all->tables()->conversationsTable());
        self::assertSame('wp_bit_demo_aikit_audit_log', $all->tables()->auditTable());

        $cases = [
            'no activity log'      => [[PluginContext::FEATURE_AUDIT => false], [Tables::CONVERSATIONS]],
            'no history'           => [[PluginContext::FEATURE_HISTORY => false], [Tables::AUDIT]],
            'MCP only'             => [[PluginContext::FEATURE_ASSISTANT => false], [Tables::AUDIT]],
            'MCP only, no log'     => [[PluginContext::FEATURE_ASSISTANT => false, PluginContext::FEATURE_AUDIT => false], []],
            'assistant, no extras' => [[PluginContext::FEATURE_MCP => false, PluginContext::FEATURE_AUDIT => false, PluginContext::FEATURE_HISTORY => false], []],
        ];
        foreach ($cases as $name => [$features, $wanted]) {
            resetAiKitTestState();
            $kit = Kit::boot($this->context(['storage' => PluginContext::STORAGE_TABLES, 'features' => $features]));
            self::assertSame($wanted, $kit->tables()->wanted(), $name);
            self::assertSame([] !== $wanted, false !== has_action('init', [$kit, 'onStorageInit']), $name . ': hooks only when a table is wanted');
        }
    }

    public function testBeforeTheTablesExistTheStoresFallBackAndNothingIsLost(): void
    {
        $kit = Kit::boot($this->context(['storage' => PluginContext::STORAGE_TABLES]));

        $logger = $kit->auditLogger();
        self::assertInstanceOf(TableAuditLogger::class, $logger);
        $logger->record(['tool' => 'list-forms', 'status' => 'ok', 'channel' => 'mcp', 'user_id' => 5, 'client_ref' => null, 'resource_id' => null, 'item_count' => 1, 'args_digest' => null, 'error_code' => null, 'duration_ms' => 2, 'created_at' => gmdate('Y-m-d H:i:s')]);
        self::assertSame(['list-forms'], array_column($logger->recent(), 'tool'), 'kept in the option until the table exists');
        self::assertSame(1, $logger->search(['tool' => 'list-forms'])['total']);

        $store = $kit->conversations();
        $store->save(5, [['role' => 'user', 'content' => 'Hi']], null);
        self::assertSame('Hi', $store->load(5)['messages'][0]['content'], 'the chat is kept in user meta meanwhile');

        self::assertFalse($kit->installTables(), 'no database: nothing to create, and no stamp');
        self::assertArrayNotHasKey('bit_demo_aikit_db_version', \AiKitTestState::$options);
    }

    public function testTheAuditSwitchStillWinsOverTheTables(): void
    {
        update_option('bit_demo_ai_settings', ['audit' => false]);
        $kit = Kit::boot($this->context(['storage' => PluginContext::STORAGE_TABLES]));

        self::assertInstanceOf(\BitApps\AiKit\Audit\NullAuditLogger::class, $kit->auditLogger());
    }

    public function testThePurgeIsScheduledOnceAndClearedOnDeactivation(): void
    {
        $kit = Kit::boot($this->context(['storage' => PluginContext::STORAGE_TABLES]));

        $kit->onStorageInit();
        $kit->onStorageInit();
        self::assertSame('daily', \AiKitTestState::$cron['bit_demo_aikit_purge']['recurrence']);
        self::assertNotFalse(has_action('bit_demo_aikit_purge', [$kit, 'onPurge']));

        $kit->onPurge(); // no tables: nothing to purge, nothing breaks

        $kit->deactivate();
        self::assertArrayNotHasKey('bit_demo_aikit_purge', \AiKitTestState::$cron);
    }

    public function testStorageMustBeOptionsOrTables(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->context(['storage' => 'redis']);
    }
}
