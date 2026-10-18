<?php

namespace BitApps\AiKit;

/**
 * The names every Bit plugin and the WordPress environment can agree on.
 *
 * Each plugin ships its own prefixed copy of this kit, so nothing here is shared
 * as PHP state: coordination happens through WordPress hooks, option names and
 * Abilities API names, which are plain strings and survive namespace prefixing.
 * These builders are the single place those strings are spelled.
 *
 * CONTRACT_VERSION changes only when a hook payload or a name scheme changes in
 * a way an older copy of the kit in another plugin could not read.
 */
final class Contract
{
    public const CONTRACT_VERSION = '1';

    /** Filter: `(bool $disabled, string $feature, string $slug)` — a host forces a feature off. */
    public const FEATURE_DISABLED_FILTER = 'bitapps_ai_feature_disabled';

    /** Filter: `(array $providers, PluginContext $ctx)` → ToolProviderInterface[] for one plugin. */
    public static function toolProvidersHook(string $slug): string
    {
        return 'bitapps_ai_tool_providers_' . self::token($slug);
    }

    /** Action: fired once a plugin's kit has booted, with the Kit instance. */
    public static function bootedHook(string $slug): string
    {
        return 'bitapps_ai_kit_booted_' . self::token($slug);
    }

    /** Abilities API category for a plugin's tools, e.g. `bitapps-bit-form`. */
    public static function abilityCategory(string $slug): string
    {
        return 'bitapps-' . self::token($slug);
    }

    /**
     * Abilities API name for a tool: `<slug>/<tool>`, lowercase, hyphens only.
     *
     * Core accepts only `^[a-z0-9-]+/[a-z0-9-]+$`, while MCP tool names may carry
     * underscores (Bit PI's `list_flows`, Bit CRM's `create_invoice`), so the MCP
     * name is kept as-is and this derived name is used for the Abilities mirror.
     */
    public static function abilityName(string $slug, string $toolName): string
    {
        return self::token($slug) . '/' . self::token($toolName);
    }

    /** admin-post action of a plugin's OAuth consent screen, e.g. `bitform_mcp_authorize`. */
    public static function consentAction(string $slug): string
    {
        return str_replace('-', '', self::token($slug)) . '_mcp_authorize';
    }

    /** MCP Adapter server id for a plugin's bridge server. */
    public static function adapterServerId(string $slug): string
    {
        return 'bitapps-' . self::token($slug);
    }

    /**
     * A string safe for hook suffixes, ability names and option keys: lowercase,
     * `[a-z0-9-]`, no leading/trailing or doubled hyphens.
     */
    public static function token(string $value): string
    {
        $token = strtolower($value);
        $token = (string) preg_replace('/[^a-z0-9]+/', '-', $token);

        return trim((string) preg_replace('/-{2,}/', '-', $token), '-');
    }
}
