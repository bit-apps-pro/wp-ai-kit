<?php

namespace BitApps\AiKit\Tests;

use BitApps\AiKit\Contract;
use BitApps\AiKit\Kit;
use BitApps\AiKit\PluginContext;
use BitApps\AiKit\Settings\FeatureFlags;

/**
 * @internal
 */
final class KitTest extends TestCase
{
    public function testBootIsIdempotentPerSlugAndAnnounced(): void
    {
        $announced = [];
        add_action(Contract::bootedHook('bit-demo'), static function ($kit) use (&$announced): void {
            $announced[] = $kit;
        }, 10, 1);

        $kit = Kit::boot($this->context());

        self::assertSame($kit, Kit::boot($this->context()));
        self::assertSame($kit, Kit::for('bit-demo'));
        self::assertNull(Kit::for('other'));
        self::assertCount(1, $announced);
    }

    public function testFeaturesAreOffUntilEnabledAndCanBeForcedOff(): void
    {
        $kit = Kit::boot($this->context());
        $flags = $kit->features();

        self::assertFalse($flags->isEnabled(FeatureFlags::MCP_SERVER));
        self::assertTrue($flags->isEnabled(FeatureFlags::ABILITIES_BRIDGE), 'bridges are harmless and default on');

        update_option('bit_demo_mcp_settings', ['enabled' => true, 'oauth' => true]);
        self::assertTrue($flags->isEnabled(FeatureFlags::MCP_SERVER));
        self::assertTrue($flags->isEnabled(FeatureFlags::OAUTH));
        self::assertFalse($flags->isEnabled(FeatureFlags::API_KEYS));

        add_filter(Contract::FEATURE_DISABLED_FILTER, static fn ($off, $feature, $slug) => FeatureFlags::MCP_SERVER === $feature && 'bit-demo' === $slug ? true : $off, 10, 3);
        self::assertTrue($flags->isForcedOff(FeatureFlags::MCP_SERVER));
        self::assertFalse($flags->isEnabled(FeatureFlags::MCP_SERVER));
        self::assertTrue($flags->isEnabled(FeatureFlags::OAUTH), 'the filter is per feature');

        self::assertFalse($flags->isEnabled('shell'));
        self::assertSame('BIT_DEMO_MCP_DISABLED', $flags->killSwitchConstant(FeatureFlags::OAUTH));
        self::assertSame('BIT_DEMO_AI_DISABLED', $flags->killSwitchConstant(FeatureFlags::ASSISTANT));
        self::assertSame(['bit_demo_mcp_settings', 'bit_demo_ai_settings'], $flags->optionNames());
    }

    public function testMalformedSettingsReadAsDefaults(): void
    {
        update_option('bit_demo_ai_settings', 'yes');
        $flags = Kit::boot($this->context())->features();

        self::assertFalse($flags->isEnabled(FeatureFlags::ASSISTANT));
        self::assertTrue($flags->isEnabled(FeatureFlags::AUDIT));
    }

    public function testAPluginWithoutMcpRegistersNoMcpHooksAndCannotTurnItOn(): void
    {
        $kit = Kit::boot($this->context(['slug' => 'bit-chat', 'option_prefix' => 'bit_chat_', 'rest_namespace' => 'bit-chat/v1', 'features' => [PluginContext::FEATURE_MCP => false]]));

        foreach (['rest_api_init', 'init', 'deleted_user', 'wp_abilities_api_init', 'mcp_adapter_init', 'admin_post_nopriv_bitchat_mcp_authorize'] as $hook) {
            self::assertFalse(has_action($hook), $hook . ' is not hooked');
        }

        update_option('bit_chat_mcp_settings', ['enabled' => true, 'oauth' => true]);
        update_option('bit_chat_ai_settings', ['enabled' => true]);
        self::assertTrue($kit->features()->isForcedOff(FeatureFlags::MCP_SERVER), 'settings cannot switch on a feature the plugin does not ship');
        self::assertFalse($kit->features()->isEnabled(FeatureFlags::OAUTH));
        self::assertTrue($kit->features()->isEnabled(FeatureFlags::ASSISTANT));
        self::assertTrue($kit->features()->isEnabled(FeatureFlags::AUDIT), 'the assistant still logs its calls');
    }

    public function testAPluginWithoutTheAssistantCannotTurnItOn(): void
    {
        $kit = Kit::boot($this->context(['features' => [PluginContext::FEATURE_ASSISTANT => false]]));
        update_option('bit_demo_ai_settings', ['enabled' => true]);

        self::assertTrue($kit->features()->isForcedOff(FeatureFlags::ASSISTANT));
        self::assertTrue(has_action('rest_api_init'), 'MCP hooks stay');
    }

    public function testMcpRoutesRegisterWithoutBuildingTheServer(): void
    {
        $kit = Kit::boot($this->context());
        update_option('bit_demo_mcp_settings', ['enabled' => true]);

        do_action('rest_api_init');

        self::assertArrayHasKey('/bit-demo/v1/mcp', \AiKitRest::$routes);
        $transport = new \ReflectionProperty(Kit::class, 'transport');
        $transport->setAccessible(true);
        self::assertNull($transport->getValue($kit), 'built only when a request reaches the endpoint');
    }

    public function testFactoryServicesAreBuiltOnceAndOnlyWhenAsked(): void
    {
        $built = 0;
        $classifier = static fn () => 'personal';
        $context = $this->context(['services' => [
            'audit' => static function () use (&$built) {
                ++$built;

                return new \stdClass();
            },
            'pii_classifier' => $classifier,
        ]]);

        self::assertSame(0, $built);
        self::assertSame($context->service('audit'), $context->service('audit'));
        self::assertSame(1, $built);
        self::assertSame($classifier, $context->service('pii_classifier'), 'other services are returned as given');
    }

    public function testSettingsAreAutoloadedSoRequestsNeedNoExtraQuery(): void
    {
        $kit = Kit::boot($this->context());

        self::assertFalse($kit->features()->isEnabled(FeatureFlags::MCP_SERVER));
        self::assertSame([], \AiKitTestState::$options['bit_demo_mcp_settings'], 'a never-saved option gets an empty placeholder');
        self::assertTrue(\AiKitTestState::$autoload['bit_demo_mcp_settings']);

        $kit->mcpSettings()->save(['enabled' => true]);
        self::assertTrue(\AiKitTestState::$autoload['bit_demo_mcp_settings']);
        self::assertTrue($kit->features()->isEnabled(FeatureFlags::MCP_SERVER), 'the placeholder does not hide saved values');
    }
}
