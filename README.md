# Bit Apps WP AI Kit

Shared MCP server and AI assistant infrastructure for Bit Apps WordPress plugins
(Bit Form, Bit CRM, Bit Flows/Bit PI, …). A plugin contributes **domain tools**;
the kit provides everything around them:

- the tool model (`ToolDefinition`, scopes, annotations, PII flags), a per-plugin
  registry fed through a WordPress filter, input validation, authorization and
  confirmation for destructive actions, rate limits, secret redaction, PII masking
  and an audit trail;
- an MCP server over Streamable HTTP (JSON-RPC 2.0; protocol revisions 2024-11-05 →
  2025-11-25) that works on **any WordPress version the plugin supports**;
- authentication: WordPress Application Passwords, kit-issued API keys, and an
  OAuth 2.1 authorization server (PKCE, Client ID Metadata Documents, dynamic client
  registration, refresh-token rotation, revocation, RFC 8414 / 9728 discovery);
- bridges to the WordPress-native pieces **when they exist**: the Abilities API
  (core 6.9+) as a mirror of every tool, the WordPress MCP Adapter plugin as an
  additional server, and the core AI Client (7.0+) as the assistant engine — with
  a built-in engine (Anthropic, OpenAI, Google, OpenAI-compatible) as fallback;
- an MCP client (Streamable HTTP + legacy SSE).

Requirements: PHP ≥ 8.0, WordPress functions available at runtime. **No Composer
dependencies.** Not a plugin: it is a library a plugin requires and prefixes.

## Install (in a plugin)

```bash
composer require bitapps/wp-ai-kit
```

Each plugin prefixes the kit into its own namespace (Imposter or Strauss, like
`bitapps/wp-kit`), so every plugin carries an isolated copy. Cross-plugin behaviour
goes only through WordPress hooks, option names and Abilities names — see
`src/Contract.php`.

## Boot

```php
use BitApps\MyPlugin\Deps\BitApps\AiKit\Kit;          // the prefixed name in your plugin
use BitApps\MyPlugin\Deps\BitApps\AiKit\PluginContext;

add_action('plugins_loaded', static function (): void {
    Kit::boot(PluginContext::create([
        'slug'           => 'bitform',
        'name'           => 'Bit Form',
        'version'        => BITFORMS_VERSION,
        'option_prefix'  => 'bitforms_',
        'rest_namespace' => 'bitform/v1',
        'can_use'        => static fn ($userId) => user_can($userId, 'manage_bitform') || user_can($userId, 'manage_options'),
    ]));
});

add_filter('bitapps_ai_tool_providers_bitform', static function (array $providers) {
    $providers[] = new \BitApps\BitForm\Ai\Providers\FormTools();
    return $providers;
});
```

The slug names the plugin's abilities (`bitform/list-forms`, which AI clients show as
`wp_ability_bitform_list_forms`) and keys the encryption of stored provider API keys, so
pick it once: changing it after a release orphans the saved keys.

### Shipping only part of the kit

A plugin that wants only the assistant, or only the MCP server, says so at boot. A left-out feature registers
no hooks, loads none of its classes and cannot be switched on by settings:

```php
PluginContext::create([
    // …
    'features' => [PluginContext::FEATURE_MCP => false],   // assistant only
]);
```

Services that are objects (`audit`, `conversations`, `oauth_storage`) can be passed as a factory
(`fn () => new MyAuditLogger()`), so they are built on first use. Boot itself only registers hooks; the MCP
routes build the server and the tool registry when a request reaches them.

Everything is off until the site enables it in the plugin's settings. Hosts can
force features off with `<PREFIX>MCP_DISABLED` / `<PREFIX>AI_DISABLED` constants.

## Documentation

- `docs/ADOPTION.md` — step-by-step adoption for a Bit plugin, including the
  migration paths for Bit PI Pro and Bit CRM Pro, which already ship their own
  MCP servers.
- `CONTRIBUTING.md` — the prefixing-safety rules every contribution must follow.
- `UPGRADING.md` — breaking changes between versions.

## Development

```bash
composer install
composer check      # lint + PHP 8.0 compatibility + prefix-safety + tests
```

Tests run on plain PHP with WordPress stubs (`tests/bootstrap.php`); no WordPress
install is needed.

## License

GPL-2.0-or-later.
