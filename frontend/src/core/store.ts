import { AiKitError, type Transport } from './transport'
import type {
  ActivityPage, ActivityQuery, AiKitBootstrap, AiKitEvent, AiSettingsInput, ApiKeyRecord, AssistantState, AttachmentKind,
  ChatMessage, ChatView, ConversationList, ConversationRef, ConversationSummary, EngineInfo, McpState,
  Mention, PendingAction, ReplySource, StreamEvent, ToolUse,
} from './types'

export type ResourceStatus = 'idle' | 'loading' | 'ready' | 'error'

export interface Resource<T> {
  status: ResourceStatus
  data: T | null
  error: string | null
}

/** A file picked for the next message: uploading, ready to send, or refused. */
export interface DraftAttachment {
  localId: string
  name: string
  size: number
  kind: AttachmentKind | null
  status: 'uploading' | 'ready' | 'error'
  /** 0–1 while uploading. */
  progress: number
  /** The server's id once uploaded. */
  id?: string
  error?: string
  /** An object URL for an image's thumbnail; revoked when the draft goes. */
  previewUrl?: string
}

/** Extension → kind, as the PHP AttachmentStore maps them. */
const KIND_BY_EXTENSION: Record<string, AttachmentKind> = {
  png: 'image', jpg: 'image', jpeg: 'image', gif: 'image', webp: 'image', pdf: 'pdf', docx: 'docx', txt: 'text', md: 'text', csv: 'text',
}

export function attachmentKindOf(name: string): AttachmentKind | null {
  return KIND_BY_EXTENSION[(name.split('.').pop() || '').toLowerCase()] || null
}

export interface AssistantSlice {
  /** The conversation was fetched at least once. */
  loaded: boolean
  busy: boolean
  messages: ChatMessage[]
  pending: PendingAction | null
  conversation: ConversationRef
  engine: EngineInfo | null
  engineReason: string | null
  error: string
  reason: string | null
  /** Everything the last reply carried beyond the standard keys (a host's `preview`). */
  extra: Record<string, unknown>
  /** The message the user typed but has not sent, or that a failed turn gave back. */
  draft: string
  /** Files picked for the next message. */
  attachments: DraftAttachment[]
  /** A streamed turn while it runs: the reply text so far and its tools; null otherwise. */
  live: LiveTurn | null
}

/** What a streamed turn has shown so far. */
export interface LiveTurn {
  text: string
  tools: ToolUse[]
}

export interface ConversationsSlice {
  status: ResourceStatus
  items: ConversationSummary[]
  total: number
  search: string
  archived: boolean
  error: string | null
}

export interface AiKitState {
  assistant: AssistantSlice
  conversations: ConversationsSlice
  assistantSettings: Resource<AssistantState>
  mcp: Resource<McpState>
  activity: Resource<ActivityPage> & { query: ActivityQuery }
}

export interface SendOptions {
  mentions?: Mention[]
  /** The host's page context (ids), sent as `context`; the server turns it into text. */
  context?: Record<string, unknown>
}

export interface HistoryOptions {
  search?: string
  archived?: boolean
  /** Append the next page instead of replacing the list. */
  more?: boolean
  limit?: number
}

const EMPTY_CONVERSATION: ConversationRef = { id: null, title: '', pinned: false, archived: false }

const STANDARD_KEYS = new Set(['messages', 'pending', 'conversation', 'engine', 'engine_reason', 'error', 'reason', 'usage', 'stop_reason'])

function initialState(bootstrap: AiKitBootstrap): AiKitState {
  return {
    assistant: {
      loaded: false,
      busy: false,
      messages: [],
      pending: null,
      conversation: EMPTY_CONVERSATION,
      engine: bootstrap.engine,
      engineReason: bootstrap.engineReason,
      error: '',
      reason: null,
      extra: {},
      draft: '',
      attachments: [],
      live: null,
    },
    conversations: { status: 'idle', items: [], total: 0, search: '', archived: false, error: null },
    assistantSettings: { status: 'idle', data: null, error: null },
    mcp: { status: 'idle', data: null, error: null },
    activity: { status: 'idle', data: null, error: null, query: { page: 1, per_page: 25 } },
  }
}

