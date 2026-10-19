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
  registration, refresh-token rotation, revocation, RFC 8414 / 9728 discovery) with
  its own consent screen;
- bridges to the WordPress-native pieces **when they exist**: the Abilities API
  (core 6.9+) as a mirror of the tools, the WordPress MCP Adapter plugin as an
  additional server, and the core AI Client (7.0+) as the assistant engine — with
  built-in Anthropic Claude, OpenAI and Google Gemini engines as fallback;
- an in-admin AI assistant (agent loop, conversation history, per-call confirmation)
  that runs the same tools.

Requirements: PHP ≥ 8.0, WordPress functions available at runtime. **No Composer
dependencies.** Not a plugin: it is a library a plugin requires and prefixes. The kit
has no admin UI; the plugin builds its settings screens on the kit's PHP API.

## Install (in a plugin)

```bash
composer require bitapps/wp-ai-kit
```

Require a released version (`^1.1`; see `CHANGELOG.md`) rather than `dev-main`.

Each plugin prefixes the kit into its own namespace, like `bitapps/wp-kit`, so every
plugin carries an isolated copy and two plugins on different kit versions coexist.
With Imposter (Bit Form):

```json
"extra": {
  "imposter": { "namespace": "BitApps\\MyPlugin\\Deps\\" }
}
```

The kit then answers as `BitApps\MyPlugin\Deps\BitApps\AiKit\…`. Strauss works the same
way. Never reference another plugin's copy: plugins meet only through WordPress hooks,
option names and Abilities names (`src/Contract.php`). Do not build a release from a
Composer `path` repository: it copies the checkout's own `vendor/` into the plugin.

## Quick start

### 1. Boot

```php
use BitApps\MyPlugin\Deps\BitApps\AiKit\Kit;          // the prefixed name in your plugin
use BitApps\MyPlugin\Deps\BitApps\AiKit\PluginContext;

add_action('plugins_loaded', static function (): void {
    Kit::boot(PluginContext::create([
        'slug'           => 'bit-form',
        'name'           => 'Bit Form',
        'version'        => BITFORMS_VERSION,
        'option_prefix'  => 'bitforms_',
        'rest_namespace' => 'bitform/v1',
        'can_use'        => static fn ($userId) => user_can($userId, 'manage_bitform') || user_can($userId, 'manage_options'),
    ]));
});
```

The slug names the plugin's abilities (`bitform/list-forms`, which AI clients show as
`wp_ability_bitform_list_forms`) and keys the encryption of stored provider API keys, so
pick it once: changing it after a release orphans the saved keys. Wrap the boot in a
`try`/`catch (\Throwable)` if a broken AI layer must never take the plugin down.

### 2. Add tools

A provider is a `Tools\ToolProviderInterface` (`definitions(): array`) or simply an array
of tool specs:

```php
add_filter('bitapps_ai_tool_providers_bitform', static function ($providers) {
    $providers[] = [[
        'name'          => 'list-forms',
        'title'         => 'List forms',
        'description'   => 'Lists the forms with their IDs and entry counts. Call this first to find a form ID.',
        'input_schema'  => ['properties' => ['status' => ['type' => 'string', 'enum' => ['active', 'inactive']]]],
        'annotations'   => ['readonly' => true],
        'capability'    => 'manage_bitform',
        'handler'       => static fn (array $args, $call) => MyFormService::list($args['status'] ?? null),
    ]];

    return $providers;
});
```

- `description` is read by the model: say what the tool returns and when to use it.
- `input_schema` is JSON Schema; arguments are validated and coerced before the handler runs.
- `annotations`: `readonly`, `destructive` (asks the user to confirm first) or `idempotent`.
  Tools without `readonly` need the site's "read and write" access level.
- `personal_data => true` masks emails, phones, names and addresses unless the site or
  the OAuth grant allows them.
- The handler gets the validated `$args` and a `Tools\ToolContext` (`$call`: user,
  channel `mcp`/`assistant`/…), and returns an array, a string, a `WP_Error` or a
  `ToolResult`. Wrap visitor-written text with `Security\UntrustedContent::wrap()`.

