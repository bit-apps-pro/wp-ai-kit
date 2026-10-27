# Adopting wp-ai-kit in a Bit plugin

> Audience: developers of Bit CRM, Bit PI (Bit Flows) and future Bit plugins.
> Bit Form is the reference consumer; its adoption is in the Bit Form repository
> (`backend/app/Providers/AiProvider.php`). Start with the README's quick start;
> this document goes deeper.

## 1. Decide the tier

The kit is a library. Require it from the `composer.json` of the tier that should
expose MCP/AI: Bit Form requires it in **Free** (Pro adds tools through a filter);
Bit CRM Pro and Bit PI Pro already run MCP in **Pro**, so they require it there.
A Pro add-on that owns its own copy of the kit types against the kit directly; a Pro
add-on that must not bundle it (Bit Form Pro) talks to Free through a Free-published,
array-based facade and never names the kit's prefixed classes.

## 2. Boot

See `README.md`. Build one `PluginContext` from the plugin's own config: slug,
name, version, option prefix, REST namespace, capability callbacks, scope map,
URLs and optional services (`oauth_storage`, `audit`, `conversations`, `pii_classifier`, `brand`,
`assistant_persona`; the README lists them all).

## 3. Contribute tools

Implement `Tools\ToolProviderInterface` per domain group; `definitions()` returns
`ToolDefinition` objects or arrays for `ToolDefinition::fromArray()`:

```php
[
    'name'           => 'list-forms',                 // MCP name; existing names may keep underscores
    'title'          => 'List forms',
    'description'    => 'Written for the model: what it returns and when to use it.',
    'input_schema'   => ['properties' => ['form_id' => ['type' => 'integer']], 'required' => ['form_id']],
    'annotations'    => ['readonly' => true],          // or destructive / idempotent
    'capability'     => 'manage_bitform',               // optional; any of a list suffices
    'scope'          => null,                           // optional plugin scope; null = read/write/destructive
    'personal_data'  => false,                          // true → masked unless the grant allows it
    'resource_check' => fn (array $args) => true,       // may this user touch that record?
    'meta'           => ['resource_argument' => 'form_id'],
    'handler'        => fn (array $args, $context) => [...],
]
```

Append providers on `bitapps_ai_tool_providers_<slug>`. Handlers return arrays,
strings, `WP_Error`, `ToolResult` or an MCP result array. Every call runs through
`ToolRunner`: validation and coercion, guard, rate limits, confirmation for
destructive tools, credential redaction, personal-data masking, audit. Wrap
visitor-written text with `Security\UntrustedContent::wrap()`.

## 4. Transport, auth and settings

- `Kit::boot()` registers the MCP route `POST <rest_namespace>/mcp` on
  `rest_api_init`, only when `<prefix>mcp_settings.enabled` is true.
- Application Passwords work out of the box (WordPress authenticates them before
  the kit sees the request). API keys: set `api_keys` in the settings and use
  `Kit::apiKeys()`.
