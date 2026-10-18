<?php

namespace BitApps\AiKit\Tests\Settings;

use BitApps\AiKit\Contract;
use BitApps\AiKit\Kit;
use BitApps\AiKit\Settings\McpSettings;
use BitApps\AiKit\Tests\TestCase;

/**
 * @internal
 */
final class McpSettingsTest extends TestCase
{
    public function testSavingValidatesAndKeepsUnsentKeys(): void
    {
        $settings = new McpSettings($this->context());

        $saved = $settings->save(['enabled' => 'true', 'access_level' => 'root', 'calls_per_minute' => 99999, 'disabled_tools' => ['a', 'zzz', 3], 'evil' => 1], ['a', 'b']);

        self::assertTrue($saved['enabled']);
        self::assertSame('read', $saved['access_level'], 'unknown level falls back');
        self::assertSame(1000, $saved['calls_per_minute']);
        self::assertSame(['a'], $saved['disabled_tools'], 'only known tools');
        self::assertArrayNotHasKey('evil', get_option('bit_demo_mcp_settings'));

        $again = $settings->save(['access_level' => 'write']);
        self::assertTrue($again['enabled'], 'unsent keys keep their value');
        self::assertSame('write', $again['access_level']);
    }

    public function testAdminStateListsToolsWithTheirStatus(): void
    {
        add_filter(Contract::toolProvidersHook('bit-demo'), static fn ($p) => array_merge($p, [[
            ['name' => 'list_a', 'description' => 'A.', 'annotations' => ['readonly' => true], 'handler' => 'strlen'],
            ['name' => 'save_b', 'description' => 'B.', 'tier' => 'pro', 'handler' => 'strlen'],
        ]]), 10, 1);
        $kit = Kit::boot($this->context());
        $kit->saveMcpSettings(['enabled' => true]);

        $state = $kit->adminState();

        self::assertTrue($state['settings']['enabled']);
        self::assertSame('https://example.test/wp-json/bit-demo/v1/mcp', $state['endpoint']);
        self::assertSame(['list_a' => true, 'save_b' => false], array_column($state['tools'], 'enabled', 'name'));
        self::assertSame('pro', $state['tools'][1]['tier']);
        self::assertContains('abilities_api', array_column($state['requirements'], 'id'));
    }
}
