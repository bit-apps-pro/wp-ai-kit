import { AiKitError, type AiKitAction, type Transport } from '../src/core/transport'
import type {
  ActivityPage, AiKitBootstrap, ApiKeyRecord, AssistantState, AuditEvent, ChatMessage, ChatView, ConversationList,
  ConversationSummary, McpState, OAuthConnection, PendingAction, RequirementCheck, ToolInfo,
} from '../src/core/types'

/** A bootstrap as `Kit::uiBootstrap()` would give a Bit Form administrator. */
export function sampleBootstrap(overrides: Partial<AiKitBootstrap> = {}): AiKitBootstrap {
  return {
    contract: '1',
    slug: 'bit-demo',
    name: 'Bit Demo',
    brand: { name: 'Bit Demo', color: '#3c4b6d', icon: null },
    restBase: 'https://example.test/wp-json/bit-demo/v1/ai',
    nonce: 'nonce',
    canUse: true,
    canManage: true,
    canUpload: true,
    features: { assistant: true, mcp: true, restUi: true, history: true, attachments: false, stream: false },
    assistantOn: true,
    mcpOn: true,
    engine: { key: 'scripted', label: 'Scripted model' },
    engineReason: null,
    user: { id: 5, locale: 'en_US' },
    settingsUrl: 'https://example.test/wp-admin/admin.php?page=bit-demo#/settings',
    docsUrl: 'https://example.test/docs',
    ...overrides,
  }
}

export const sampleTools: ToolInfo[] = [
  { name: 'list-forms', title: 'List forms', description: 'Lists the forms.', access: 'read', tier: 'free', personal: false, enabled: true },
  { name: 'rename-form', title: 'Rename a form', description: 'Renames a form.', access: 'write', tier: 'free', personal: false, enabled: true },
  { name: 'delete-form', title: 'Delete a form', description: 'Deletes a form for good.', access: 'destructive', tier: 'pro', personal: false, enabled: false },
]

export const sampleRequirements: RequirementCheck[] = [
  { id: 'php', label: 'PHP', status: 'pass', detail: 'PHP 8.3' },
  { id: 'permalinks', label: 'Pretty permalinks', status: 'warn', detail: 'OAuth needs pretty permalinks.' },
  { id: 'ai_client', label: 'WordPress AI', status: 'info', detail: 'Available from WordPress 7.0; optional.' },
]

interface StoredConversation {
  id: string
  title: string
  messages: ChatMessage[]
  pending: PendingAction | null
  context: Record<string, unknown>
  created: number
  updated: number
}

/** What the scripted model answers to a message. */
export type Reply = { text: string, tools?: { name: string, title: string, error?: boolean }[], pending?: PendingAction | null, extra?: Record<string, unknown> }

export interface MockOptions {
  bootstrap?: AiKitBootstrap
  /** Reply to a message; default echoes it. */
  reply?: (message: string, context: Record<string, unknown>) => Reply
  /** Answer the decide call. */
  decided?: (approve: boolean) => Reply
  /** Milliseconds each request takes (0 = microtask). */
  latency?: number
  /** Throw this for the given actions. */
  fail?: Partial<Record<AiKitAction, AiKitError>>
}

export interface MockTransport {
  transport: Transport
  calls: { action: AiKitAction, payload: Record<string, unknown> }[]
  conversations: Map<string, StoredConversation>
  activeId: string | null
  assistantState: AssistantState
  mcpState: McpState
  activity: AuditEvent[]
  /** Seed a conversation. */
  seed(conversation: Partial<StoredConversation> & { title: string }): StoredConversation
  /** Record an audit event. */
  audit(event: Partial<AuditEvent> & { tool: string }): void
}

/**
 * The kit's REST routes in memory: conversations with pending actions and
 * history flags, settings, the MCP state, the activity log, API keys and
 * connections. Enough for component tests and the playground.
 */
