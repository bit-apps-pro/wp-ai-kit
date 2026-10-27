# Changelog

All notable changes to this package. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html): within a major version, changes are additive only.

## [Unreleased]

## [1.2.0] - 2026-10-27

### Added
- Optional parts a plugin can leave out in `PluginContext` `features`, with no UI and nothing to save:
  `FEATURE_AUDIT` (activity log), `FEATURE_HISTORY` (earlier chats), `FEATURE_OAUTH`, `FEATURE_API_KEYS`,
  `FEATURE_ABILITIES` and `FEATURE_MCP_ADAPTER` (all on by default). A part left out registers no hooks or
  REST routes, saved settings cannot switch it on, `Kit::uiBootstrap()` reports it in `features`, and the
  UI package hides its tabs, cards and rows (`featureOn()` / `useFeature()` in `/core`).
- Table storage on the Bit Apps ORM: `PluginContext` `storage => tables` keeps chat history in
  `{prefix}aikit_conversations` and the activity log in `{prefix}aikit_audit_log` (searched, filtered and
  paged in SQL), created only for the parts the plugin ships. `Kit::installTables()`, `deactivate()`,
  `uninstallTables()`; the tables are also created or upgraded on the first request after an update, a
  daily cron purges by the retention settings, and the stores fall back to the option and user meta until
  the tables exist. `Storage\Migrations`, `Storage\Model`, `TableConversationRepository`,
  `TableAuditLogger`. New dependency: `bitapps/wp-database` ^2.0 (the ORM every Bit plugin ships).
- Integration tier `composer test-integration` (`phpunit.integration.xml`, `tests/Integration`): the table storage
  against a real WordPress and MySQL; skips unless `WP_TESTS_DIR` and `WP_TESTS_CONFIG_FILE_PATH` are set.
- Admin REST routes under `<rest_namespace>/ai/*` (`Rest\RestRoutes`), opt-in through the PluginContext
  feature `rest_ui`: the chat panel (`assistant/view|chat|decide|reset|history`, `assistant/conversations/{id}`
  open/rename/flags/delete), the settings screens (`settings/assistant`, `settings/mcp`), the MCP screen
  (`mcp/state`, `mcp/activity`, `mcp/api-keys`, `mcp/connections`). WordPress cookie + `X-WP-Nonce` auth;
  every route requires `can_use`, saving and clearing need the new `manage_cap` (`manage_options` by default).
  A plugin that opts in writes no controllers; the host adds its own through the filters below.
- `Kit::uiBootstrap()`: what the admin UI needs before its first request (REST base, nonce, what the user may
  do, which features are shipped and on, the engine, brand), for the host to localize under its own variable.
- Hooks for host plugins: `chatContextHook` (turn the browser's ids into the page-context sentence and the
  stored `about`), `chatViewHook` (add to every chat reply, e.g. a preview), `conversationSwitchHook`
  (before reset/open/forget), `settingsSavedHook` (after a save through the kit), `uiBootstrapHook`,
  `restPermissionHook`; constants `Contract::REST_BASE` and `CONVERSATION_SOURCES_ACTION`.
- Conversation history: `Assistant::rename()` and `setFlags()` (pinned / archived, kept inside the
  conversation's `context` so no repository needs new columns); `history()` takes `$archived` and lists
  pinned conversations first; optional `Ai\ConversationQueryInterface` lets a table-backed repository do
  that in SQL; the view's `conversation` carries `pinned` and `archived`.
- `Audit\SearchableAuditLog` (`search()`, `facets()`, `clear()`) for activity screens; `OptionsAuditLogger`
  implements it; `Kit::auditSearch()`, `auditFacets()`, `clearAudit()` fall back for loggers that cannot.
- `PluginContext` `manage_cap` and `canManage()`; `Kit::useEngineFactory()` test seam.
- `@bitapps/wp-ai-kit-ui` (repo folder `frontend/`, npm): the admin UI on those routes. `/core` holds the
  types, REST transport, store (`useSyncExternalStore`, no state library), `AiKitProvider`, hooks and the
  window bus; `/components` the chat (`AssistantChat`, composer with `@` mentions and `/` commands, pending
  action card, Markdown subset with a link policy), conversation history (search, rename, pin, archive,
  delete), `AssistantSettingsForm`, `McpSettings` (overview, connect guides for 12 AI apps, connections, API
  keys, tools, activity, diagnostics), `Panel` and `FloatingLauncher`; `/antd` an optional slot map for antd
  hosts. Primitives are injectable slots with plain-HTML defaults; styling is `--aikit-*` tokens on
  zero-specificity rules. Contract `"1"`.
- Settings screens show what a setting does behind an info button beside its label (`help="tooltip"`,
  default) or under it (`help="inline"`); privacy and security consequences and current states stay visible
  as a one-line note. `SettingText`, `HelpTip`, `HelpText` and `HelpModeProvider` are exported for host rows.
- `docs/ARCHITECTURE.md`, `docs/SECURITY.md` and `docs/RELEASING.md`; README sections on choosing features
  and storage; ADOPTION sections on leaving parts out and on the admin UI.
- CI for the UI package (`.github/workflows/frontend.yml`) and a release workflow that publishes it to npm
  with provenance on a `v*` tag (`release.yml`); `pnpm contract:check` keeps the UI's contract equal to
  `Contract::CONTRACT_VERSION`.

### Fixed
- Switching the activity log off (feature `audit`, setting, constant or filter) now also stops a
  plugin-supplied audit logger; before, a logger passed in `services.audit` kept recording.

### Security
- Without the manage capability, a user's activity log, its filter menus and the MCP screen's recent calls
  show only that user's own calls (they listed every user's calls, app names and record ids).
