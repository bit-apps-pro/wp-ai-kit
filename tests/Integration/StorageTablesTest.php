<?php

namespace BitApps\AiKit\Tests\Integration;

use BitApps\AiKit\Kit;
use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Storage\TableAuditLogger;
use BitApps\AiKit\Storage\Tables;
use BitApps\WPDatabase\Connection;

/**
 * `storage => tables` against MySQL: the migrations, the two ORM stores, the
 * purge, uninstall, and that only shipped parts get a table.
 *
 * The WordPress test case turns CREATE TABLE into temporary tables, which
 * SHOW TABLES cannot see; these tests create real ones and drop them after.
 *
 * @internal
 */
final class StorageTablesTest extends \WP_UnitTestCase
{
    private const PREFIX = 'aikitint_';

    protected function setUp(): void
    {
        parent::setUp();
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);
        Tables::forget();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}" . self::PREFIX . "aikit_conversations, {$wpdb->prefix}" . self::PREFIX . 'aikit_audit_log');
        Tables::forget();
        parent::tearDown();
    }

    public function testInstallCreatesBothTablesWithoutTouchingTheOrmPrefix(): void
    {
        global $wpdb;
        $hostPrefix = Connection::getPrefix();
        $kit = $this->boot();

        self::assertTrue($kit->installTables(), (string) $wpdb->last_error);
        self::assertSame($hostPrefix, Connection::getPrefix(), 'the ORM prefix belongs to the host plugin');
        self::assertTrue($kit->tables()->ready(Tables::CONVERSATIONS));
        self::assertTrue($kit->tables()->ready(Tables::AUDIT));
        self::assertSame(Tables::SCHEMA_VERSION . ':conversations,audit', get_option(self::PREFIX . 'aikit_db_version'));
        self::assertSame(
            ['id', 'created_at', 'user_id', 'channel', 'client_ref', 'tool', 'resource_id', 'item_count', 'status', 'error_code', 'duration_ms', 'args_digest'],
            $wpdb->get_col('SHOW COLUMNS FROM ' . $kit->tables()->auditTable())
        );
    }

    public function testConversationsAreScopedSearchedFlaggedAndPurged(): void
    {
        global $wpdb;
        $kit = $this->boot();
        $kit->installTables();
        $repo = $kit->conversations()->repository();

        $a = $repo->save(7, null, 'Survey review', [['role' => 'user', 'content' => 'Review survey']], null, ['form_id' => 3]);
        $b = $repo->save(7, null, 'Hello', [['role' => 'user', 'content' => 'Hi']], ['name' => 'x'], []);
        self::assertMatchesRegularExpression('/^\d+$/', $a);
        self::assertSame($a, $repo->save(7, $a, 'Survey review', [['role' => 'user', 'content' => 'Review survey'], ['role' => 'assistant', 'text' => 'ok']], null, ['form_id' => 3, 'pinned' => true]));

        self::assertCount(2, $repo->find(7, $a)['messages']);
        self::assertSame(['name' => 'x'], $repo->find(7, $b)['pending']);
        self::assertNull($repo->find(8, $a), 'another user\'s conversation is not found');

        $live = $repo->query(7, []);
        self::assertSame(2, $live['total']);
        self::assertSame($a, $live['items'][0]['id'], 'pinned first');
        self::assertSame([$a], array_column($repo->query(7, ['search' => 'survey'])['items'], 'id'));

        $repo->save(7, $b, 'Hello', [['role' => 'user', 'content' => 'Hi']], null, ['archived' => true]);
        self::assertSame([$b], array_column($repo->query(7, ['archived' => true])['items'], 'id'));
        self::assertSame(1, $repo->query(7, [])['total']);

        $repo->delete(8, $a);
        self::assertNotNull($repo->find(7, $a), 'delete is scoped to the owner');

        $wpdb->update($kit->tables()->conversationsTable(), ['updated_at' => gmdate('Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS)], ['id' => (int) $a]);
        self::assertSame(1, $repo->purgeOlderThan(30 * DAY_IN_SECONDS));
        $repo->deleteAll(7);
        self::assertSame(0, $repo->list(7)['total']);
    }

    public function testTheActivityLogSearchesFiltersFacetsAndClears(): void
    {
        $kit = $this->boot();
        $kit->installTables();
        $logger = $kit->auditLogger();
        self::assertInstanceOf(TableAuditLogger::class, $logger);

        $event = static fn (string $tool, string $status, int $user, string $client, ?string $resource = null, ?string $at = null): array => [
            'tool'       => $tool, 'status' => $status, 'channel' => 'mcp', 'user_id' => $user, 'client_ref' => $client, 'resource_id' => $resource,
            'item_count' => 1, 'args_digest' => str_repeat('a', 64), 'error_code' => null, 'duration_ms' => 3, 'created_at' => $at ?? gmdate('Y-m-d H:i:s'),
        ];
        $logger->record($event('list-forms', 'ok', 5, 'Claude'));
        $logger->record($event('get-form', 'denied', 6, 'Cursor', '12'));
        $logger->record($event('get-form', 'ok', 5, 'Claude', '12', '2020-01-02 10:00:00'));

        self::assertSame(3, $logger->search([])['total']);
        self::assertSame(5, $logger->search([])['rows'][0]['user_id']);
        self::assertSame(1, $logger->search(['status' => 'denied'])['total']);
        self::assertSame(2, $logger->search(['user_id' => 5])['total']);
        self::assertSame(1, $logger->search(['from' => '2020-01-01', 'to' => '2020-01-02'])['total'], 'a bare to-date covers the whole day');
        self::assertCount(1, $logger->search([], 2, 2)['rows']);

        $facets = $logger->facets();
        self::assertSame(['Claude', 'Cursor'], $facets['client_ref']);
        self::assertSame([5, 6], $facets['user_id']);

        self::assertSame(1, $logger->purgeOlderThan(30));
        self::assertTrue($logger->clear());
        self::assertSame(0, $logger->search([])['total']);
    }

    public function testOnlyShippedPartsGetATableAndUninstallDropsThem(): void
    {
        global $wpdb;
        $kit = $this->boot([PluginContext::FEATURE_AUDIT => false]);

        self::assertTrue($kit->installTables());
        self::assertNull($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $kit->tables()->auditTable())), 'no activity log, no table');
        self::assertTrue($kit->tables()->ready(Tables::CONVERSATIONS));

        $kit->uninstallTables();
        Tables::forget();
        self::assertNull($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $kit->tables()->conversationsTable())));
        self::assertFalse(get_option(self::PREFIX . 'aikit_db_version'));
    }

    public function testTheTablesAreCreatedOnAnyRequestAndThePurgeIsScheduled(): void
    {
        $kit = $this->boot();
        wp_set_current_user(0);

        $kit->onStorageInit();
        Tables::forget();
        self::assertTrue($kit->tables()->ready(Tables::AUDIT), 'a visitor\'s request is enough');
        self::assertNotFalse(wp_next_scheduled($kit->purgeHook()));

        $kit->deactivate();
        self::assertFalse(wp_next_scheduled($kit->purgeHook()));
    }

    /** @param array<string, bool> $features */
    private function boot(array $features = []): Kit
    {
        // Boot is idempotent per slug: every test gets its own.
        return Kit::boot(PluginContext::create([
            'slug'           => 'aikit-int-' . uniqid(),
            'name'           => 'AI Kit integration',
            'version'        => '1.0.0',
            'option_prefix'  => self::PREFIX,
            'rest_namespace' => 'aikit-int/v1',
            'storage'        => PluginContext::STORAGE_TABLES,
            'features'       => $features,
        ]));
    }
}