export function createMockTransport(options: MockOptions = {}): MockTransport {
  const bootstrap = options.bootstrap || sampleBootstrap()
  const conversations = new Map<string, StoredConversation>()
  let activeId: string | null = null
  let nextId = 1
  let nextKey = 1
  const calls: MockTransport['calls'] = []
  const activity: AuditEvent[] = []
  const apiKeys: ApiKeyRecord[] = []
  let connections: OAuthConnection[] = [
    { id: 'c1', client_id: 'https://claude.ai/.well-known/client.json', client_name: 'Claude', client_kind: 'cimd', client_host: 'claude.ai', scope: 'write', user_id: 5, user: 'User 5', personal_data: false, created: Math.floor(Date.now() / 1000) - 86400, last_used: Math.floor(Date.now() / 1000) - 3600 },
  ]

  const assistantState: AssistantState = {
    settings: { enabled: true, engine: 'auto', provider: 'claude', model: '', access_level: 'write', disabled_tools: [], confirm_destructive: true, allow_personal_data: false, max_turns: 6, daily_messages: 300, history_days: 30 },
    keys: { claude: { set: true, hint: '…ab12' }, openai: { set: false, hint: '' }, gemini: { set: false, hint: '' }, openai_compatible: { set: false, hint: '' } },
    suggested_models: { claude: ['claude-sonnet-5-5'], openai: ['gpt-5'], gemini: ['gemini-2.5-pro'], openai_compatible: [] },
    forced_off: false,
    wordpress_ai: false,
    wordpress_ai_api: true,
    engine: bootstrap.engine,
    engine_reason: bootstrap.engineReason,
    salts_in_config: true,
    tools: sampleTools,
  }

  const mcpState: McpState = {
    settings: { enabled: true, access_level: 'write', disabled_tools: [], confirm_destructive: true, allow_personal_data: false, api_keys: true, oauth: true, expose_on_default_server: false, abilities_in_rest: false, calls_per_minute: 120, writes_per_minute: 30, audit_retention_days: 90 },
    forced_off: { mcp: false, ai: false },
    endpoint: 'https://example.test/wp-json/bit-demo/v1/mcp',
    tools: sampleTools,
    requirements: sampleRequirements,
    recent: [],
    api_keys: { enabled: true, forced_off: false, keys: apiKeys },
    oauth: { forced_off: false, enabled: true, pretty_permalinks: true, connections },
  }

  const now = () => Math.floor(Date.now() / 1000)

  const seed: MockTransport['seed'] = (conversation) => {
    const id = conversation.id || String(nextId++)
    const row: StoredConversation = { id, title: conversation.title, messages: conversation.messages || [], pending: conversation.pending || null, context: conversation.context || {}, created: conversation.created || now(), updated: conversation.updated || now() }
    conversations.set(id, row)
    return row
  }

  const audit: MockTransport['audit'] = (event) => {
    activity.unshift({ id: activity.length + 1, created_at: new Date().toISOString().slice(0, 19).replace('T', ' '), user_id: 5, user: 'User 5', channel: 'assistant', client_ref: 'Assistant · Scripted', resource_id: null, item_count: 1, status: 'ok', error_code: null, duration_ms: 12, args_digest: null, ...event })
    mcpState.recent = activity.slice(0, 20)
  }

  const view = (): ChatView => {
    const row = activeId ? conversations.get(activeId) : null
    return {
      messages: row ? row.messages : [],
      pending: row ? row.pending : null,
      conversation: row ? { id: row.id, title: row.title, pinned: !!row.context.pinned, archived: !!row.context.archived } : { id: null, title: '', pinned: false, archived: false },
      engine: assistantState.engine,
      engine_reason: assistantState.engine_reason,
    }
  }

  const refuse = (error: string, reason: string): never => {
    throw new AiKitError(error, reason, 422, { ...view(), error, reason })
  }

  const summaries = (archived: boolean, search: string): ConversationSummary[] => [...conversations.values()]
    .filter(row => !!row.context.archived === archived && (!search || row.title.toLowerCase().includes(search.toLowerCase())))
    .sort((a, b) => Number(!!b.context.pinned) - Number(!!a.context.pinned) || b.updated - a.updated)
    .map(row => ({ id: row.id, title: row.title, context: row.context, messages: row.messages.length, created: row.created, updated: row.updated, pinned: !!row.context.pinned, archived: !!row.context.archived }))

  const applyReply = (row: StoredConversation, reply: Reply): ChatView => {
    const tools = (reply.tools || []).map(tool => ({ name: tool.name, title: tool.title, error: !!tool.error }))
    tools.forEach(tool => audit({ tool: tool.name, status: tool.error ? 'error' : 'ok' }))
    row.messages = [...row.messages, { role: 'assistant', text: reply.text, tools }]
    row.pending = reply.pending || null
    row.updated = now()
    return { ...view(), ...(reply.extra || {}) }
  }

  const handle = async (action: AiKitAction, payload: Record<string, unknown>): Promise<unknown> => {
    switch (action) {
      case 'assistant/view':
        return view()
      case 'assistant/chat': {
        const message = String(payload.message || '').trim()
        if (!message) throw new AiKitError('Type a message first.', 'ai_empty_message', 400, null)
        if (!assistantState.settings.enabled) refuse('The AI assistant is switched off.', 'disabled')
        if (!assistantState.engine) refuse(assistantState.engine_reason || 'No AI engine is set up.', 'no_engine')
        let row = activeId ? conversations.get(activeId) : undefined
        if (row?.pending) refuse('Confirm or cancel the waiting action first.', 'pending_confirmation')
        if (!row) {
          row = seed({ title: message.slice(0, 80), context: (payload.context as Record<string, unknown>)?.about as Record<string, unknown> || {} })
          activeId = row.id
        }
        row.messages = [...row.messages, { role: 'user', text: message }]
        const reply = options.reply ? options.reply(message, (payload.context as Record<string, unknown>) || {}) : { text: `You said: ${message}` }
        return applyReply(row, reply)
      }
      case 'assistant/decide': {
        const row = activeId ? conversations.get(activeId) : undefined
        if (!row?.pending) refuse('Nothing is waiting for confirmation.', 'no_pending')
        const approve = !!payload.approve
        const reply = options.decided ? options.decided(approve) : { text: approve ? 'Done.' : 'Cancelled.' }
        row!.pending = null
        return applyReply(row!, reply)
      }
      case 'assistant/reset':
        activeId = null
        return view()
      case 'assistant/history': {
        const all = summaries(payload.archived === true || payload.archived === '1' || payload.archived === 1, String(payload.search || ''))
        const offset = Number(payload.offset || 0)
        const limit = Number(payload.limit || 20)
        return { items: all.slice(offset, offset + limit), total: all.length } as ConversationList
      }
      case 'assistant/open': {
        const row = conversations.get(String(payload.id))
        if (!row) throw new AiKitError('That conversation no longer exists.', 'not_found', 404, { ...view(), error: 'That conversation no longer exists.', reason: 'not_found' })
        activeId = row.id
        return view()
      }
      case 'assistant/update': {
        const row = conversations.get(String(payload.id))
        if (!row) throw new AiKitError('That conversation no longer exists.', 'not_found', 404, { ...view(), error: 'That conversation no longer exists.', reason: 'not_found' })
        if (typeof payload.title === 'string' && payload.title.trim()) row.title = payload.title.trim()
        for (const flag of ['pinned', 'archived'] as const) {
          if (flag in payload) {
            if (payload[flag]) row.context = { ...row.context, [flag]: true }
            else { const { [flag]: _drop, ...rest } = row.context; row.context = rest }
          }
        }
        return view()
      }
      case 'assistant/forget': {
        const id = String(payload.id)
        if (id === 'all') conversations.clear()
        else conversations.delete(id)
        if (id === 'all' || id === activeId) activeId = null
        return view()
      }
      case 'settings/assistant/get':
        return assistantState
      case 'settings/assistant/save': {
        const input = (payload.settings || payload) as Record<string, unknown>
        const { api_key, clear_api_key, ...settings } = input
        Object.assign(assistantState.settings, settings)
        if (typeof api_key === 'string' && api_key) assistantState.keys[assistantState.settings.provider] = { set: true, hint: `…${api_key.slice(-4)}` }
        if (clear_api_key) assistantState.keys[assistantState.settings.provider] = { set: false, hint: '' }
        return assistantState
      }
      case 'settings/mcp/get':
      case 'mcp/state':
        return mcpState
      case 'settings/mcp/save':
        Object.assign(mcpState.settings, (payload.settings || payload) as Record<string, unknown>)
        return mcpState
      case 'mcp/activity': {
        let rows = activity
        for (const key of ['tool', 'status', 'channel', 'client_ref', 'user_id', 'resource_id'] as const) {
          if (payload[key] !== undefined && payload[key] !== '') rows = rows.filter(row => String(row[key] ?? '') === String(payload[key]))
        }
        const perPage = Number(payload.per_page || 25)
        const page = Number(payload.page || 1)
        const distinct = (key: keyof AuditEvent) => [...new Set(activity.map(row => row[key]).filter(v => v !== null && v !== undefined && v !== ''))] as string[]
        return { rows: rows.slice((page - 1) * perPage, page * perPage), total: rows.length, filters: { tool: distinct('tool'), status: distinct('status'), channel: distinct('channel'), client_ref: distinct('client_ref'), resource_id: distinct('resource_id'), user: [{ id: 5, name: 'User 5' }] } } as ActivityPage
      }
      case 'mcp/activity/clear':
        activity.length = 0
        mcpState.recent = []
        return { rows: [], total: 0, filters: { tool: [], status: [], channel: [], client_ref: [], resource_id: [], user: [] } } as ActivityPage
      case 'mcp/api-keys/list':
        return mcpState.api_keys
      case 'mcp/api-keys/create': {
        const name = String(payload.name || '').trim()
        if (!name) throw new AiKitError('Give the key a name of up to 100 characters.', 'invalid_name', 422, null)
        const record: ApiKeyRecord = { id: `k${nextKey++}`, name, user_id: 5, user: 'User 5', display: 'bitdemo_key_1234…', scopes: [String(payload.access || 'read') as ApiKeyRecord['scopes'][number]], unattended: !!payload.unattended, created_at: now(), expires_at: payload.expires_days ? now() + Number(payload.expires_days) * 86400 : null, last_used_at: null, revoked_at: null, status: 'active' }
        apiKeys.unshift(record)
        return { key: `bitdemo_key_${record.id}_secretvalue`, record, state: mcpState }
      }
      case 'mcp/api-keys/revoke': {
        const record = apiKeys.find(key => key.id === payload.id)
        if (!record) throw new AiKitError('That key does not exist.', 'ai_api_key_not_found', 404, null)
        record.status = 'revoked'
        record.revoked_at = now()
        return mcpState
      }
      case 'mcp/connections/list':
        return mcpState.oauth
      case 'mcp/connections/revoke': {
        if (!connections.some(connection => connection.id === payload.id)) throw new AiKitError('That connection no longer exists.', 'ai_connection_not_found', 404, null)
        connections = connections.filter(connection => connection.id !== payload.id)
        mcpState.oauth.connections = connections
        return mcpState
      }
      case 'mcp/connections/revoke-all':
        connections = []
        mcpState.oauth.connections = connections
        return mcpState
      default:
        throw new AiKitError(`Unknown action ${action as string}`, 'unknown_action', 404, null)
    }
  }

  const transport: Transport = {
    async request<T>(action: AiKitAction, payload: Record<string, unknown> = {}): Promise<T> {
      calls.push({ action, payload })
      if (options.latency) await new Promise(resolve => setTimeout(resolve, options.latency))
      const failure = options.fail?.[action]
      if (failure) throw failure
      // A fresh copy every time, as real JSON replies are: hooks compare by identity.
      const result = await handle(action, payload)
      return (result === undefined ? result : JSON.parse(JSON.stringify(result))) as T
    },
  }

  return {
    transport,
    calls,
    conversations,
    get activeId() { return activeId },
    set activeId(id: string | null) { activeId = id },
    assistantState,
    mcpState,
    activity,
    seed,
    audit,
  }
}
