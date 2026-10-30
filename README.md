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
  built-in Anthropic Claude, OpenAI, Google Gemini and OpenAI-compatible (Mistral, Groq,
  OpenRouter…) engines as fallback, with streamed replies for Claude and OpenAI-style APIs;
- an in-admin AI assistant (agent loop, conversation history, per-call confirmation)
  that runs the same tools;
- optional admin REST routes and a React UI package, `@bitapps/wp-ai-kit-ui`
  (`frontend/`), with the chat panel, conversation history and the AI Assistant and
  MCP Server settings screens, themeable and with slots for the host's own components.

Requirements: PHP ≥ 8.0, WordPress functions available at runtime. **One Composer
dependency:** `bitapps/wp-database` (^2.0), the Bit Apps ORM every Bit plugin already ships, used only for
the optional tables. Not a plugin: it is a library a plugin requires and prefixes. The admin
UI is optional: mount the components from `@bitapps/wp-ai-kit-ui` (see
`frontend/README.md`), or build your own screens on the kit's PHP API.

## Install (in a plugin)

```bash
composer require bitapps/wp-ai-kit
```

Require a released version (`^1.3`; see `CHANGELOG.md`) rather than `dev-main`.

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

The kit owns validation and storage. With `rest_ui` and `@bitapps/wp-ai-kit-ui` the plugin writes no
endpoints ("Admin REST routes" below). A plugin with its own UI calls these on
`$kit = Kit::for('bitform')` from its own admin endpoints, which check the user's capability first:

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
| `storage`                             | `options` (default) or `tables`: where conversations and the activity log live ("What the kit stores"). |
| `manage_cap`                          | Capability for saving settings and touching other users' keys and connections. Default `manage_options`. |
| `services`                            | Plugin-supplied pieces, all optional (below).                                                    |

| Service             | Purpose                                                                              |
| ------------------- | ------------------------------------------------------------------------------------ |
| `audit`             | `Audit\AuditLoggerInterface`; default: the kit's table with `storage => tables`, else the last 200 events in an option. |
| `conversations`     | `Ai\ConversationRepositoryInterface`; default: the kit's table with `storage => tables`, else one conversation per user in user meta. |
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

### Choosing features

`features` says what the plugin **ships**. Everything is on unless listed as false, except `rest_ui`. A part
left out registers no hooks or REST routes, loads none of its classes, cannot be switched on by saved
settings, and the UI package hides it. No UI is needed to leave something out:

```php
PluginContext::create([
    // …
    'features' => [
        PluginContext::FEATURE_REST_UI => true,   // the kit's admin REST routes (off by default)
        PluginContext::FEATURE_AUDIT   => false,  // no activity log at all
        PluginContext::FEATURE_OAUTH   => false,  // MCP apps sign in with API keys or Application Passwords only
    ],
]);
```

| Key | Default | What it ships |
| --- | --- | --- |
| `FEATURE_MCP` (`mcp`) | on | the MCP server, its settings and screens |
| `FEATURE_ASSISTANT` (`assistant`) | on | the in-admin assistant, its settings and screens |
| `FEATURE_REST_UI` (`rest_ui`) | **off** | the admin REST routes `<ns>/ai/*` for `@bitapps/wp-ai-kit-ui` |
| `FEATURE_AUDIT` (`audit`) | on | the activity log: recording, its routes, the Activity tab and recent-activity cards, retention |
| `FEATURE_HISTORY` (`history`) | on | earlier chats: the history list, open, rename, pin, archive, delete (the current chat stays) |
| `FEATURE_OAUTH` (`oauth`) | on | OAuth sign-in for MCP apps, connected apps; OAuth-only apps leave the connect guide |
| `FEATURE_API_KEYS` (`api_keys`) | on | kit-issued MCP API keys |
| `FEATURE_ABILITIES` (`abilities`) | on | offering the tools as WordPress abilities (WordPress 6.9+) |
| `FEATURE_MCP_ADAPTER` (`mcp_adapter`) | on | listing the tools on the WordPress MCP Adapter plugin's server |
| `FEATURE_STREAM` (`stream`) | **off** | streamed replies: `POST <ns>/ai/assistant/stream` and `…/decide/stream` (server-sent events), the text shows while the model writes and each tool as it runs |
| `FEATURE_ATTACHMENTS` (`attachments`) | **off** | files attached to assistant messages (images, PDF, Word, text); see "Attachments" |

