<?php

namespace BitApps\AiKit\Tests\Abilities;

use BitApps\AiKit\Contract;
use BitApps\AiKit\Kit;
use BitApps\AiKit\Tests\TestCase;

/**
 * @internal
 */
final class AbilitiesBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->grant(3, 'manage_options');
        add_filter(Contract::toolProvidersHook('bit-demo'), static fn ($p) => array_merge($p, [[
            ['name' => 'list_forms', 'title' => 'List forms', 'description' => 'Forms.', 'annotations' => ['readonly' => true], 'handler' => static fn () => [['id' => 1, 'secret' => 'x']]],
            ['name' => 'get_form', 'description' => 'One form.', 'input_schema' => ['properties' => ['form_id' => ['type' => 'integer']], 'required' => ['form_id']], 'annotations' => ['readonly' => true], 'handler' => static fn (array $a) => ['id' => $a['form_id']]],
            ['name' => 'delete_form', 'description' => 'Delete.', 'annotations' => ['destructive' => true], 'handler' => static fn () => true],
        ]]), 10, 1);
    }

    public function testNothingIsMirroredUntilTheSiteUsesAi(): void
    {
        $this->bootWith(['enabled' => false]);
        do_action('wp_abilities_api_categories_init');
        do_action('wp_abilities_api_init');

        self::assertSame([], \AiKitAbilities::$abilities);
    }

    public function testNothingIsMirroredWhenNothingWouldReadIt(): void
    {
        if (class_exists('WP\MCP\Core\McpAdapter')) {
            self::markTestSkipped('The adapter stub from another test is loaded.');
        }
        $this->bootWith(['enabled' => true]);
        do_action('wp_abilities_api_categories_init');
        do_action('wp_abilities_api_init');

        self::assertSame([], \AiKitAbilities::$abilities, 'no abilities REST and no MCP Adapter: the tools are not loaded at all');
    }

    public function testTheMcpAdapterAloneIsAReasonToMirror(): void
    {
        if (!class_exists('WP\MCP\Core\McpAdapter')) {
            eval('namespace WP\MCP\Core; class McpAdapter {}');
        }
        $this->bootWith(['enabled' => true]);
        do_action('wp_abilities_api_init');

        self::assertArrayHasKey('bit-demo/list-forms', \AiKitAbilities::$abilities);
        self::assertFalse(\AiKitAbilities::$abilities['bit-demo/list-forms']['meta']['show_in_rest'], 'mirrored for the adapter, not offered over REST');
    }

    public function testNonDestructiveToolsBecomeAbilities(): void
    {
        $this->bootWith(['enabled' => true, 'abilities_in_rest' => true]);
        do_action('wp_abilities_api_categories_init');
        do_action('wp_abilities_api_init');

        self::assertArrayHasKey('bitapps-bit-demo', \AiKitAbilities::$categories);
        self::assertSame(['bit-demo/list-forms', 'bit-demo/get-form'], array_keys(\AiKitAbilities::$abilities), 'destructive tools are not mirrored');

        $list = \AiKitAbilities::$abilities['bit-demo/list-forms'];
        self::assertSame('bitapps-bit-demo', $list['category']);
        self::assertArrayNotHasKey('input_schema', $list, 'no-argument tools accept null input');
        self::assertFalse($list['meta']['mcp']['public']);
        self::assertTrue($list['meta']['show_in_rest']);
        self::assertTrue($list['meta']['annotations']['readonly']);
        self::assertSame('object', \AiKitAbilities::$abilities['bit-demo/get-form']['input_schema']['type']);
    }

    public function testAbilityCallsGoThroughTheGuardAndRedaction(): void
    {
        $this->bootWith(['enabled' => true, 'abilities_in_rest' => true]);
        do_action('wp_abilities_api_init');
        $ability = \AiKitAbilities::$abilities['bit-demo/list-forms'];

        self::assertFalse(($ability['permission_callback'])(null), 'logged out');

        \AiKitTestState::$currentUser = 3;
        self::assertTrue(($ability['permission_callback'])(null));
        self::assertSame([['id' => 1, 'secret' => '[redacted]']], ($ability['execute_callback'])(null));

        $error = (\AiKitAbilities::$abilities['bit-demo/get-form']['execute_callback'])(['form_id' => 'x']);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('invalid_arguments', $error->get_error_code());
    }

    public function testLateBootRegistersImmediately(): void
    {
        update_option('bit_demo_mcp_settings', ['enabled' => true, 'abilities_in_rest' => true]);
        do_action('wp_abilities_api_categories_init');
        do_action('wp_abilities_api_init');
        self::assertSame([], \AiKitAbilities::$abilities, 'the kit was not booted yet');

        Kit::boot($this->context());

        self::assertArrayHasKey('bitapps-bit-demo', \AiKitAbilities::$categories);
        self::assertArrayHasKey('bit-demo/list-forms', \AiKitAbilities::$abilities);
    }

    public function testAdapterServerListsTheMirroredAbilities(): void
    {
        $this->bootWith(['enabled' => true, 'abilities_in_rest' => true]);
        $adapter = new class {
            public array $servers = [];

            public function create_server(...$args): void
            {
                $this->servers[] = $args;
            }
        };

        do_action('mcp_adapter_init', $adapter);

        self::assertCount(1, $adapter->servers);
        [$id, $namespace, $route] = $adapter->servers[0];
        self::assertSame('bitapps-bit-demo', $id);
        self::assertSame('bit-demo/v1', $namespace);
        self::assertSame('mcp-adapter', $route);
        self::assertSame(['bit-demo/list-forms', 'bit-demo/get-form'], $adapter->servers[0][9]);

        $permission = $adapter->servers[0][12];
        self::assertFalse($permission(null));
        \AiKitTestState::$currentUser = 3;
        self::assertTrue($permission(null));
    }

    private function bootWith(array $settings): Kit
    {
        update_option('bit_demo_mcp_settings', $settings);

        return Kit::boot($this->context());
    }
}
