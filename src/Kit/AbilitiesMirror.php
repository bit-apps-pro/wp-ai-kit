<?php

namespace BitApps\AiKit\Kit;

use BitApps\AiKit\Abilities\AbilitiesBridge;
use BitApps\AiKit\Contract;
use BitApps\AiKit\Kit;
use BitApps\AiKit\Mcp\Transport\WpMcpAdapterBridge;
use BitApps\AiKit\Settings\FeatureFlags;

/**
 * When and how the plugin's tools are mirrored into the Abilities API and the MCP
 * Adapter. Kit::abilities() holds the bridge that does the mirroring.
 *
 * @internal plugins call these through Kit
 */
final class AbilitiesMirror
{
    public function __construct(private Kit $kit) {}

    /**
     * Mirror tools into the Abilities API (and the MCP Adapter) only while the
     * MCP server is on, and only when something will use them: the "offer as
     * WordPress abilities" setting, or the MCP Adapter plugin. Mirroring needs
     * every tool definition, and WordPress builds the abilities registry on REST
     * requests, so mirroring nobody reads would load the plugin's tools on each.
     */
    public function mirrorsAbilities(): bool
    {
        $features = $this->kit->features();

        // Only with the MCP server on: the assistant alone must not open another way in.
        if (!AbilitiesBridge::available()
            || !$features->isEnabled(FeatureFlags::ABILITIES_BRIDGE)
            || !$features->isEnabled(FeatureFlags::MCP_SERVER)) {
            return false;
        }

        return !empty($this->kit->mcpSettings()->read()['abilities_in_rest'])
            || ($features->isEnabled(FeatureFlags::ADAPTER_BRIDGE) && WpMcpAdapterBridge::available());
    }

    /** wp_abilities_api_categories_init */
    public function onAbilityCategoriesInit(): void
    {
        if ($this->mirrorsAbilities()) {
            $this->kit->abilities()->registerCategory();
        }
    }

    /** wp_abilities_api_init */
    public function onAbilitiesInit(): void
    {
        if ($this->mirrorsAbilities()) {
            $this->kit->abilities()->registerAbilities();
        }
    }

    /**
     * mcp_adapter_init.
     *
     * @param mixed $adapter
     */
    public function onMcpAdapterInit($adapter = null): void
    {
        if (!$this->mirrorsAbilities() || !$this->kit->features()->isEnabled(FeatureFlags::ADAPTER_BRIDGE)) {
            return;
        }

        (new WpMcpAdapterBridge($this->kit->context(), $this->kit->abilities()))->createServer($adapter);
    }

    /** The categories init already fired before this kit booted. */
    public function registerLateCategory(): void
    {
        if (!$this->mirrorsAbilities() || !class_exists(\WP_Ability_Categories_Registry::class)) {
            return;
        }

        $context = $this->kit->context();
        $slug = Contract::abilityCategory($context->slug());
        if (\function_exists('wp_has_ability_category') && wp_has_ability_category($slug)) {
            return;
        }

        $registry = \WP_Ability_Categories_Registry::get_instance();
        if (null !== $registry) {
            $registry->register($slug, ['label' => $context->name(), 'description' => 'Tools provided by ' . $context->name() . '.']);
        }
    }

    /** The abilities init already fired before this kit booted. */
    public function registerLateAbilities(): void
    {
        if (!$this->mirrorsAbilities() || !class_exists(\WP_Abilities_Registry::class)) {
            return;
        }

        $registry = \WP_Abilities_Registry::get_instance();
        if (null === $registry) {
            return;
        }

        foreach ($this->kit->registry()->all() as $tool) {
            $name = $tool->abilityName($this->kit->context()->slug());
            if ($tool->isDestructive() || (\function_exists('wp_has_ability') && wp_has_ability($name))) {
                continue;
            }
            $registry->register($name, $this->kit->abilities()->abilityArgs($tool));
        }
    }
}