A shipped part can still be switched off at run time, without code in the plugin:

- the saved settings (the screens' switches);
- the constants `<PREFIX>_MCP_DISABLED` / `<PREFIX>_AI_DISABLED` in `wp-config.php`
  (`BITFORMS_MCP_DISABLED`); the AI constant covers the assistant and the activity log;
- the filter `bitapps_ai_feature_disabled`, `(bool $disabled, string $feature, string $slug)`, with
  `$feature` one of `mcp_server`, `oauth`, `api_keys`, `adapter_bridge`, `abilities_bridge`, `assistant`, `audit`.

## Admin REST routes (opt-in)

With `'features' => [PluginContext::FEATURE_REST_UI => true]` the kit registers `<rest_namespace>/ai/*`
for the chat panel and the settings screens, so the plugin writes no controllers:

| Route | What |
| --- | --- |
| `GET assistant/view`, `POST assistant/chat`, `POST assistant/decide`, `POST assistant/reset` | The chat panel; a failed turn answers 422 with the conversation view (`error`, `reason`) |
| `GET assistant/history?search&limit&offset&archived`, `POST assistant/conversations/{id}/open`, `PATCH …/{id}` (`title`, `pinned`, `archived`), `DELETE …/{id}` (or `all`) | Conversation history |
| `GET/POST settings/assistant`, `GET/POST settings/mcp` | The settings screens (save as the form posts, flat or under `settings`) |
| `POST assistant/stream`, `POST assistant/decide/stream` | As `assistant/chat` / `assistant/decide`, answered as server-sent events: `step`, `text` (`delta`), `tool` (`name`, `title`, `status`), then `done` (`status`, `view`). `stream` feature only |
| `POST assistant/attachments` (multipart `file`), `DELETE assistant/attachments/{id}` | Files for the next message (`attachments` feature only; also needs `upload_files`) |
| `GET mcp/state`, `GET/DELETE mcp/activity`, `GET/POST mcp/api-keys`, `DELETE mcp/api-keys/{id}`, `GET/DELETE mcp/connections[/{id}]` | The MCP screen |

Authentication is WordPress's cookie session with an `X-WP-Nonce` (`wp_rest`) header. Every route needs
the plugin's `can_use`; saving settings, clearing the log and other users' keys or connections need
`manage_cap` (`manage_options` by default). `Kit::uiBootstrap()` gives the UI the REST base, the nonce and
what the user may do; localize it under the plugin's own script variable.

What is the plugin's, the plugin adds through hooks (names from `Contract`): `chatContextHook` turns the
browser's `context` (ids such as the screen and record the user is on) into the sentence the system prompt
gets and the `about` stored with a new conversation — nothing the browser sent reaches the model otherwise;
`chatViewHook` adds to every reply (Bit Form puts its builder preview there); `conversationSwitchHook` fires
before reset/open/forget; `settingsSavedHook` after a save.

## Hooks

| Hook                               | Type   | Use                                                                             |
| ---------------------------------- | ------ | ------------------------------------------------------------------------------- |
| `bitapps_ai_tool_providers_<slug>` | filter | Add tool providers (`$providers`, `PluginContext`).                             |
| `bitapps_ai_kit_booted_<slug>`     | action | Runs once the kit has booted, with the `Kit`.                                   |
| `bitapps_ai_feature_disabled`      | filter | `(bool $disabled, string $feature, string $slug)`: a host forces a feature off. |
| `bitapps_ai_chat_context_<slug>`   | filter | `(array $context, int $userId, WP_REST_Request)`: set `summary` and `about` from the browser's `input`. |
| `bitapps_ai_chat_view_<slug>`      | filter | `(array $view, int $userId, WP_REST_Request)`: add to every chat reply (a preview, say). |
| `bitapps_ai_conversation_switch_<slug>` | action | `(int $userId, string $event, ?string $id)` before reset, open or forget. |
| `bitapps_ai_settings_saved_<slug>` | action | `(string $which, array $settings)` after a save through the kit. |
| `bitapps_ai_ui_bootstrap_<slug>`   | filter | `(array $bootstrap)`: what `Kit::uiBootstrap()` hands the UI. |
| `bitapps_ai_rest_permission_<slug>` | filter | `(bool\|WP_Error $allowed, string $route, WP_REST_Request, string $level)` on every kit REST route for a signed-in user (`$level`: `use` or `manage`). |
| `bitapps_ai_attachment_limits_<slug>` | filter | `(array $limits)`: file count, size caps per kind, text length, time to live and upload rate. |
| `bitapps_ai_secret_keys`           | filter | Extra key names to redact from tool output.                                     |
| `bitapps_ai_allowed_origins`       | filter | Browser origins allowed to call the MCP endpoint.                               |
| `bitapps_ai_client_ip`             | filter | The client IP for rate limits behind a proxy or CDN.                            |

Hosts can also force features off in `wp-config.php` with `<PREFIX>_MCP_DISABLED` /
`<PREFIX>_AI_DISABLED`, where the prefix is the upper-cased option prefix without its
trailing underscore (`BITFORMS_MCP_DISABLED`). See "Choosing features".

## Attachments (opt-in)

With `PluginContext::FEATURE_ATTACHMENTS => true` (and `rest_ui`), users can attach files to an assistant
message: PNG, JPEG, GIF and WebP images, PDF, Word (`.docx`) and plain text (`.txt`, `.md`, `.csv`).

- **Where they go.** Each upload is kept in `{uploads}/{option_prefix}aikit_attachments/{user id}/`, with
  `.htaccess`, `web.config` and `index.html` guards, under names keyed with the site's salt, so the id the
  browser holds does not give a file's path; the kit never hands out a URL to them. On nginx, which ignores
  `.htaccess`, add `location ~ /[^/]+aikit_attachments/ { deny all; }` for a hard block. They
  are removed after 24 hours by the hourly cron `{option_prefix}aikit_attachments_purge`, and with their
  conversation when it is deleted.
- **What is checked.** The extension must be on the list and agree with the file's content (`finfo`,
  `wp_check_filetype_and_ext`, image decoding, the `%PDF-` header); text with script markers is refused;
  a `.docx` must open as a Word document (PHP `zip` extension). Uploads are rate-limited per user.
