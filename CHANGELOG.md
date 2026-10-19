# Changelog

All notable changes to this package. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html): within a major version, changes are additive only.

## [Unreleased]

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