- Only a manager may create an API key that never expires or never pauses for confirmation (`unattended`),
  and no key can be created while API keys are switched off.
- Route ids (`{id}`) are read from the URL only; an `id` in the query or body no longer changes which
  conversation, key or connection a route acts on (`?id=all` could delete all of a user's chats).
- Signed-out requests get 401 before `bitapps_ai_rest_permission_<slug>` runs, so the filter cannot open
  a route to visitors; it now also receives the route's level (`use` / `manage`).
- An approved action is taken off the conversation before it runs, so a second approval sent at the same
  time, or after a failed turn, cannot run it twice.
- The history search is capped at 100 characters; non-scalar activity filters are ignored.

## [1.1.2] - 2026-10-19

### Added
- `PluginContext` keys `description`, `website_url` and `icons`, sent in the MCP `initialize` reply's
  `serverInfo` (`description`, `websiteUrl`, `icons`; MCP 2025-11-25) so clients can show the plugin's
  website and logo. Icons keep MCP's shape (http(s) or `data:image/` source, optional `mimeType`, `sizes`,
  `theme`); an invalid URL or icon is dropped. Empty values are not sent.

### Changed
- User-facing messages name WordPress's own menu as "WordPress Settings → Connectors", so it is not read as a
  plugin settings tab. The assistant's missing-key messages point to the AI engine section of the assistant
  settings instead of "here", which was wrong in the chat panel. The consent screen says the app can be
  disconnected in the plugin's MCP server settings instead of a stale "AI & MCP" menu name.

## [1.1.1] - 2026-10-18

### Changed
- "Sign in with OAuth" (`oauth` MCP setting and feature) is on by default, so AI apps that only connect through
  OAuth (Claude.ai, ChatGPT, Gemini) work once the MCP server is switched on. OAuth still needs the MCP server
  on and pretty permalinks.

## [1.1.0] - 2026-10-18