- **What the model gets.** Images and PDFs go to the provider as native parts when the engine reads them
  (Claude, OpenAI, Gemini and the WordPress AI Client do); Word and text files go as text, marked as
  untrusted content. The conversation stores only the file's name, kind and size; a sent file the site no longer
  keeps shows as `expired` in the chat view (and in the chat).
- **Photo metadata.** JPEG, PNG and WebP files lose their EXIF (GPS position, camera), XMP, IPTC and text
  metadata when stored, without re-encoding; a JPEG keeps its orientation. Turn it off with
  `bitapps_ai_attachment_strip_metadata_<slug>` (`(bool $strip, string $extension)`).
- **Audit.** Uploads (also refused ones, with the reason) and removals are in the activity log as
  `attachment.uploaded` / `attachment.deleted`, with the attachment id; never the file's name or content.
- **Limits.** 5 files and 20 MB per message; images 5 MB, PDF 15 MB, Word 10 MB, text 1 MB and 200,000
  characters. `Kit::uiBootstrap()` sends the rules for the current engine (`attachments`), so the picker
  only offers what it can read. Change them with `bitapps_ai_attachment_limits_<slug>`.
- **Uninstall.** `Kit::removeSiteData()` removes the attachments folder with the rest of the kit's data.

MCP tools never see attachments.

## What the kit stores

All names start with the plugin's `option_prefix`:

- options `mcp_settings` and `ai_settings` (autoloaded, small), `ai_credentials`
  (encrypted provider keys), `mcp_api_keys`, `mcp_oauth_clients`, `mcp_oauth_grants`,
  `ai_audit_log` (unless `audit` is replaced);
- user meta `ai_conversation` and `ai_conversation_active` (`_<blog id>` added on
  multisite), unless `conversations` is replaced;
- short-lived transients for rate limits, OAuth codes and confirmations;
- with `attachments`, the folder `{uploads}/{option_prefix}aikit_attachments/` (files kept 24 hours).

### Tables (`storage => tables`)

