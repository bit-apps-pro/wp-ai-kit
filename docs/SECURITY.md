# Security model

> Audience: kit contributors and the developers of consuming plugins. It says who may call
> what and what the kit protects. A plugin still owns its tools' own checks
> (`resource_check`, capabilities).

Report a vulnerability privately through GitHub's private vulnerability reporting
(repository `bit-apps-pro/wp-ai-kit`, tab **Security → Report a vulnerability**), never in a public issue.

## 1. Surfaces and who may use them

| Surface | Authentication | Who |
| --- | --- | --- |
| MCP `POST <ns>/mcp` | Application Password (WordPress), kit API key, or OAuth bearer token | the user the credential belongs to, checked against the plugin's `can_use` |
| OAuth `<ns>/mcp/oauth/{authorize,token,register,revoke}` and `/.well-known/oauth-*` | public by design (OAuth 2.1 with PKCE) | any client; a grant needs a signed-in user's consent |
| Consent screen `admin-post.php?action=<slug>_mcp_authorize` | WordPress login first, then the `wp_nonce` of the form | a user with `can_use` |
| Attachment upload `POST <ns>/ai/assistant/attachments` | as the admin REST routes | `can_use` and `upload_files`; only with the `attachments` feature |
| Admin REST `<ns>/ai/*` | WordPress cookie session and the `wp_rest` nonce in `X-WP-Nonce` | `can_use`; changing settings, clearing the activity log and touching other users' keys or connections need `manage_cap` |

Rules for the admin REST routes (`src/Rest/RestRoutes.php`):

- **A real permission check on every route.** Each one has a `permission_callback`; none
  uses `__return_true`. Writes use POST, PATCH or DELETE, never GET.
- **Signed-out requests are refused first.** They get 401 before the
  `bitapps_ai_rest_permission_<slug>` filter runs. The filter receives `$level`
  (`use` or `manage`) and the request (`get_method()`), so a plugin can narrow access
  per method, but never open a route to visitors.
- **Ids come from the URL only.** A `{id}` is read from the path. An `id` in the query
  or body cannot change which record a route acts on.
- **Users see their own records.** Without `manage_cap`, a user sees and changes only
  their own conversations, API keys, OAuth connections and activity rows. The filter
  menus and the screen state are reduced to match.
- **Keys that bypass safety settings are manager-only.** An API key that never expires,
  or never pauses for confirmation (`unattended`), can only be created by a user with
  `manage_cap`. No key can be created while API keys are switched off.
- **Optional parts register nothing.** A part the plugin leaves out (`audit`,
  `history`, `oauth`, `api_keys`) registers no route at all (`RestRoutes::requires()`).

Rules for attachments (`src/Ai/Attachments/AttachmentStore.php`):

- **An allow-list, checked against the content.** Only images (PNG, JPEG, GIF, WebP), PDF, `.docx`
  and plain text. The extension, `finfo`'s type and `wp_check_filetype_and_ext()` must agree; images
  must decode, PDFs start with `%PDF-`, a `.docx` must contain `word/document.xml` (its size is capped
  against zip bombs). SVG, HTML and text carrying `<?php` or `<script` are refused.
- **Never linked.** Files sit in a per-user folder under uploads with deny guards for Apache, IIS and
  directory listing. The id is 128 random bits and the name on disk is an HMAC of it with the site's
  `auth` salt, so neither the id (which the browser and the stored chat hold) nor the user id gives the
  path. The path is built from the signed-in user's id, so a user can only name their own files. Nothing
  returns a URL to them. nginx ignores `.htaccess`: there the names are the protection, and a
  `location ~ /[^/]+aikit_attachments/ { deny all; }` rule makes it a hard block.
- **Short-lived.** 24 hours by default (hourly purge), and deleted with their conversation.
- **Untrusted in the prompt.** Text pulled from files is wrapped as untrusted content; the
  conversation keeps metadata only. Uploads are rate-limited per user (30 per 10 minutes).