### Added
- `services.brand` takes `background`, `color_dark` and `background_dark` beside `color`, so the consent
  screen can follow the plugin's colours in light and dark mode. Hex values only; a missing key falls back to
  the new `ConsentScreen::DEFAULT_PALETTE` (`color_dark` to the plugin's `color` first).

### Changed

- The consent screen's primary button text is black or white, whichever reads better on the accent, and the
  access-level radios and checkbox use the accent.

### Fixed

- Client metadata documents are accepted when `none` is among `token_endpoint_auth_methods_supported`,
  whatever the legacy `token_endpoint_auth_method` says. ChatGPT publishes `["none", "private_key_jwt"]`
  with `private_key_jwt` as its preference and was refused as an unknown client.

## [1.0.0] - 2026-10-18

First release.

### Added

- `PluginContext` `features` (`FEATURE_MCP`, `FEATURE_ASSISTANT`, both on by default): a plugin that does not
  ship one leaves it out entirely. Without MCP the kit registers no hooks at all (no REST routes, OAuth,
  `.well-known`, consent, Abilities or adapter hooks), and settings cannot switch a left-out feature on.
- Factory services: `audit`, `conversations` and `oauth_storage` may be given as a Closure; the kit builds
  them on first use, so a plain request loads none of their classes.
- `Contract::consentAction()`; `HttpTransport::registerLazily()` / `OAuthServer::registerLazily()`.
- Conversation history: `Ai\ConversationRepositoryInterface` (a plugin supplies one as the `conversations`
  service), `UserMetaConversationRepository` (default: one conversation per user, as before),
  `Assistant::history()`, `open()`, `forget()`; the chat view carries `conversation {id, title}`; "New chat"
  keeps the previous conversation; `chat()` takes a context stored with a new conversation;
  AI setting `history_days` (1–365, default 30) ages conversations out.
- `meta.assistant_preview` on a tool definition: in the assistant channel the tool runs without the
  per-call confirmation, because it only changes a preview the user applies or discards afterwards.
- `Kit::boot(PluginContext)`, `Contract` (cross-plugin hook/option/ability names), `Settings\FeatureFlags`
  with `<PREFIX>MCP_DISABLED` / `<PREFIX>AI_DISABLED` kill switches and the `bitapps_ai_feature_disabled` filter.
- Tool core: `ToolDefinition` (accepts Bit PI objects' shape and Bit CRM's array tools, MCP annotation
  spellings), `ToolProviderInterface`, `ToolRegistry` (filter `bitapps_ai_tool_providers_<slug>`, first name
  wins, broken definitions skipped), `ToolContext`, `ToolResult` (ORM-agnostic `rows()`), `InputValidator`
  (core REST schema validation + coercion), `Scopes` (read ⊂ write ⊂ destructive), `AccessPolicy`,
  `ToolGuard`, two-step `Confirmation\ConfirmationGate`, `ToolRunner`.
- Security: `RateLimiter`, `SecretRedactor` (filter `bitapps_ai_secret_keys`), `PiiMasker` (plugin-supplied
  classifier), `UntrustedContent` fencing, `Digest`.
- Audit: `AuditLoggerInterface`, `OptionsAuditLogger` (default), `NullAuditLogger`.
- MCP: `Mcp\Server\McpServer` + `JsonRpc` (protocols 2024-11-05 → 2025-11-25, batches only ≤ 2025-03-26),
  `Mcp\Transport\HttpTransport` (Streamable HTTP, stateless, Application Passwords via WordPress, bearer
  resolvers, Origin check, 401/403 `WWW-Authenticate` with step-up scope), `Mcp\Auth\ApiKeyService`.
- WordPress-native bridges: `Abilities\AbilitiesBridge` (mirror non-destructive tools, lazy-init safe) and
  `Mcp\Transport\WpMcpAdapterBridge` (server `bitapps-<slug>` on the MCP Adapter plugin).
- Settings: `McpSettings` (validated, non-autoloaded), `RequirementsReport`, `Kit::adminState()`.
- OAuth 2.1 authorization server (`Mcp\OAuth\*`, feature `oauth`): RFC 9728 / RFC 8414 discovery at
  path-suffixed `/.well-known/*` URLs (served by exact path on `init`, no rewrite rules), Client ID Metadata
  Documents and RFC 7591 registration (public clients, capped at 50), authorization code + PKCE S256,
  RFC 8707 audience binding, 1 h access / 30 d rotating refresh tokens with reuse detection, RFC 7009
  revocation, consent screen on `admin-post.php` (access level capped by the site, opt-in personal data,
  `frame-ancestors 'none'`), `OAuthStorageInterface` with an options-backed default that reads Bit CRM's
  rows. `Kit::oauth()`, `oauthConnections()`, `revokeOAuthConnection()`, `adminState()['oauth']`.
- AI assistant (`Ai\*`): `Assistant` (engine choice, system prompt, limits, server-side conversation in user
  meta, per site on multisite), `AgentLoop` (max turns, tools through the `ToolRunner` on channel `assistant`,
  destructive tools pause for the user — the confirmation token stays server-side), engines
  `Engine\CoreAiClientEngine` (WordPress AI Client, 7.0+, keys from Settings → Connectors) and direct
  `Providers\{Claude,OpenAi,Gemini}Provider` (ported from Bit CRM Pro, non-streaming, fixed endpoints, no
  base-URL field), `Settings\AiSettings` (`<prefix>ai_settings`, keys encrypted in `<prefix>ai_credentials` with
  `Security\SecretBox`). `Kit::assistant()`, `aiSettings()`, `saveAiSettings()`, `assistantState()`.
- `ToolRunner` authorizes before validating arguments, so a caller without access learns nothing about a
  tool's schema.
- Prefix-safety linter (`bin/check-prefix-safety.php`) and CI (PHP 8.0 / 8.3 / 8.4).

### Changed

- MCP and OAuth REST routes register without building the server: the transport, tool registry, providers and
  OAuth stores are created only when a request reaches those routes (previously on every REST request while
  MCP was on). The `rest_post_dispatch` challenge hook builds the transport only for the MCP endpoint's 401s.
- Tools are mirrored into the Abilities API only when something reads them: the `abilities_in_rest` MCP
  setting, or the MCP Adapter plugin. WordPress builds the abilities registry on REST requests, so an unused
  mirror loaded every tool definition on each one.
- The MCP and assistant settings options are autoloaded (credentials still are not); a never-saved option gets
  an empty autoloaded placeholder on first read. The MCP hooks read the settings on every REST request, which
  cost one database query each while the options were not autoloaded or did not exist.

### Fixed

- `PluginContext::create()` threw an unqualified `InvalidArgumentException` (a fatal) for non-string
  `instructions`.

[Unreleased]: https://github.com/bit-apps-pro/wp-ai-kit/compare/v1.1.2...HEAD
[1.1.2]: https://github.com/bit-apps-pro/wp-ai-kit/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/bit-apps-pro/wp-ai-kit/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/bit-apps-pro/wp-ai-kit/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/bit-apps-pro/wp-ai-kit/releases/tag/v1.0.0