`docs/ADOPTION.md` §3 lists every key (`scope`, `resource_check`, `meta`, …).

### 3. Switch it on

Everything is off until the site enables it, so give the site admin a settings screen
(see [Settings screens](#settings-screens)). To try it before the screen exists:

```php
Kit::for('bitform')->saveMcpSettings(['enabled' => true]);
```

### 4. Check it

The MCP endpoint is `rest_url('<rest_namespace>/mcp')`. With an Application Password:

```bash
curl -s -u 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' https://example.com/wp-json/bitform/v1/mcp
```

Then add the URL to an AI app (Claude, ChatGPT, Cursor, VS Code, …). With OAuth on
(the default once MCP is enabled; needs pretty permalinks) the app opens the kit's
consent screen on the site and the user approves it there.

## Settings screens

The plugin owns the UI; the kit owns validation and storage. Call these on
`$kit = Kit::for('bitform')` from the plugin's own admin endpoints, which check the
user's capability first:

| Screen         | Read                                                                            | Save / act                                                        |
| -------------- | ------------------------------------------------------------------------------- | ----------------------------------------------------------------- |
| MCP server     | `$kit->adminState()` (settings, tools, endpoint, requirements, OAuth, API keys) | `$kit->saveMcpSettings($input)`                                   |
| Connected apps | `$kit->oauthConnections()`                                                      | `$kit->revokeOAuthConnection($id)`, `revokeAllOAuthConnections()` |
| API keys       | `$kit->adminState()['api_keys']`                                                | `$kit->createApiKey(...)`, `$kit->apiKeys()->revoke(...)`         |
| AI assistant   | `$kit->assistantState()`                                                        | `$kit->saveAiSettings($input)`                                    |
| Chat panel     | `$kit->assistant()->view($userId)`, `history()`                                 | `chat()`, `decide()`, `reset()`, `open()`, `forget()`             |

Unknown keys are dropped and every value is validated, so the input can be posted as-is.

## Context reference

Required `PluginContext::create()` keys: `slug`, `name`, `version`, `option_prefix`
(lowercase `[a-z0-9_]`), `rest_namespace`. Optional:

| Key                                   | Purpose                                                                                          |
| ------------------------------------- | ------------------------------------------------------------------------------------------------ |
| `text_domain`                         | Defaults to the slug.                                                                            |
| `can_use`                             | `fn (int $userId): bool` — may this user use AI/MCP at all. Default `manage_options`.            |
| `can`                                 | `fn (string $capability, ...$args): bool` for per-tool capabilities. Default `current_user_can`. |
| `scope_map`                           | Legacy scope strings → `read` / `write` / `destructive`.                                         |
| `logger`                              | `fn (string $level, string $message, array $context)`. Default: `error_log` under `WP_DEBUG`.    |
| `urls`                                | Named admin URLs, e.g. `settings`.                                                               |
| `instructions`                        | What the MCP server tells clients about the tools.                                               |
| `description`, `website_url`, `icons` | How MCP clients show the server (below).                                                         |
| `features`                            | Which parts the plugin ships (below).                                                            |
| `services`                            | Plugin-supplied pieces, all optional (below).                                                    |

| Service             | Purpose                                                                              |
| ------------------- | ------------------------------------------------------------------------------------ |
| `audit`             | `Audit\AuditLoggerInterface`; default keeps the last events in an option.            |
| `conversations`     | `Ai\ConversationRepositoryInterface`; default: user meta, one conversation per user. |
| `oauth_storage`     | `Mcp\OAuth\OAuthStorageInterface`; default: two options.                             |
| `pii_classifier`    | `callable` that marks fields as personal data for masking.                           |
| `brand`             | Consent screen colours: `color`, `background`, `color_dark`, `background_dark`.      |
| `assistant_persona` | Plugin-specific guidance added to the assistant's system prompt.                     |
| `api_key_prefix`    | Prefix of issued API keys; default `<slug>_key_`.                                    |

`audit`, `conversations` and `oauth_storage` may be given as a factory
(`fn () => new MyAuditLogger()`), so they are built on first use. Boot itself only
registers hooks; the MCP routes build the server and the tool registry when a request
reaches them.

### How MCP clients see the server

The MCP `initialize` reply names the server with `slug`, `name` and `version`. Optional context keys add
what MCP 2025-11-25 clients may display: `description`, `website_url` and `icons`. An icon is
`['src' => <https or data:image URI>, 'mimeType' => 'image/png', 'sizes' => ['256x256'], 'theme' => 'light'|'dark']`
(all but `src` optional). Use a PNG or JPEG, as every client that shows icons must support those. An invalid URL
or icon is left out, not an error:

```php
PluginContext::create([
    // …
    'description' => 'Forms, entries, notifications and workflows of the Bit Form plugin on this WordPress site.',
    'website_url' => 'https://bit-form.com',
    'icons'       => [['src' => plugins_url('static/bitform-icon.png', __FILE__), 'mimeType' => 'image/png', 'sizes' => ['256x256']]],
]);
```

### Shipping only part of the kit

A plugin that wants only the assistant, or only the MCP server, says so at boot. A left-out feature registers
no hooks, loads none of its classes and cannot be switched on by settings:

```php
PluginContext::create([
    // …
    'features' => [PluginContext::FEATURE_MCP => false],   // assistant only
]);
```

## Hooks

| Hook                               | Type   | Use                                                                             |
| ---------------------------------- | ------ | ------------------------------------------------------------------------------- |
| `bitapps_ai_tool_providers_<slug>` | filter | Add tool providers (`$providers`, `PluginContext`).                             |
| `bitapps_ai_kit_booted_<slug>`     | action | Runs once the kit has booted, with the `Kit`.                                   |
| `bitapps_ai_feature_disabled`      | filter | `(bool $disabled, string $feature, string $slug)`: a host forces a feature off. |
| `bitapps_ai_secret_keys`           | filter | Extra key names to redact from tool output.                                     |
| `bitapps_ai_allowed_origins`       | filter | Browser origins allowed to call the MCP endpoint.                               |
| `bitapps_ai_client_ip`             | filter | The client IP for rate limits behind a proxy or CDN.                            |

Hosts can also force features off in `wp-config.php` with `<PREFIX>MCP_DISABLED` /
`<PREFIX>AI_DISABLED`, where the prefix is the upper-cased option prefix
(`BITFORMS_MCP_DISABLED`).

## What the kit stores

All names start with the plugin's `option_prefix`:

- options `mcp_settings` and `ai_settings` (autoloaded, small), `ai_credentials`
  (encrypted provider keys), `mcp_api_keys`, `mcp_oauth_clients`, `mcp_oauth_grants`,
  `ai_audit_log` (unless `audit` is replaced);
- user meta `ai_conversation` and `ai_conversation_active` (`_<blog id>` added on
  multisite), unless `conversations` is replaced;
- short-lived transients for rate limits, OAuth codes and confirmations.

Delete them in the plugin's uninstall routine. Provider keys are encrypted with the
site's salts; define `AUTH_KEY` and friends in `wp-config.php`.

## Documentation

- `docs/ADOPTION.md` — tool spec reference, transport and OAuth details, the
  assistant API, and the migration paths for Bit PI Pro and Bit CRM Pro, which
  already ship their own MCP servers.
- `CONTRIBUTING.md` — the prefixing-safety rules every contribution must follow.
- `CHANGELOG.md` / `UPGRADING.md` — what changed per version, and breaking changes.

Versions follow SemVer: within a major version changes are additive, so `^1.1`
is safe to update.

## Development

```bash
composer install
composer check      # lint + PHP 8.0 compatibility + prefix-safety + rector + tests
```

Tests run on plain PHP with WordPress stubs (`tests/bootstrap.php`); no WordPress
install is needed.

## License

GPL-2.0-or-later.
