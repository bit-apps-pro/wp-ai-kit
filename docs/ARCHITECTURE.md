# Architecture

> Audience: developers changing the kit, and plugin developers who want to know what
> runs where before adopting it. For adopting it, read `ADOPTION.md`; for the rules
> every change must follow, `../CONTRIBUTING.md`.

The kit is two artifacts built from one repository:

| Artifact | Where | Distributed as | Consumed by |
| --- | --- | --- | --- |
| PHP library `bitapps/wp-ai-kit` | `src/` | Composer (Packagist), prefixed into each plugin | the plugin's PHP |
| UI package `@bitapps/wp-ai-kit-ui` | `frontend/` | npm, bundled by each plugin's build | the plugin's admin React app |

Both speak one wire contract: `Contract::CONTRACT_VERSION` in PHP and `"contract"` in
`frontend/package.json`. They share a major.minor version (`docs/RELEASING.md`).

## 1. Layers

```
Plugin (Bit Form, Bit CRM, …)
 ├─ PluginContext ─────────────── slug, prefixes, capabilities, features, services
 ├─ tool providers (filter) ───── its domain tools
 └─ host UI (React) ───────────── containers, routes, slot map, theme tokens
        │                                   │
        ▼                                   ▼
Kit (PHP, src/)                      UI package (frontend/)
 ├─ Tools/      registry, runner,     ├─ core/        transport, store, hooks, bus
 │              policy, validation    ├─ components/  chat, history, settings, MCP
 ├─ Mcp/        server, transport,    ├─ antd/        optional slot map
 │              OAuth, API keys       └─ styles/      --aikit-* tokens
 ├─ Ai/         assistant, agent
 │              loop, engines
 ├─ Rest/       admin REST routes ◄──────── fetch + X-WP-Nonce
 ├─ Audit/      activity log
 ├─ Security/   rate limits, masking,
 │              redaction, secrets
 └─ Settings/   settings, feature
                flags, requirements
```

- **`Kit`** (`src/Kit.php`) is the one object a plugin holds. `Kit::boot()` registers
  WordPress hooks only; every heavier object (registry, MCP server, OAuth server,
  assistant, REST controllers) is built when a request needs it.
- **`PluginContext`** carries everything plugin-specific. Kit code never reads a
  plugin's config, options or capabilities directly. Several plugins can each run
  their own prefixed copy of the kit on one site without sharing state.
- **`Contract`** names every cross-copy hook, option suffix and route base. Its
  version changes only when a payload or name scheme changes.

## 2. What each request runs

**MCP call** (`POST <ns>/mcp`):
1. WordPress authenticates an Application Password, or the kit authenticates an API
   key or OAuth bearer token.
2. `Mcp\Transport\HttpTransport` parses the JSON-RPC message, and `Mcp\Server\McpServer`
   answers `initialize` and `tools/list` or dispatches `tools/call`.
3. `Tools\ToolRunner` authorizes the call: access level, scope, the tool's
   `resource_check`, and rate limits. It then validates the arguments, asks for
   confirmation on destructive tools, runs the handler, masks personal data,
   redacts secrets and records an audit event.

**OAuth sign-in**:
1. Discovery comes from `/.well-known/oauth-*`, served on `init` at priority 0.
2. The client is registered through dynamic registration or a client metadata
   document.
3. `<ns>/mcp/oauth/authorize` sends the user to the consent screen at
   `admin-post.php?action=<slug>_mcp_authorize`.
4. `<ns>/mcp/oauth/token` exchanges the code (PKCE). Refresh tokens rotate and are
   revocable.

**Assistant turn** (`POST <ns>/ai/assistant/chat`):
1. `Rest\RestRoutes` checks the permission (signed in, `can_use`).
2. `Rest\AssistantController` passes the browser's `context` (ids only) through
   `chatContextHook`, where the plugin turns it into a sentence for the system prompt.
3. `Ai\Assistant` checks it is ready (switched on, engine, daily limit).
4. `Ai\AgentLoop` alternates model calls (`Ai\Engine\*` or `Ai\Providers\*`) with tool
   runs through the same `ToolRunner`, on the channel `assistant`.
5. A destructive or write tool stops the loop with a `pending` action. The user
   approves or cancels it through `assistant/decide`, never the model.
6. The conversation is stored server-side (`Ai\ConversationStore`). The reply passes
   through `chatViewHook`, where the plugin adds its own keys.

**Settings screen**:
1. `GET <ns>/ai/settings/{assistant,mcp}` returns `Kit::assistantState()` or
   `Kit::adminState()`, filtered to what the user may see.
2. `POST` validates through `AiSettings` or `McpSettings` (manage capability only),
   then fires `settingsSavedHook`.

## 3. The UI package

