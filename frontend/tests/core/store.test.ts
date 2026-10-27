import { createAiKitStore, toolsOfLastExchange, type AiKitStore } from '../../src/core/store'
import type { AiKitEvent, PendingAction } from '../../src/core/types'
import { createMockTransport, sampleBootstrap, type MockTransport } from '../mockTransport'

const last = <T,>(list: T[]): T | undefined => list[list.length - 1]

const PENDING: PendingAction = { name: 'delete-item', title: 'Delete item', description: 'Deletes item 12 for good.', destructive: true, input: { id: 12 } }

function setup(options: Parameters<typeof createMockTransport>[0] = {}): { mock: MockTransport, store: AiKitStore, events: AiKitEvent[] } {
  const mock = createMockTransport(options)
  const store = createAiKitStore(mock.transport, sampleBootstrap())
  const events: AiKitEvent[] = []
  store.onEvent(event => events.push(event))
  return { mock, store, events }
}

describe('AiKitStore: chat', () => {
  it('loads the view once', async () => {
    const { mock, store } = setup()
    mock.seed({ id: 'c1', title: 'Earlier', messages: [{ role: 'user', text: 'a' }, { role: 'assistant', text: 'b' }] })
    mock.activeId = 'c1'

    await store.loadView()

    const { assistant } = store.getState()
    expect(assistant.loaded).toBe(true)
    expect(assistant.busy).toBe(false)
    expect(assistant.messages).toHaveLength(2)
    expect(assistant.conversation.id).toBe('c1')
  })

  it('shows the user message at once and applies the reply', async () => {
    const { store, events } = setup({ latency: 5 })
    const listener = vi.fn()
    store.subscribe(listener)

    const promise = store.send('  hello  ')

    let { assistant } = store.getState()
    expect(assistant.busy).toBe(true)
    expect(assistant.messages).toEqual([{ role: 'user', text: 'hello', optimistic: true }])
    expect(assistant.draft).toBe('')

    const view = await promise

    assistant = store.getState().assistant
    expect(view?.messages).toHaveLength(2)
    expect(assistant.busy).toBe(false)
    expect(assistant.messages).toEqual([{ role: 'user', text: 'hello' }, { role: 'assistant', text: 'You said: hello', tools: [] }])
    expect(assistant.conversation.id).not.toBeNull()
    expect(assistant.error).toBe('')
    expect(events.some(event => event.type === 'reply')).toBe(true)
    expect(listener).toHaveBeenCalled()
  })

  it('tags each reply with the request it answered', async () => {
    const { store, events } = setup()

    await store.loadView()
    await store.send('hello')
    await store.reset()

    const sources = events.filter(event => event.type === 'reply').map(event => (event as Extract<AiKitEvent, { type: 'reply' }>).source)
    expect(sources).toEqual(['view', 'chat', 'reset'])
  })

  it('ignores empty messages and messages while busy', async () => {
    const { mock, store } = setup({ latency: 5 })

    expect(await store.send('   ')).toBeNull()
    const first = store.send('one')
    expect(await store.send('two')).toBeNull()
    await first

    expect(mock.calls.filter(call => call.action === 'assistant/chat')).toHaveLength(1)
  })

  it('gives a refused turn (422) back to the draft and keeps its error and reason', async () => {
    const { mock, store, events } = setup()
    mock.assistantState.settings.enabled = false

    const view = await store.send('hello')

    const { assistant } = store.getState()
    expect(view).toBeNull()
    expect(assistant.busy).toBe(false)
    expect(assistant.draft).toBe('hello')
    expect(assistant.messages).toEqual([])
    expect(assistant.error).toBe('The AI assistant is switched off.')
    expect(assistant.reason).toBe('disabled')
    // The refused view is still a view: the panel shows what the server saved.
    expect(events.some(event => event.type === 'reply')).toBe(true)
  })

  it('restores the messages and reports a plain failure', async () => {
    const { store } = setup({ fail: { 'assistant/chat': new (await import('../../src/core/transport')).AiKitError('Server down', 'http_500', 500, null) } })

    await store.send('hello')

    const { assistant } = store.getState()
    expect(assistant.messages).toEqual([])
    expect(assistant.draft).toBe('hello')
    expect(assistant.error).toBe('Server down')
    expect(assistant.reason).toBe('http_500')
  })

  it('emits pending, then approved and executed on decide', async () => {
    const { store, events } = setup({
      reply: () => ({ text: 'This cannot be undone.', pending: PENDING }),
      decided: approve => (approve ? { text: 'Deleted.', tools: [{ name: 'delete-item', title: 'Delete item' }] } : { text: 'Cancelled.' }),
    })

    await store.send('delete item 12')

    expect(store.getState().assistant.pending).toEqual(PENDING)
    expect(events).toContainEqual({ type: 'pending', name: 'delete-item', pending: PENDING })
    // A second message is refused by the store while an action waits.
    expect(await store.send('anything')).toBeNull()

    const view = await store.decide(true)

    const { assistant } = store.getState()
    expect(view?.pending).toBeNull()
    expect(assistant.pending).toBeNull()
    expect(assistant.busy).toBe(false)
    expect(events).toContainEqual({ type: 'approved', name: 'delete-item' })
    expect(events).toContainEqual({ type: 'executed', name: 'delete-item' })
    expect(last(assistant.messages)?.text).toBe('Deleted.')
  })

  it('emits rejected on cancel and nothing on decide without a pending action', async () => {
    const { store, events } = setup({ reply: () => ({ text: 'Sure?', pending: PENDING }) })

    expect(await store.decide(false)).toBeNull()
    await store.send('delete')
    await store.decide(false)

    expect(events).toContainEqual({ type: 'rejected', name: 'delete-item' })
    expect(events.some(event => event.type === 'approved')).toBe(false)
  })

  it('emits executed and failed for the tools of the last exchange, once each', async () => {
    const { store, events } = setup({
      reply: () => ({ text: 'Done.', tools: [{ name: 'list-items', title: 'List' }, { name: 'rename-item', title: 'Rename', error: true }, { name: 'list-items', title: 'List' }] }),
    })

    await store.send('list and rename')

    const toolEvents = events.filter(event => event.type === 'executed' || event.type === 'failed')
    expect(toolEvents).toEqual([{ type: 'executed', name: 'list-items' }, { type: 'failed', name: 'rename-item' }])
  })

  it('emits a tool again when the next turn runs it again', async () => {
    const { store, events } = setup({ reply: () => ({ text: 'Undone.', tools: [{ name: 'undo-change', title: 'Undo' }] }) })

    await store.send('undo')
    await store.send('undo once more')

    expect(events.filter(event => event.type === 'executed')).toEqual([{ type: 'executed', name: 'undo-change' }, { type: 'executed', name: 'undo-change' }])
  })

  it('keeps host extras from the reply', async () => {
    const { store } = setup({ reply: () => ({ text: 'Preview ready.', extra: { preview: { id: 3 } } }) })

    await store.send('design')

    expect(store.getState().assistant.extra).toEqual({ preview: { id: 3 } })
  })

  it('tracks the draft', () => {
    const { store } = setup()
    const listener = vi.fn()
    store.subscribe(listener)

    store.setDraft('typing')
    store.setDraft('typing')

    expect(store.getState().assistant.draft).toBe('typing')
    expect(listener).toHaveBeenCalledTimes(1)
  })
})