With `'storage' => PluginContext::STORAGE_TABLES` the kit keeps chat history and the activity log in its own
tables, built with the Bit Apps ORM (`Storage\Migrations`, `Storage\Model`):

| Table | For | Created when the plugin ships |
| --- | --- | --- |
| `{wp_prefix}{option_prefix}aikit_conversations` | every conversation, searchable, pinned, archived | the assistant **and** `history` |
| `{wp_prefix}{option_prefix}aikit_audit_log` | every tool call, paged and filtered in SQL | `audit` (and MCP or the assistant) |

A part the plugin leaves out gets no table: `FEATURE_AUDIT => false` means no log table, ever. Runtime
switches (settings, constants, filters) do not create or drop tables.

```php
register_activation_hook(__FILE__, fn () => Kit::for('bit-connect')?->installTables());
register_deactivation_hook(__FILE__, fn () => Kit::for('bit-connect')?->deactivate());
// uninstall.php runs without the plugin booted: build the same context, no boot needed.
Kit::removeSiteData(MyPlugin::aiContext());   // everything below, tables included
```

Updates do not run activation hooks, so the kit also creates or upgrades the tables on the first request
after the schema or the shipped parts change, on any request (front end, REST, cron), with one autoloaded
option read otherwise. Until a table exists its store falls back to the option or user meta, so no chat
turn or log event is lost. A daily cron (`{option_prefix}aikit_purge`) deletes activity older than the MCP
setting `audit_retention_days` and conversations untouched for longer than the assistant setting
`history_days`. The ORM's shared plugin prefix is never changed: the kit's models carry their own.

A plugin with tables of its own (Bit Form: `{prefix}bitforms_ai_conversations` and
`{prefix}bitforms_ai_audit_log`) passes them as the `conversations` and `audit` services instead; those
win over `storage`.

### Uninstall

`Kit::removeSiteData(PluginContext $context, bool $dropTables = true)` removes everything above on the
current site: the options (`Kit::OWNED_OPTIONS`), each user's conversation user meta, the attachments folder,
the scheduled purges and, with `$dropTables`, the tables and their schema stamp. Transients are left to
expire. It needs no booted kit, only the plugin's context, so call it from `uninstall.php` or the uninstall
hook, once per site on a network. A plugin that lets users keep their data on uninstall passes its setting
as `$dropTables` (Bit Form: "delete tables"). The kit knows its own names, so an option a kit update adds is
removed without a change in the plugin. `$kit->uninstall($dropTables)` is the same on a booted kit.

Provider keys are encrypted with the site's salts; define `AUTH_KEY` and friends in `wp-config.php`.

## Documentation

- `docs/ADOPTION.md` — adopting the kit in a plugin, step by step: tools, transport and OAuth, the
  assistant, the admin UI, and the migration paths for Bit PI Pro and Bit CRM Pro.
- `docs/ARCHITECTURE.md` — how the PHP library, the REST routes and the UI package fit together, what
  each request runs, storage, and where new code goes.
- `docs/SECURITY.md` — who may call what, how tool calls are protected, what reaches the model, secrets.
- `docs/RELEASING.md` — versioning, cutting a release (Packagist and npm), upgrading a consumer.
- `frontend/README.md` — the UI package: install, components, hooks, theming, slots, translation.
- `CONTRIBUTING.md` / `frontend/CONTRIBUTING.md` — the rules every contribution follows.
- `CHANGELOG.md` / `UPGRADING.md` — what changed per version, and breaking changes.

Versions follow SemVer: within a major version changes are additive, so `^1.1`
is safe to update.

## Development

```bash
composer install
composer check      # lint + PHP 8.0 compatibility + prefix-safety + rector + tests

# the kit's tables against MySQL: point at a WordPress test library and its config (Bit Form's work)
WP_TESTS_DIR=../bitform/tests/__libs WP_TESTS_CONFIG_FILE_PATH=../bitform/tests.config.php composer test-integration

cd frontend && pnpm install
pnpm check          # types + tests + build + size budgets + string list + PHP↔UI contract
pnpm playground     # every component and layout against a mock backend
```

`composer check` runs on plain PHP with WordPress stubs (`tests/bootstrap.php`); no WordPress install is
needed. The integration tier (`tests/Integration`) needs one and skips without it.

## License

GPL-2.0-or-later.
