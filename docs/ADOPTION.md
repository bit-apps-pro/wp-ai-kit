# Adopting wp-ai-kit in a Bit plugin

> Audience: developers of Bit CRM, Bit PI (Bit Flows) and future Bit plugins.
> Bit Form is the reference consumer; its adoption is in the Bit Form repository
> (`docs/specs/mcp-ai-architecture.md`). This document is filled in as the kit's
> phases land; sections marked *(pending)* describe planned API.

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
URLs and optional services (`storage`, `audit`, `pii_classifier`).

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
  existing rows or use a table. `services.brand` (`['color' => '#hex']`) tints the
  consent screen.
- A settings screen reads `Kit::adminState()` and saves through
  `Kit::saveMcpSettings($input)`; the kit validates every key.
- On WordPress 6.9+ the tools are mirrored as abilities (category
  `bitapps-<slug>`), and on sites with the MCP Adapter plugin they also appear on
  the server `bitapps-<slug>` at `<rest_namespace>/mcp-adapter`.

### AI assistant

`Kit::assistant()->chat($userId, $message, $pageContext)`, `->decide($userId, $approve)`,
`->reset($userId)` and `->view($userId)` drive an in-admin chat; `Kit::assistantState()` and
`Kit::saveAiSettings($input)` back its settings screen. The engine is the WordPress AI Client when the site
set up a provider in Settings → Connectors (WordPress 7.0+), otherwise the plugin's saved key for Claude,
OpenAI or Gemini. Tools run through the same `ToolRunner` as MCP, on channel `assistant`, with the access level
from `<prefix>ai_settings`; destructive tools return a `pending` action the user approves or cancels in the UI
(never the model). Build the page-context sentence on the server from ids, not from browser text. Supply
`services.assistant_persona` for plugin-specific guidance. Bit CRM can replace its `AgentLoop`,
`ToolDispatcher` and providers with this; streaming (SSE) is not in the kit yet.

### Reference: Bit Form

`bitform/backend/app/Providers/AiProvider.php` (boot, context, provider filter),
`backend/app/Ai/Tools/*` (providers over the ORM models),
`backend/app/Http/Controller/AiMcpController.php` (settings endpoints). Bit Form
Pro adds tools through Free's own `bitform_ai_tool_providers` filter and Free's
`Ai\ToolProvider` facade, so it never names the kit's prefixed classes.

## 5. Migration paths

### Bit PI Pro (replaces `pro/backend/app/src/McpServer/*`)

Keep: route `bit-pi/v1/mcp`, `.well-known` documents under the resource path,
options `bit_pi_pro_mcp_oauth_clients`, `bit_pi_pro_mcp_oauth_tokens`,
`bit_pi_pro_mcp_api_key`, `bit_pi_pro_mcp_server_settings`, token prefixes
`bfmcp_at_` / `bfmcp_rt_` / `bfmcp_key_`, client ids `bfmcp_`, scopes
`flow:read … settings:write`, authorization-code TTL 300 s, and the rule that a
token record without `scopes` means full access. The kit's `Mcp\Storage` adapter
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

## 6. Checklist *(pending)*
