/**
 * The wire contract with the kit's PHP (Contract::CONTRACT_VERSION "1"): what
 * `Kit::uiBootstrap()`, the `<ns>/ai/*` REST routes and the settings screens
 * exchange. Keep in step with `src/Rest/*` and `Kit.php`.
 */

export type AccessLevel = 'read' | 'write' | 'destructive'

export type CheckStatus = 'pass' | 'warn' | 'info' | 'fail'

export interface RequirementCheck {
  id: string
  label: string
  detail: string
  status: CheckStatus
}

export interface ToolInfo {
  name: string
  title: string
  description: string
  access: AccessLevel
  tier: string
  personal: boolean
  enabled: boolean
}

export interface McpSettings {
  enabled: boolean
  access_level: AccessLevel
  disabled_tools: string[]
  confirm_destructive: boolean
  allow_personal_data: boolean
  api_keys: boolean
  oauth: boolean
  expose_on_default_server: boolean
  abilities_in_rest: boolean
  calls_per_minute: number
  writes_per_minute: number
  audit_retention_days: number
}

export interface AuditEvent {
  id?: number
  created_at: string
  user_id: number
  user?: string
  channel: string
  client_ref: string | null
  tool: string
  resource_id: string | null
  item_count: number
  status: string
  error_code: string | null
  duration_ms: number
  args_digest: string | null
}

export interface OAuthConnection {
  id: string
  client_id: string
  client_name: string
  client_kind: string
  client_host: string
  scope: AccessLevel
  user_id: number
  user: string
  personal_data: boolean
  created: number
  last_used: number | null
}

export interface ApiKeyRecord {
  id: string
  name: string
  user_id: number
  user?: string
  display: string
  scopes: AccessLevel[]
  unattended: boolean
  created_at: number
  expires_at: number | null
  last_used_at: number | null
  revoked_at: number | null
  status: 'active' | 'expired' | 'revoked'
}

/** `GET settings/mcp` / `GET mcp/state` (`Kit::adminState()` as one user sees it). */
export interface McpState {
  settings: McpSettings
  forced_off: { mcp: boolean, ai: boolean }
  endpoint: string
  tools: ToolInfo[]
  requirements: RequirementCheck[]
  recent: AuditEvent[]
  api_keys: { enabled: boolean, forced_off: boolean, keys: ApiKeyRecord[] }
  oauth: { forced_off: boolean, enabled: boolean, pretty_permalinks: boolean, connections: OAuthConnection[] }
}

export interface ActivityFilters {
  tool: string[]
  status: string[]
  channel: string[]
  client_ref: string[]
  resource_id?: string[]
  user: { id: number, name: string }[]
}

/** `GET mcp/activity`. */
export interface ActivityPage {
  rows: AuditEvent[]
  total: number
  filters: ActivityFilters
}

export interface ActivityQuery {
  page?: number
  per_page?: number
  tool?: string
  status?: string
  channel?: string
  client_ref?: string
  user_id?: number
  resource_id?: string
  from?: string
  to?: string
}

export type Provider = 'claude' | 'openai' | 'gemini'

export interface AiSettings {
  enabled: boolean
  engine: 'auto' | 'wordpress' | 'provider'
  provider: Provider
  model: string
  access_level: AccessLevel
  disabled_tools: string[]
  confirm_destructive: boolean
  allow_personal_data: boolean
  max_turns: number
  daily_messages: number
  history_days: number
}

export interface EngineInfo {
  key: string
  label: string
}

/** `GET settings/assistant` (`Kit::assistantState()`). */
export interface AssistantState {
  settings: AiSettings
  keys: Record<Provider, { set: boolean, hint: string }>
  suggested_models: Record<Provider, string[]>
  forced_off: boolean
  wordpress_ai: boolean
  wordpress_ai_api: boolean
  engine: EngineInfo | null
  engine_reason: string | null
  salts_in_config: boolean
  tools: ToolInfo[]
}

/** What the settings form may send: the settings plus the write-only key fields. */
export type AiSettingsInput = Partial<AiSettings> & { api_key?: string, clear_api_key?: boolean }

export interface ToolUse {
  name: string
  title: string
  error: boolean
}

export interface ChatMessage {
  role: 'user' | 'assistant'
  text: string
  tools?: ToolUse[]
  /** Set on the optimistic copy of a message the server has not answered yet. */
  optimistic?: boolean
}

export interface PendingAction {
  name: string
  title: string
  description: string
  destructive: boolean
  input: Record<string, unknown>
}

export interface ConversationRef {
  id: string | null
  title: string
  pinned: boolean
  archived: boolean
}

/**
 * What every chat route answers (`Assistant::view()` plus what the kit's REST
 * controller and the host's `chatViewHook` add). A failed turn carries `error`
 * and `reason` under HTTP 422.
 */
export interface ChatView {
  messages: ChatMessage[]
  pending: PendingAction | null
  conversation: ConversationRef
  engine?: EngineInfo | null
  engine_reason?: string | null
  error?: string
  reason?: string
  usage?: { input_tokens: number, output_tokens: number }
  stop_reason?: string
  /** Host additions (Bit Form: `preview`). */
  [extra: string]: unknown
}

export interface ConversationSummary {
  id: string
  title: string
  context: Record<string, unknown>
  messages: number
  created: number
  updated: number
  pinned: boolean
  archived: boolean
}

export interface ConversationList {
  items: ConversationSummary[]
  total: number
}

/** A thing the message points at, as the host defines it; the server resolves names. */
export type Mention = Record<string, unknown>

/** `Kit::uiBootstrap()`, localized by the host plugin. */
export interface AiKitBootstrap {
  contract: string
  slug: string
  name: string
  brand: { name: string, color: string | null, icon: string | null }
  restBase: string | null
  nonce: string
  canUse: boolean
  canManage: boolean
  canUpload: boolean
  /**
   * What this plugin ships (PluginContext `features`). The optional parts are absent
   * from older kits and count as on; `featureOn()` reads them that way.
   */
  features: {
    assistant: boolean
    mcp: boolean
    restUi: boolean
    history: boolean
    attachments: boolean
    stream: boolean
    audit?: boolean
    oauth?: boolean
    apiKeys?: boolean
    abilities?: boolean
    mcpAdapter?: boolean
  }
  assistantOn: boolean
  mcpOn: boolean
  engine: EngineInfo | null
  engineReason: string | null
  user: { id: number, locale: string }
  settingsUrl: string | null
  docsUrl: string | null
  /** Host additions through `uiBootstrapHook`. */
  [extra: string]: unknown
}

/** Which request a chat view answered: `view` is the first load, `chat` / `decide` a turn. */
export type ReplySource = 'view' | 'chat' | 'decide' | 'reset' | 'open' | 'forget' | 'update'

export type AiKitEvent =
  | { type: 'pending', name: string, pending: PendingAction }
  | { type: 'approved' | 'rejected', name: string }
  | { type: 'executed' | 'failed', name: string }
  | { type: 'reply', view: ChatView, source: ReplySource }
  | { type: 'conversation', id: string | null }
  | { type: 'open', detail: OpenRequest }

/** What a host asks the chat to do from outside React (`aiKitBus(slug).open()`). */
export interface OpenRequest {
  message?: string
  autoSend?: boolean
  newChat?: boolean
  conversationId?: string
  context?: Record<string, unknown>
}