- OAuth (claude.ai and ChatGPT connectors, Cursor, VS Code remote): set `oauth`
  in the settings (needs the MCP server on and pretty permalinks). The kit then
  serves `/.well-known/oauth-protected-resource/<resource path>` and
  `/.well-known/oauth-authorization-server/<resource path>`, the routes
  `<ns>/mcp/oauth/{authorize,token,register,revoke}`, and the consent screen at
  `admin-post.php?action=<slug without hyphens>_mcp_authorize`. Grants are
  scoped `read` / `write` / `destructive` (the user picks at consent, never above
  the site's access level) and may allow personal data. List and revoke them with
  `Kit::oauthConnections()` / `Kit::revokeOAuthConnection($id)`; deleting a user
  revokes theirs. Storage: two non-autoloaded options by default; pass a
  `services.oauth_storage` implementing `Mcp\OAuth\OAuthStorageInterface` to keep
  existing rows or use a table. `services.brand` colours the consent screen:
  `color` (accent: name, buttons, radios), `background` (page), and
  `color_dark` / `background_dark` for visitors in dark mode. Hex values only;
  a missing key falls back to `ConsentScreen::DEFAULT_PALETTE` (`color_dark` to
  your `color` first), and the button text turns black or white to stay readable
  on the accent.
- A settings screen reads `Kit::adminState()` and saves through
  `Kit::saveMcpSettings($input)`; the kit validates every key. With the context
  feature `rest_ui` the kit serves all of this over REST (`<ns>/ai/*`, README
  "Admin REST routes") and the plugin writes no controllers: it localizes
  `Kit::uiBootstrap()` for its UI and hooks `chatContextHook` / `chatViewHook`
  for what is its own (Bit Form: `Providers\AiProvider::chatContext()` and
  `chatView()`).
- On WordPress 6.9+ the tools are mirrored as abilities (category
  `bitapps-<slug>`), and on sites with the MCP Adapter plugin they also appear on
  the server `bitapps-<slug>` at `<rest_namespace>/mcp-adapter`.

### AI assistant

`Kit::assistant()->chat($userId, $message, $pageContext)`, `->decide($userId, $approve)`,
`->reset($userId)` and `->view($userId)` drive an in-admin chat; `Kit::assistantState()` and
`Kit::saveAiSettings($input)` back its settings screen. The engine is the WordPress AI Client when the site
set up a provider in WordPress Settings → Connectors (WordPress 7.0+), otherwise the plugin's saved key for Claude,
OpenAI or Gemini. Tools run through the same `ToolRunner` as MCP, on channel `assistant`, with the access level
from `<prefix>ai_settings`; destructive tools return a `pending` action the user approves or cancels in the UI
(never the model). Build the page-context sentence on the server from ids, not from browser text. Supply
`services.assistant_persona` for plugin-specific guidance. Bit CRM can replace its `AgentLoop`,
`ToolDispatcher` and providers with this; streaming (SSE) is not in the kit yet.

### Storage

Pass `'storage' => PluginContext::STORAGE_TABLES` for real chat history and a searchable activity log in the
kit's own tables; call `Kit::for($slug)->installTables()` on activation, `deactivate()` on deactivation and
`uninstallTables()` on uninstall (README, "Tables"). Only the tables for the parts the plugin ships are
created. Leave it at `options` (the default) for a small footprint: one conversation per user and the last
200 log events. A plugin that already has tables passes them as the `conversations` / `audit` services.

### Leaving parts out

List what the plugin does not ship in `features` (README, "Choosing features"): `audit` (no activity log),
`history` (no earlier-chats list), `oauth`, `api_keys`, `abilities`, `mcp_adapter`. Nothing else is needed:
the kit registers no hooks or routes for them and the UI package hides them. Use the runtime switches
(settings, constants, `bitapps_ai_feature_disabled`) only for parts a site owner should be able to turn off.

### Admin UI (`@bitapps/wp-ai-kit-ui`)

1. Boot with `PluginContext::FEATURE_REST_UI => true` and localize `Kit::uiBootstrap()` under the plugin's
   script variable (Bit Form: `window.bits.aiKit`).
2. `pnpm add @bitapps/wp-ai-kit-ui` (peer: React 18.3+ or 19; antd 5 only for the adapter). With a linked
   checkout during development, add `resolve: { dedupe: ['react', 'react-dom'] }` to the Vite config.
3. Wrap the AI screens in `<AiKitProvider bootstrap={…} i18n={{ __ }}>`:
   - **antd plugin (Bit CRM, Bit PI, Bit Connect):** `components={antdComponents}` from
     `@bitapps/wp-ai-kit-ui/antd`, and spread `useAntdTheme()` on the root element's `style`.
   - **Own design system (Bit Form):** a partial slot map (Button, Switch, Badge, …); unmapped slots keep the
     plain defaults. Theme with `--aikit-*` tokens on the app root.
4. Mount the parts in the plugin's own containers: `AssistantChat` in a dock, drawer or floating `Panel`;
   `AssistantSettingsForm` and `McpSettings` on settings pages (route-driven tabs through `tab` /
   `onTabChange`).
5. Keep the components out of the plugin's main bundle: import them only from lazily loaded screens
   (`/components` is about 33 kB brotli). One store can be shared between lazy chunks (`AiKitProvider`'s
   `store` prop) so saving settings updates an open chat.
6. Merge `node_modules/@bitapps/wp-ai-kit-ui/i18n/strings.en.json` into the plugin's translation template.
7. Add what is the plugin's through props and hooks, never forks: `context`, `mentions`, `commands`,
   `renderPreviewBar`, `onEvent` in the chat; `chatContextHook` / `chatViewHook` on the server.

### Reference: Bit Form

- `bitform/backend/app/Providers/AiProvider.php`: boot, context, the provider filter, `chatContext()`,
  `chatView()`, `uiBootstrap()`.
- `backend/app/Ai/Tools/*`: providers over the ORM models.
- `backend/app/Ai/{AuditLogger,ConversationRepository}.php`: table-backed services.
- `frontend-dev/src/components/AiKit/*`: provider, slot map and token bridge.
- `frontend-dev/src/components/AiAssistant/AssistantPanel.tsx`: the docked chat with the builder preview.

Bit Form Pro adds tools through Free's own `bitform_ai_tool_providers` filter and Free's `Ai\ToolProvider`
facade, so it never names the kit's prefixed classes.

## 5. Migration paths

### Bit PI Pro (replaces `pro/backend/app/src/McpServer/*`)

Keep: route `bit-pi/v1/mcp`, `.well-known` documents under the resource path,
options `bit_pi_pro_mcp_oauth_clients`, `bit_pi_pro_mcp_oauth_tokens`,
`bit_pi_pro_mcp_api_key`, `bit_pi_pro_mcp_server_settings`, token prefixes
`bfmcp_at_` / `bfmcp_rt_` / `bfmcp_key_`, client ids `bfmcp_`, scopes
`flow:read … settings:write`, authorization-code TTL 300 s, and the rule that a
token record without `scopes` means full access. The kit's default `OptionsOAuthStorage`
cannot read these records as they are: PI stores one row per token (access and
refresh separately, `type`, `scopes` array). Write an `OAuthStorageInterface`
adapter that presents PI's rows as kit grants (pair access+refresh rows by
`client_id` + `wp_user_id` + issue time, map scopes with `scope_map`) or let
existing PI connections sign in once more after the switch. There are no MCP/OAuth tests in
Bit PI today; write fixtures for token, API-key and option migration before
switching. Then delete the duplicated server, OAuth and controller classes and turn
`Tools/*Tools.php` into kit providers.