describe('AiKitStore: conversations', () => {
  it('reset starts a new chat and announces it', async () => {
    const { store, events } = setup()
    await store.send('hello')
    expect(store.getState().assistant.conversation.id).not.toBeNull()

    await store.reset()

    expect(store.getState().assistant.conversation.id).toBeNull()
    expect(store.getState().assistant.messages).toEqual([])
    expect(events).toContainEqual({ type: 'conversation', id: null })
  })

  it('open continues an earlier conversation, and reports a missing one', async () => {
    const { mock, store, events } = setup()
    mock.seed({ id: 'c1', title: 'Earlier', messages: [{ role: 'user', text: 'a' }] })

    expect(await store.open('c1')).toBe(true)
    expect(store.getState().assistant.conversation).toEqual({ id: 'c1', title: 'Earlier', pinned: false, archived: false })
    expect(events).toContainEqual({ type: 'conversation', id: 'c1' })

    expect(await store.open('nope')).toBe(false)
    expect(store.getState().assistant.error).toBe('That conversation no longer exists.')
    expect(store.getState().assistant.busy).toBe(false)
  })

  it('loads the history, pages it, and lists archived chats apart', async () => {
    const { mock, store } = setup()
    for (let i = 1; i <= 25; i += 1) mock.seed({ id: `c${i}`, title: `Chat ${i}`, updated: 1000 + i })
    mock.seed({ id: 'old', title: 'Archived chat', context: { archived: true } })

    await store.loadHistory({ limit: 10 })
    let { conversations } = store.getState()
    expect(conversations.status).toBe('ready')
    expect(conversations.items).toHaveLength(10)
    expect(conversations.total).toBe(25)
    expect(conversations.items[0].title).toBe('Chat 25')

    await store.loadHistory({ more: true, limit: 10 })
    conversations = store.getState().conversations
    expect(conversations.items).toHaveLength(20)

    await store.loadHistory({ archived: true })
    conversations = store.getState().conversations
    expect(conversations.archived).toBe(true)
    expect(conversations.items.map(item => item.id)).toEqual(['old'])
    expect(conversations.total).toBe(1)

    await store.loadHistory({ archived: false, search: 'chat 2' })
    conversations = store.getState().conversations
    expect(conversations.search).toBe('chat 2')
    expect(conversations.items.map(item => item.title)).toEqual(['Chat 25', 'Chat 24', 'Chat 23', 'Chat 22', 'Chat 21', 'Chat 20', 'Chat 2'])
  })

  it('reports a failed history load', async () => {
    const { store } = setup({ fail: { 'assistant/history': new (await import('../../src/core/transport')).AiKitError('Nope', 'ai_forbidden', 403, null) } })

    await store.loadHistory()

    expect(store.getState().conversations.status).toBe('error')
    expect(store.getState().conversations.error).toBe('Nope')
  })

  it('rename updates the list and the server', async () => {
    const { mock, store } = setup()
    mock.seed({ id: 'c1', title: 'Old' })
    await store.loadHistory()

    expect(await store.rename('c1', 'New name')).toBe(true)

    expect(store.getState().conversations.items[0].title).toBe('New name')
    expect(mock.conversations.get('c1')?.title).toBe('New name')
    expect(last(mock.calls)).toEqual({ action: 'assistant/update', payload: { id: 'c1', title: 'New name' } })
  })

  it('setFlags pins in place and moves an archived chat to the other list', async () => {
    const { mock, store } = setup()
    mock.seed({ id: 'c1', title: 'One', updated: 10 })
    mock.seed({ id: 'c2', title: 'Two', updated: 20 })
    await store.loadHistory()

    expect(await store.setFlags('c1', { pinned: true })).toBe(true)
    expect(store.getState().conversations.items.find(item => item.id === 'c1')?.pinned).toBe(true)

    expect(await store.setFlags('c2', { archived: true })).toBe(true)
    let { conversations } = store.getState()
    expect(conversations.items.map(item => item.id)).toEqual(['c1'])

    await store.loadHistory({ archived: true })
    conversations = store.getState().conversations
    expect(conversations.items.map(item => item.id)).toEqual(['c2'])
    expect(conversations.items[0].archived).toBe(true)
  })

  it('forget removes one chat, or every one', async () => {
    const { mock, store } = setup()
    mock.seed({ id: 'c1', title: 'One' })
    mock.seed({ id: 'c2', title: 'Two' })
    mock.seed({ id: 'c3', title: 'Three' })
    await store.loadHistory()
    await store.open('c1')

    expect(await store.forget('c1')).toBe(true)
    let { conversations, assistant } = store.getState()
    expect(conversations.items.map(item => item.id).sort()).toEqual(['c2', 'c3'])
    expect(conversations.total).toBe(2)
    // The open chat was the deleted one: the view is empty again.
    expect(assistant.conversation.id).toBeNull()

    expect(await store.forget('all')).toBe(true)
    ;({ conversations, assistant } = store.getState())
    expect(conversations.items).toEqual([])
    expect(conversations.total).toBe(0)
    expect(mock.conversations.size).toBe(0)
  })

  it('keeps an error from a failed update on the slice', async () => {
    const { store } = setup()
    await store.loadHistory()

    expect(await store.rename('missing', 'x')).toBe(false)

    expect(store.getState().conversations.error).toBe('That conversation no longer exists.')
  })
})

