<?php

namespace BitApps\AiKit\Settings;

use BitApps\AiKit\Contract;
use BitApps\AiKit\PluginContext;

/**
 * Which kit features a plugin has switched on, and who can force them off.
 *
 * Features: `mcp_server` (the Bit transport), `oauth` (authorization server),
 * `api_keys`, `adapter_bridge` (register on the WordPress MCP Adapter when
 * present), `abilities_bridge` (mirror tools into the Abilities API when
 * present), `assistant` (AI engine + agent loop), `audit`.
 *
 * Resolution, strongest first: a constant `<PREFIX>AI_DISABLED` /
 * `<PREFIX>MCP_DISABLED` in wp-config.php (prefix = upper-cased option prefix,
 * e.g. `BITFORMS_MCP_DISABLED`), the `bitapps_ai_feature_disabled` filter, then
 * the `enabled` flag of the feature's settings option. Everything is off until a
 * site switches it on, except the two bridges, which are harmless and on by default.
 */
final class FeatureFlags
{
    public const MCP_SERVER = 'mcp_server';

    public const OAUTH = 'oauth';

    public const API_KEYS = 'api_keys';

    public const ADAPTER_BRIDGE = 'adapter_bridge';

    public const ABILITIES_BRIDGE = 'abilities_bridge';

    public const ASSISTANT = 'assistant';

    public const AUDIT = 'audit';

    /** Which kill-switch constant governs each feature. */
    private const KILL_SWITCH_GROUP = [
        self::MCP_SERVER       => 'MCP',
        self::OAUTH            => 'MCP',
        self::API_KEYS         => 'MCP',
        self::ADAPTER_BRIDGE   => 'MCP',
        self::ABILITIES_BRIDGE => 'MCP',
        self::ASSISTANT        => 'AI',
        self::AUDIT            => 'AI',
    ];

    /** Which settings option holds each feature's `enabled` flag. */
    private const SETTINGS_OPTION = [
        self::MCP_SERVER       => 'mcp_settings',
        self::OAUTH            => 'mcp_settings',
        self::API_KEYS         => 'mcp_settings',
        self::ADAPTER_BRIDGE   => 'mcp_settings',
        self::ABILITIES_BRIDGE => 'mcp_settings',
        self::ASSISTANT        => 'ai_settings',
        self::AUDIT            => 'ai_settings',
    ];

    /** @var array<string, bool> */
    private array $defaults;

    /**
     * @param array<string, bool> $defaults feature => default when the settings option says nothing
     */
    public function __construct(private PluginContext $context, array $defaults = [])
    {
        $this->defaults = array_merge([
            self::MCP_SERVER       => false,
            self::OAUTH            => true,
            self::API_KEYS         => false,
            self::ADAPTER_BRIDGE   => true,
            self::ABILITIES_BRIDGE => true,
            self::ASSISTANT        => false,
            self::AUDIT            => true,
        ], $defaults);
    }

    /** @return string[] */
    public static function all(): array
    {
        return array_keys(self::KILL_SWITCH_GROUP);
    }

    public function isEnabled(string $feature): bool
    {
        if (!isset(self::KILL_SWITCH_GROUP[$feature]) || $this->isForcedOff($feature)) {
            return false;
        }

        $settings = $this->settings($feature);
        $key = self::MCP_SERVER === $feature || self::ASSISTANT === $feature ? 'enabled' : $feature;

        if (\array_key_exists($key, $settings)) {
            return (bool) $settings[$key];
        }

        return $this->defaults[$feature];
    }

    /** Off by constant or filter — the UI shows the toggle locked. */
    public function isForcedOff(string $feature): bool
    {
        if (!isset(self::KILL_SWITCH_GROUP[$feature]) || !$this->shipped($feature)) {
            return true;
        }

        if ($this->isDisabledByConstant($feature)) {
            return true;
        }

        if (\function_exists('apply_filters')) {
            return true === apply_filters(Contract::FEATURE_DISABLED_FILTER, false, $feature, $this->context->slug());
        }

        return false;
    }

    public function isDisabledByConstant(string $feature): bool
    {
        $constant = $this->killSwitchConstant($feature);

        return null !== $constant && \defined($constant) && (bool) \constant($constant);
    }

    /** `BITFORMS_MCP_DISABLED` for Bit Form's MCP features, `BITFORMS_AI_DISABLED` for AI. */
    public function killSwitchConstant(string $feature): ?string
    {
        if (!isset(self::KILL_SWITCH_GROUP[$feature])) {
            return null;
        }

        return strtoupper(rtrim($this->context->optionPrefix(), '_')) . '_' . self::KILL_SWITCH_GROUP[$feature] . '_DISABLED';
    }

    /** The option name a feature's settings live in, e.g. `bitforms_mcp_settings`. */
    public function settingsOptionName(string $feature): ?string
    {
        if (!isset(self::SETTINGS_OPTION[$feature])) {
            return null;
        }

        return $this->context->optionName(self::SETTINGS_OPTION[$feature]);
    }

    /** @return string[] every option name the kit may store for this plugin's features */
    public function optionNames(): array
    {
        $names = [];
        foreach (array_unique(self::SETTINGS_OPTION) as $suffix) {
            $names[] = $this->context->optionName($suffix);
        }

        return $names;
    }

    /** @return array<string, mixed> */
    public function settings(string $feature): array
    {
        $option = $this->settingsOptionName($feature);
        if (null === $option || !\function_exists('get_option')) {
            return [];
        }

        $settings = get_option($option, null);
        if (null === $settings) {
            // Never saved: an empty, autoloaded placeholder (defaults still apply), so later
            // requests find it among the autoloaded options instead of querying for a missing one.
            if (\function_exists('add_option')) {
                add_option($option, [], '', 'yes');
            }

            return [];
        }

        return \is_array($settings) ? $settings : [];
    }

    /** Whether the plugin ships the part this feature belongs to (PluginContext `features`). */
    private function shipped(string $feature): bool
    {
        if (self::AUDIT === $feature) {
            return $this->context->provides(PluginContext::FEATURE_MCP) || $this->context->provides(PluginContext::FEATURE_ASSISTANT);
        }

        return $this->context->provides('MCP' === self::KILL_SWITCH_GROUP[$feature] ? PluginContext::FEATURE_MCP : PluginContext::FEATURE_ASSISTANT);
    }
}