- **No location leaks.** EXIF (with GPS), XMP, IPTC and text metadata are dropped from JPEG, PNG and
  WebP files as they are stored (`Ai\Attachments\ImageMetadata`, no re-encoding; a JPEG keeps only its
  orientation), so the provider never gets them. Filter `bitapps_ai_attachment_strip_metadata_<slug>`.
- **Audited.** Each upload, refusal and removal is an activity-log row (`attachment.uploaded` /
  `attachment.deleted`, user, attachment id, outcome), without the file's name or content.

## 2. Tool calls

All channels (MCP and the assistant) run tools through `Tools\ToolRunner`:

1. **Authorization before validation.** The access level comes from the site
   settings, capped by the OAuth grant or API key. Then the tool's scope and the
   plugin's `resource_check`. An unauthorized caller learns nothing about a tool's
   arguments.
2. **Rate limits** per user and app (`calls_per_minute`, `writes_per_minute`).
   The assistant also has `daily_messages` and a 4,000-character message limit.
3. **Confirmation.** Destructive tools need the user's confirmation. Over MCP this is
   a one-time code the app must send back. In the assistant, every write and
   destructive call waits as a `pending` action that only the user can approve. It is
   taken off the conversation before it runs, so a repeated approval cannot run it twice.
4. **Output protection.** Personal data is masked unless the setting or the OAuth
   grant allows it (`Security\PiiMasker`, plus the plugin's `pii_classifier`). Secrets
   are redacted (`Security\SecretRedactor`). Tool results are fenced as untrusted
   content before a model sees them (`Security\UntrustedContent`).
5. **Audit.** Each call records tool, status, channel, user, app, record id and
   duration. Arguments and results are never stored. A plugin can leave the log out
   entirely (`features.audit = false`).

## 3. What reaches the model

- The user's message, earlier turns, and tool results (masked and fenced).
- A page-context sentence built **by the plugin on the server** from ids the browser
  sent (`chatContextHook`). Browser text never goes into the system prompt directly.
  Plugins must resolve ids against saved data and strip markup. Bit Form caps labels
  at 80 characters and the whole context at 500.
- Never provider keys, API keys, OAuth tokens or confirmation codes.

### OpenAI-compatible services

The one engine with an address someone types. Only a manager (`manage_cap`) can set it, through the settings
screen. The base URL must be https and must not resolve to a local or private address (`wp_http_validate_url()` when
saved, `wp_safe_remote_post()` again on every request), with no credentials, query or fragment in it. The saved key
belongs to that URL: changing the URL removes the key, so a stored key cannot be re-pointed at another server. Provider
calls do not follow redirects, so a redirect cannot carry the key's header to another host. Only managers see the
base URL and the keys' last-four hints; other assistant users see only whether a key is set.
The chat, tool results and attached text go to that service under its own policy.

## 4. Secrets at rest

| Secret | Stored as |
| --- | --- |
| Provider keys (Claude, OpenAI, Gemini, OpenAI-compatible) | encrypted with the site's salts (`Security\SecretBox`) in the non-autoloaded `ai_credentials` option; screens show only a last-four hint |
| API keys | hashed; the key is shown once on creation |
| OAuth tokens | hashed; refresh tokens rotate, and reuse revokes the whole grant |

Chats and attached files are personal data: they go when the user is deleted, and the kit registers a
personal-data exporter and eraser for them (`<slug>-ai-chats`).

Define `AUTH_KEY` and the other salts in `wp-config.php`. Salts stored in the database
protect the provider key less, and the settings screen says so.

## 5. Known limits

- **Long assistant turns.** A turn may extend PHP's time limit, up to about 95 seconds
  per model step. The daily message limit caps cost, but not how many turns one user
  runs at once.
- **Page context labels.** A plugin may build the page context from a builder state the
  browser sent (Bit Form's preview). Those labels are sanitised and capped, but they
  are user-controlled text. Keep them short and treat them as data.
- **Attached files go to the provider.** Personal-data masking applies to tool output, not to the files a
  user attaches; the composer says so under the files.
- **The provider's own policy.** Whatever reaches the provider follows that provider's
  data policy. Personal-data masking is the control the kit offers.