describe('AiKitStore: settings and activity', () => {
  it('loads and saves the assistant settings, updating the engine', async () => {
    const { mock, store } = setup()

    await store.loadAssistantSettings()
    expect(store.getState().assistantSettings.status).toBe('ready')
    expect(store.getState().assistantSettings.data?.settings.max_turns).toBe(6)

    mock.assistantState.engine = { key: 'claude', label: 'Claude' }
    const saved = await store.saveAssistantSettings({ max_turns: 9, api_key: 'sk-1234' })

    expect(saved.settings.max_turns).toBe(9)
    expect(saved.keys.claude).toEqual({ set: true, hint: '…1234' })
    expect(store.getState().assistant.engine).toEqual({ key: 'claude', label: 'Claude' })
    expect(last(mock.calls)?.payload).toEqual({ settings: { max_turns: 9, api_key: 'sk-1234' } })
  })

  it('loads the activity with a merged query and clears it', async () => {
    const { mock, store } = setup()
    mock.audit({ tool: 'list-items' })
    mock.audit({ tool: 'rename-item', channel: 'mcp' })

    await store.loadActivity({ channel: 'assistant', per_page: 5 })

    let { activity } = store.getState()
    expect(activity.status).toBe('ready')
    expect(activity.query).toEqual({ page: 1, per_page: 5, channel: 'assistant' })
    expect(activity.data?.rows.map(row => row.tool)).toEqual(['list-items'])

    await store.clearActivity()
    activity = store.getState().activity
    expect(activity.data?.rows).toEqual([])
  })

  it('manages API keys and connections through the MCP state', async () => {
    const { store } = setup()
    await store.loadMcp()

    const created = await store.createApiKey({ name: 'CI', access: 'read' })
    expect(created.key).toContain('secretvalue')
    expect(store.getState().mcp.data?.api_keys.keys[0].name).toBe('CI')

    await store.revokeApiKey(created.record.id)
    expect(store.getState().mcp.data?.api_keys.keys[0].status).toBe('revoked')

    await store.revokeConnection('c1')
    expect(store.getState().mcp.data?.oauth.connections).toEqual([])
  })
})

describe('toolsOfLastExchange', () => {
  it('returns the tools after the last user message', () => {
    expect(toolsOfLastExchange([
      { role: 'user', text: 'a' },
      { role: 'assistant', text: 'b', tools: [{ name: 'old', title: 'Old', error: false }] },
      { role: 'user', text: 'c' },
      { role: 'assistant', text: 'd', tools: [{ name: 'new', title: 'New', error: false }] },
      { role: 'assistant', text: 'e' },
    ])?.map(tool => tool.name)).toEqual(['new'])
  })
})