export function errorMessage(error: unknown, fallback: string): string {
  if (error instanceof AiKitError) return error.message || fallback
  if (error instanceof Error && error.message) return error.message
  return fallback
}

/** The tools the latest exchange ran (after the last user message). */
export function toolsOfLastExchange(messages: ChatMessage[]): ChatMessage['tools'] {
  let lastUser = -1
  messages.forEach((message, index) => { if (message.role === 'user') lastUser = index })
  return messages.slice(lastUser + 1).flatMap(message => message.tools || [])
}

/**
 * The UI's state for one plugin, outside React: plain object, `subscribe()` for
 * `useSyncExternalStore`, actions that talk to the transport. No state library,
 * so a host keeps its own (Jotai, zustand, react-query) untouched.
 */
export class AiKitStore {
  private state: AiKitState

  private listeners = new Set<() => void>()

  private eventListeners = new Set<(event: AiKitEvent) => void>()

  private historyTicket = 0

  constructor(private transport: Transport, public readonly bootstrap: AiKitBootstrap) {
    this.state = initialState(bootstrap)
  }

  getState = (): AiKitState => this.state

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener)
    return () => { this.listeners.delete(listener) }
  }

  /** Things a host reacts to: tool runs, pending actions, conversation switches, open requests. */
  onEvent = (listener: (event: AiKitEvent) => void): (() => void) => {
    this.eventListeners.add(listener)
    return () => { this.eventListeners.delete(listener) }
  }

  emit(event: AiKitEvent): void {
    this.eventListeners.forEach(listener => listener(event))
  }

  /**
   * Check files against the bootstrap's rules and upload the good ones; each
   * becomes a draft the composer shows with its progress or why it was refused.
   */
  attach(files: File[]): void {
    const rules = this.bootstrap.attachments
    const upload = this.transport.upload?.bind(this.transport)
    let drafts = [...this.state.assistant.attachments]
    files.forEach((file) => {
      const localId = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`
      const kind = attachmentKindOf(file.name)
      const live = drafts.filter(draft => draft.status !== 'error')
      let error = ''
      if (!rules || !upload) error = 'Files cannot be attached here.'
      else if (!kind || !rules.kinds.includes(kind)) error = 'This file type cannot be attached here.'
      else if (live.length >= rules.maxFiles) error = `Attach at most ${rules.maxFiles} files to one message.`
      else if (rules.maxBytes[kind] && file.size > (rules.maxBytes[kind] as number)) error = 'The file is too large.'
      else if (live.reduce((sum, draft) => sum + draft.size, 0) + file.size > rules.maxTotalBytes) error = 'The files are too large together for one message.'

      const previewUrl = !error && kind === 'image' && typeof URL !== 'undefined' && typeof URL.createObjectURL === 'function' ? URL.createObjectURL(file) : undefined
      const draft: DraftAttachment = { localId, name: file.name, size: file.size, kind, status: error ? 'error' : 'uploading', progress: 0, error: error || undefined, previewUrl }
      drafts = [...drafts, draft]
      if (error || !upload) return

      upload(file, { onProgress: fraction => this.patchAttachment(localId, { progress: fraction }) })
        .then(attachment => this.patchAttachment(localId, { status: 'ready', progress: 1, id: attachment.id }))
        .catch(cause => this.patchAttachment(localId, { status: 'error', error: errorMessage(cause, 'The upload failed.') }))
    })
    this.patchAssistant({ attachments: drafts })
  }

  /** Drop a picked file; one already uploaded is deleted on the site too. */
  detach(localId: string): void {
    const draft = this.state.assistant.attachments.find(item => item.localId === localId)
    if (!draft) return
    if (draft.previewUrl) URL.revokeObjectURL(draft.previewUrl)
    if (draft.id) void this.transport.request('assistant/attachments/delete', { id: draft.id }).catch(() => undefined)
    this.patchAssistant({ attachments: this.state.assistant.attachments.filter(item => item.localId !== localId) })
  }

  private patchAttachment(localId: string, patch: Partial<DraftAttachment>): void {
    const { attachments } = this.state.assistant
    if (!attachments.some(item => item.localId === localId)) return
    this.patchAssistant({ attachments: attachments.map(item => (item.localId === localId ? { ...item, ...patch } : item)) })
  }

  setDraft(draft: string): void {
    if (draft !== this.state.assistant.draft) this.patchAssistant({ draft })
  }

  // --- assistant ---------------------------------------------------------

  async loadView(): Promise<void> {
    if (this.state.assistant.busy) return
    this.patchAssistant({ busy: true, error: '' })
    try {
      this.applyView(await this.transport.request<ChatView>('assistant/view'), 'view')
    } catch (error) {
      this.failAssistant(error, 'Could not load the assistant.')
    }
    this.patchAssistant({ busy: false, loaded: true })
  }

  /**
   * Send a message. The user's text shows at once; a refused turn (422) gives
   * it back to the draft so it can be sent again.
   */
  async send(text: string, options: SendOptions = {}): Promise<ChatView | null> {
    const message = text.trim()
    const { assistant } = this.state
    // Files still uploading hold the message back; refused ones are left out.
    if (assistant.attachments.some(item => item.status === 'uploading')) return null
    const ready = assistant.attachments.filter(item => item.status === 'ready' && item.id)
    if ((!message && ready.length === 0) || assistant.busy || assistant.pending) return null

    this.patchAssistant({
      busy: true,
      error: '',
      reason: null,
      draft: '',
      messages: [...assistant.messages, {
        role: 'user',
        text: message,
        optimistic: true,
        ...(ready.length ? { attachments: ready.map(item => ({ id: item.id as string, name: item.name, kind: item.kind || 'text', size: item.size })) } : {}),
      }],
    })
    const before = assistant.messages
    try {
      const payload = {
        message,
        context: { ...(options.context || {}), ...(options.mentions ? { mentions: options.mentions } : {}) },
        ...(ready.length ? { attachments: ready.map(item => item.id) } : {}),
      }
      const view = this.streams()
        ? await this.streamTurn(payload)
        : await this.transport.request<ChatView>('assistant/chat', payload)
      this.applyView(view, 'chat')
      // Sent: the files belong to the message now.
      this.state.assistant.attachments.forEach((item) => { if (item.previewUrl) URL.revokeObjectURL(item.previewUrl) })
      this.patchAssistant({ busy: false, attachments: [] })
      // A new turn: every tool in it is new, even one the previous turn also ran.
      this.emitToolEvents([], view)
      return view
    } catch (error) {
      this.recover(error, 'chat', before, message)
      return null
    }
  }

  /** Whether turns are followed as they run (the site offers it and the transport can). */
  private streams(): boolean {
    return this.bootstrap.features.stream === true && !!this.transport.stream
  }

  /** Send through the stream, showing the reply as it comes; the live turn ends with the request. */
  private async streamTurn(payload: Record<string, unknown>, action: 'chat' | 'decide' = 'chat'): Promise<ChatView> {
    this.patchAssistant({ live: { text: '', tools: [] } })
    try {
      return await (this.transport.stream as NonNullable<Transport['stream']>)(payload, { onEvent: event => this.onStreamEvent(event), action })
    } finally {
      this.patchAssistant({ live: null })
    }
  }

  private onStreamEvent(event: StreamEvent): void {
    const live = this.state.assistant.live
    if (!live) return
    if (event.type === 'text') {
      this.patchAssistant({ live: { ...live, text: live.text + event.delta } })
    } else if (event.type === 'step') {
      // A new model step after some text starts a new paragraph.
      if (event.turn > 0 && live.text && !live.text.endsWith('\n\n')) this.patchAssistant({ live: { ...live, text: `${live.text}\n\n` } })
    } else if (event.type === 'tool') {
      const tool: ToolUse = {
        name: event.name,
        title: event.title,
        error: event.status === 'error',
        ...(event.status === 'skipped' ? { skipped: true } : {}),
        ...(event.status === 'running' || event.status === 'waiting' ? { running: true } : {}),
        id: event.id,
      }
      const index = live.tools.findIndex(item => item.id === event.id)
      const tools = index === -1 ? [...live.tools, tool] : live.tools.map((item, i) => (i === index ? tool : item))
      this.patchAssistant({ live: { ...live, tools } })
    }
  }

  /** Approve or cancel the action the assistant is waiting on. */
  async decide(approve: boolean, context: Record<string, unknown> = {}): Promise<ChatView | null> {
    const { assistant } = this.state
    if (assistant.busy || !assistant.pending) return null
    const name = assistant.pending.name
    this.patchAssistant({ busy: true, error: '', reason: null })
    const before = assistant.messages
    try {
      const view = this.streams()
        ? await this.streamTurn({ approve, context }, 'decide')
        : await this.transport.request<ChatView>('assistant/decide', { approve, context })
      this.applyView(view, 'decide')
      this.patchAssistant({ busy: false })
      this.emit({ type: approve ? 'approved' : 'rejected', name })
      this.emitToolEvents(before, view)
      return view
    } catch (error) {
      this.recover(error, 'decide', before)
      return null
    }
  }

  /** Start a new conversation; the current one stays in the history. */
  async reset(): Promise<void> {
    if (this.state.assistant.busy) return
    this.patchAssistant({ busy: true, error: '', reason: null })
    try {
      this.applyView(await this.transport.request<ChatView>('assistant/reset'), 'reset')
      this.emit({ type: 'conversation', id: null })
    } catch (error) {
      this.failAssistant(error, 'Could not start a new chat.', 'reset')
    }
    this.patchAssistant({ busy: false })
  }

  /** Continue an earlier conversation. */
  async open(id: string): Promise<boolean> {
    if (this.state.assistant.busy) return false
    this.patchAssistant({ busy: true, error: '', reason: null })
    let ok = false
    try {
      this.applyView(await this.transport.request<ChatView>('assistant/open', { id }), 'open')
      this.emit({ type: 'conversation', id })
      ok = true
    } catch (error) {
      this.failAssistant(error, 'That conversation no longer exists.', 'open')
    }
    this.patchAssistant({ busy: false })
    return ok
  }

  // --- history -------------------------------------------------------------

  async loadHistory(options: HistoryOptions = {}): Promise<void> {
    const current = this.state.conversations
    const search = options.search ?? current.search
    const archived = options.archived ?? current.archived
    const offset = options.more ? current.items.length : 0
    const ticket = ++this.historyTicket
    this.patch({ conversations: { ...current, status: 'loading', search, archived, error: null } })
    try {
      const list = await this.transport.request<ConversationList>('assistant/history', { search, archived, offset, limit: options.limit ?? 20 })
      if (ticket !== this.historyTicket) return
      this.patch({ conversations: { ...this.state.conversations, status: 'ready', items: offset > 0 ? [...this.state.conversations.items, ...list.items] : list.items, total: list.total } })
    } catch (error) {
      if (ticket !== this.historyTicket) return
      this.patch({ conversations: { ...this.state.conversations, status: 'error', error: errorMessage(error, 'Could not load the chat history.') } })
    }
  }

  async rename(id: string, title: string): Promise<boolean> {
    return this.updateConversation(id, { title })
  }

  async setFlags(id: string, flags: { pinned?: boolean, archived?: boolean }): Promise<boolean> {
    return this.updateConversation(id, flags)
  }

  /** Delete one conversation, or every one (`all`). */
  async forget(id: string): Promise<boolean> {
    try {
      const view = await this.transport.request<ChatView>('assistant/forget', { id })
      this.applyView(view, 'forget')
      const { conversations } = this.state
      this.patch({ conversations: { ...conversations, items: id === 'all' ? [] : conversations.items.filter(item => item.id !== id), total: id === 'all' ? 0 : Math.max(0, conversations.total - 1) } })
      return true
    } catch (error) {
      this.patch({ conversations: { ...this.state.conversations, error: errorMessage(error, 'Could not delete it.') } })
      return false
    }
  }

  // --- settings screens ----------------------------------------------------

  async loadAssistantSettings(): Promise<void> {
    await this.loadResource('assistantSettings', 'settings/assistant/get', 'Could not load the assistant settings.')
  }

  async saveAssistantSettings(input: AiSettingsInput): Promise<AssistantState> {
    const state = await this.transport.request<AssistantState>('settings/assistant/save', { settings: input })
    this.patch({ assistantSettings: { status: 'ready', data: state, error: null } })
    this.patchAssistant({ engine: state.engine, engineReason: state.engine_reason })
    return state
  }

  async loadMcp(): Promise<void> {
    await this.loadResource('mcp', 'settings/mcp/get', 'Could not load the MCP settings.')
  }

  async saveMcp(input: Record<string, unknown>): Promise<McpState> {
    return this.applyMcp(await this.transport.request<McpState>('settings/mcp/save', { settings: input }))
  }

  async loadActivity(query: ActivityQuery = {}): Promise<void> {
    const merged = { ...this.state.activity.query, ...query }
    this.patch({ activity: { ...this.state.activity, status: 'loading', query: merged, error: null } })
    try {
      const page = await this.transport.request<ActivityPage>('mcp/activity', merged as Record<string, unknown>)
      this.patch({ activity: { ...this.state.activity, status: 'ready', data: page, error: null } })
    } catch (error) {
      this.patch({ activity: { ...this.state.activity, status: 'error', error: errorMessage(error, 'Could not load the activity log.') } })
    }
  }

  async clearActivity(): Promise<void> {
    const page = await this.transport.request<ActivityPage>('mcp/activity/clear')
    this.patch({ activity: { ...this.state.activity, status: 'ready', data: page, error: null } })
  }

  async createApiKey(input: { name: string, access: string, expires_days?: number | null, unattended?: boolean }): Promise<{ key: string, record: ApiKeyRecord }> {
    const created = await this.transport.request<{ key: string, record: ApiKeyRecord, state: McpState }>('mcp/api-keys/create', input)
    this.applyMcp(created.state)
    return { key: created.key, record: created.record }
  }

  async revokeApiKey(id: string): Promise<void> {
    this.applyMcp(await this.transport.request<McpState>('mcp/api-keys/revoke', { id }))
  }

  async revokeConnection(id: string | 'all'): Promise<void> {
    this.applyMcp(await this.transport.request<McpState>(id === 'all' ? 'mcp/connections/revoke-all' : 'mcp/connections/revoke', id === 'all' ? {} : { id }))
  }

  // --- internals -----------------------------------------------------------

  private async updateConversation(id: string, fields: { title?: string, pinned?: boolean, archived?: boolean }): Promise<boolean> {
    try {
      const view = await this.transport.request<ChatView>('assistant/update', { id, ...fields })
      this.applyView(view, 'update')
      const { conversations } = this.state
      this.patch({ conversations: { ...conversations, items: conversations.items.map(item => (item.id === id ? { ...item, ...('title' in fields ? { title: fields.title || item.title } : {}), ...('pinned' in fields ? { pinned: !!fields.pinned } : {}), ...('archived' in fields ? { archived: !!fields.archived } : {}) } : item)) } })
      // An archived or unarchived chat belongs to the other list now.
      if ('archived' in fields) await this.loadHistory()
      return true
    } catch (error) {
      this.patch({ conversations: { ...this.state.conversations, error: errorMessage(error, 'Could not change the conversation.') } })
      return false
    }
  }

  private async loadResource<K extends 'assistantSettings' | 'mcp'>(key: K, action: 'settings/assistant/get' | 'settings/mcp/get', fallback: string): Promise<void> {
    this.patch({ [key]: { ...this.state[key], status: 'loading', error: null } } as Partial<AiKitState>)
    try {
      const data = await this.transport.request(action)
      this.patch({ [key]: { status: 'ready', data, error: null } } as Partial<AiKitState>)
      if (key === 'assistantSettings') {
        const state = data as AssistantState
        this.patchAssistant({ engine: state.engine, engineReason: state.engine_reason })
      }
    } catch (error) {
      this.patch({ [key]: { ...this.state[key], status: 'error', error: errorMessage(error, fallback) } } as Partial<AiKitState>)
    }
  }

  private applyMcp(state: McpState): McpState {
    this.patch({ mcp: { status: 'ready', data: state, error: null } })
    return state
  }

  /** Put a chat view (a reply, or the view a refused turn carried) into the state. */
  applyView(view: ChatView | null | undefined, source: ReplySource = 'view'): void {
    if (!view || typeof view !== 'object' || !Array.isArray(view.messages)) return
    const extra: Record<string, unknown> = {}
    Object.entries(view).forEach(([key, value]) => { if (!STANDARD_KEYS.has(key)) extra[key] = value })
    const previous = this.state.assistant
    this.patchAssistant({
      loaded: true,
      messages: view.messages,
      pending: view.pending ?? null,
      conversation: view.conversation ? { ...EMPTY_CONVERSATION, ...view.conversation } : previous.conversation,
      engine: view.engine === undefined ? previous.engine : view.engine,
      engineReason: view.engine_reason === undefined ? previous.engineReason : view.engine_reason,
      error: view.error || '',
      reason: view.reason || null,
      extra,
    })
    if (view.pending) this.emit({ type: 'pending', name: view.pending.name, pending: view.pending })
    this.emit({ type: 'reply', view, source })
  }

  /** A refused turn still carries the conversation; anything else is an error line. */
  private recover(error: unknown, source: ReplySource, before: ChatMessage[], draft = ''): void {
    const view = error instanceof AiKitError ? error.view : null
    if (view) {
      this.applyView(view as unknown as ChatView, source)
      this.patchAssistant({ busy: false, draft })
      return
    }
    this.patchAssistant({ busy: false, messages: before, draft, error: errorMessage(error, 'The assistant did not answer. Try again.'), reason: error instanceof AiKitError ? error.code : null })
  }

  private failAssistant(error: unknown, fallback: string, source: ReplySource = 'view'): void {
    const view = error instanceof AiKitError ? error.view : null
    if (view) this.applyView(view as unknown as ChatView, source)
    else this.patchAssistant({ error: errorMessage(error, fallback), reason: error instanceof AiKitError ? error.code : null })
  }

  private emitToolEvents(before: ChatMessage[], view: ChatView): void {
    if (view.pending) return
    const ran = toolsOfLastExchange(view.messages) || []
    const already = new Set((toolsOfLastExchange(before) || []).map(tool => `${tool.name}:${tool.error}`))
    ran.forEach((tool) => {
      const key = `${tool.name}:${tool.error}`
      if (already.has(key)) return
      already.add(key)
      this.emit({ type: tool.error ? 'failed' : 'executed', name: tool.name })
    })
  }

  private patchAssistant(patch: Partial<AssistantSlice>): void {
    this.patch({ assistant: { ...this.state.assistant, ...patch } })
  }

  private patch(patch: Partial<AiKitState>): void {
    this.state = { ...this.state, ...patch }
    this.listeners.forEach(listener => listener())
  }
}

export function createAiKitStore(transport: Transport, bootstrap: AiKitBootstrap): AiKitStore {
  return new AiKitStore(transport, bootstrap)
}
