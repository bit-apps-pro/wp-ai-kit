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

    /** The kit's admin REST routes live under `<rest_namespace>/ai/...` (Rest\RestRoutes). */
    public const REST_BASE = '/ai';

    /**
     * Action (global, unprefixed): every booted kit may register a read-only lister
     * of its conversations, so a history screen can offer "All Bit apps". Payload:
     * `(array &$sources)` where each source is `['slug', 'name', 'lister' => callable(int $userId, array $args): array]`.
     */
    public const CONVERSATION_SOURCES_ACTION = 'bitapps_ai_conversation_sources';

    /**
     * Filter on every chat turn: `(array $context, int $userId, \WP_REST_Request $request)` → array.
     * `$context['input']` is what the browser sent (ids, never text the model should trust);
     * the plugin sets `summary` (the sentence the system prompt gets, e.g. which screen and
     * record the user is on) and `about` (stored with a new conversation, e.g. `['form_id' => 7]`).
     */
    public static function chatContextHook(string $slug): string
    {
        return 'bitapps_ai_chat_context_' . self::token($slug);
    }

    /** Filter: `(array $view, int $userId, \WP_REST_Request $request)` → the chat view a reply carries, e.g. plus a `preview`. */
    public static function chatViewHook(string $slug): string
    {
        return 'bitapps_ai_chat_view_' . self::token($slug);
    }

    /** Filter: `(array $bootstrap)` → what Kit::uiBootstrap() hands the admin UI. */
    public static function uiBootstrapHook(string $slug): string
    {
        return 'bitapps_ai_ui_bootstrap_' . self::token($slug);
    }

    /**
     * Filter: `(bool|\WP_Error $allowed, string $route, \WP_REST_Request $request, string $level)` on every
     * kit REST route for a signed-in user (`$level` is `use` or `manage`; the method is `$request->get_method()`).
     * Signed-out requests are refused before it runs.
     */
    public static function restPermissionHook(string $slug): string
    {
        return 'bitapps_ai_rest_permission_' . self::token($slug);
    }

    /** Action: `(string $which 'assistant'|'mcp', array $settings)` after settings were saved through the kit. */
    public static function settingsSavedHook(string $slug): string
    {
        return 'bitapps_ai_settings_saved_' . self::token($slug);
    }

    /**
     * Action: `(int $userId, string $event 'reset'|'open'|'forget', ?string $conversationId)` just before
     * the open conversation changes, so a plugin can drop state tied to it (a builder preview).
     */
    public static function conversationSwitchHook(string $slug): string
    {
        return 'bitapps_ai_conversation_switch_' . self::token($slug);
    }

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