- **`core/`** has no DOM.
  - `createRestTransport()` maps actions such as `assistant/chat` to the routes above.
  - `AiKitStore` holds the chat, history, settings and MCP state, read through
    `useSyncExternalStore` selectors.
  - Hooks (`useAssistant`, `useConversations`, `useAiSettings`, …) read the store.
  - `aiKitBus(slug)` lets code outside React open the chat.
- **`components/`** are composed from **slots** (Button, Switch, Select, Modal, …)
  with plain HTML defaults. A host maps the slots to its design system: antd through
  `/antd`, Bit Form to its own components. Styling is `--aikit-*` tokens on rules of
  one-class specificity, so host CSS wins without `!important`.
- **The host keeps the shell**: containers (dock, drawer, page), routes, menus, Pro
  gating and anything domain-specific. Bit Form's builder preview, for example, uses
  `renderPreviewBar` and `onEvent`.

The bootstrap (`Kit::uiBootstrap()`) is the UI's starting point: the REST base, the
nonce, what the user may do, which features the plugin ships, the engine and the brand.

## 4. Features

`PluginContext` `features` says what a plugin **ships**:

- `mcp` and `assistant` select the two halves.
- `rest_ui` selects the admin routes.
- `audit`, `history`, `oauth`, `api_keys`, `abilities` and `mcp_adapter` are the
  optional parts.

A part left out registers no hooks and no routes, and settings cannot switch it on. The
UI hides it, reading `bootstrap.features` through `featureOn()`.

`Settings\FeatureFlags` then decides what is **on** at run time:

- the plugin ships the part;
- no `<PREFIX>_MCP_DISABLED` / `<PREFIX>_AI_DISABLED` constant is set;
- the `bitapps_ai_feature_disabled` filter does not say off;
- the saved settings say on, or the default does.

## 5. Storage

By default (`storage => options`) the kit creates **no tables**. It keeps:

- **Options**, all named with the plugin's `option_prefix`:
  - settings: `mcp_settings`, `ai_settings`
  - provider keys, encrypted: `ai_credentials`
  - MCP access: `mcp_api_keys`, `mcp_oauth_clients`, `mcp_oauth_grants`
  - the activity log, the last 200 events: `ai_audit_log`
- **User meta** for one conversation per user: `ai_conversation`, `ai_conversation_active`.
- **Transients** for rate limits, OAuth codes and confirmations.

With `storage => tables` the growing data moves to the kit's own tables, on the Bit Apps ORM
(`bitapps/wp-database`, the one Composer dependency, which every consumer prefixes with the kit):

- `Storage\Tables` decides which tables a plugin gets from what it ships, runs `Storage\Migrations` (Schema
  builder, `CREATE TABLE IF NOT EXISTS`), stamps `{option_prefix}aikit_db_version`, and drops the tables on
  uninstall.
- `Storage\Model\Conversation` and `Storage\Model\AuditEvent` are one model per table. `StorageModel` gives
  each instance its own table prefix (`for($optionPrefix)`), because the ORM's `Connection` prefix belongs to
  the host plugin and must not change. No automatic timestamps (UTC written by hand), no JSON casts, no model
  events; writes check `last_error`.
- `Storage\TableConversationRepository` and `Storage\TableAuditLogger` implement the store interfaces on
  those models, falling back to user meta and the option until a table exists.

A plugin can also pass its own services, which win over `storage`:

| Service | Interface | Bit Form's implementation |
| --- | --- | --- |
| `audit` | `Audit\AuditLoggerInterface` (+ `SearchableAuditLog`) | table `{prefix}bitforms_ai_audit_log` |
| `conversations` | `Ai\ConversationRepositoryInterface` (+ `ConversationQueryInterface`) | table `{prefix}bitforms_ai_conversations` |
| `oauth_storage` | `Mcp\OAuth\OAuthStorageInterface` | (options) |

## 6. Where to add things

| Change | Goes in |
| --- | --- |
| A new tool | the plugin, through its tool provider; never the kit |
| A new MCP or OAuth behaviour | `src/Mcp/*`, with a test in `tests/Mcp` |
| A new admin route | `Rest\RestRoutes::table()`, its controller, `RestRoutes::requires()` for the feature it belongs to, `frontend/src/core/transport.ts`, and the README route table |
| A schema change on a kit table | a new `Storage\Migrations\*` step (never edit a released one), listed in `Tables::migrations()`, and a `Tables::SCHEMA_VERSION` bump |
| A new optional part | `PluginContext::FEATURE_*`, `FeatureFlags::OPTIONAL_PART`, the bootstrap `features`, `featureOn()` in the components |
| A new UI screen or component | `frontend/src/components`, exported from `components/index.ts`, strings through `useI18n()`, styles in `frontend/src/styles` |
| A new hook | `Contract` (name), README hook table, `CHANGELOG.md` |