### Bit CRM Pro (replaces `pro/backend/app/Services/Mcp/*`, `OAuth/*`, `Services/Ai/*`)

Keep: REST namespace `bit-crm-sales-marketing-automation`, settings-table rows
`mcp_oauth_clients` / `mcp_oauth_tokens` (one record per access-token hash with
`refresh_hash`, `access_expires`, `refresh_expires`), unprefixed tokens, client ids
`bitcrm_`, authorization-code TTL 60 s, scopes `mcp read write offline_access`,
capability `bit_crm_mcp_server` as `can_use`. Drop the root `.well-known` rewrite
(the site root is a single slot shared by every plugin); keep the path-suffixed
documents. The kit enforces scopes on `tools/call` (today a read-only grant can
write) and sends `X-Frame-Options`/`frame-ancestors` on the consent page. Port
`tests/Settings/McpOAuthTest.php` expectations onto the kit's suite (done: every
case is covered by `tests/Mcp/OAuthServerTest.php`). CRM's token rows are already
the kit's grant shape: an `oauth_storage` service that reads and writes the
settings rows `mcp_oauth_clients` / `mcp_oauth_tokens` keeps every live
connection; rows without `id` get one derived from their access digest. Map
`mcp` → `write` with `scope_map`. The consent URL changes from `/bit-crm-authorize`
to admin-post.php; clients pick it up from the metadata on their next sign-in. The AI
providers, streaming and agent loop move into the kit's `Ai\*`; `SystemPrompt`,
`SummaryBrief` and the chat UI stay in CRM.

## 6. Checklist

- [ ] Kit required from the right tier, prefixed (Imposter or Strauss), no `path` repository in the release build.
- [ ] `PluginContext` with a final `slug` (it keys abilities and encrypted keys) and the plugin's `can_use`.
- [ ] Boot wrapped so an exception cannot take the plugin down.
- [ ] Tool providers on `bitapps_ai_tool_providers_<slug>`: model-readable descriptions, honest
      `readonly` / `destructive` annotations, `personal_data` where entries are returned, a
      `resource_check` where a user may reach only some records.
- [ ] `features` lists what the plugin does not ship (activity log, history, OAuth, API keys, bridges).
- [ ] Settings screens: `@bitapps/wp-ai-kit-ui` over `rest_ui`, or the plugin's own on `adminState()` /
      `saveMcpSettings()` and `assistantState()` / `saveAiSettings()`, behind the plugin's capability check.
- [ ] `can_use` is a staff capability, never subscribers; `manage_cap` is the site administrators'.
- [ ] `storage` chosen; with `tables`, `installTables()` / `deactivate()` / `uninstallTables()` wired to
      activation, deactivation and uninstall.
- [ ] Uninstall deletes the kit's options and user meta (README, "What the kit stores").
- [ ] Privacy: the plugin's privacy exporters/erasers cover conversations and the audit log if it keeps them.
- [ ] Tested with an Application Password (`curl … tools/list`) and one OAuth app (Claude.ai or ChatGPT).
