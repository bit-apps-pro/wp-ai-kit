import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { AiKitProvider } from '../../src/core/context'
import { useAssistant } from '../../src/core/hooks/useAssistant'
import { useConversations } from '../../src/core/hooks/useConversations'
import { useAiSettings } from '../../src/core/hooks/useSettings'
import type { AiKitEvent } from '../../src/core/types'
import type { Transport } from '../../src/core/transport'
import { createMockTransport, sampleBootstrap, type MockOptions } from '../mockTransport'

/** Replies as fresh JSON, as over the wire: the mock hands back its live, mutated state objects. */
function wire(transport: Transport): Transport {
  return { request: (action, payload, options) => transport.request(action, payload, options).then(reply => JSON.parse(JSON.stringify(reply ?? null))) }
}

const last = <T,>(list: T[]): T | undefined => list[list.length - 1]

function setup(options: MockOptions = {}) {
  const mock = createMockTransport(options)
  const bootstrap = options.bootstrap || sampleBootstrap()
  const wrapper = ({ children }: { children: ReactNode }) => <AiKitProvider bootstrap={bootstrap} transport={wire(mock.transport)}>{children}</AiKitProvider>
  return { mock, wrapper }
}

describe('useAssistant', () => {
  it('loads the view on mount and sends a message', async () => {
    const { mock, wrapper } = setup()
    mock.seed({ id: 'c1', title: 'Earlier', messages: [{ role: 'user', text: 'a' }, { role: 'assistant', text: 'b' }] })
    mock.activeId = 'c1'
    const events: AiKitEvent[] = []

    const { result } = renderHook(() => useAssistant({ context: { screen: 'home' }, onEvent: event => events.push(event) }), { wrapper })

    expect(result.current.loaded).toBe(false)
    await waitFor(() => expect(result.current.loaded).toBe(true))
    expect(mock.calls[0].action).toBe('assistant/view')
    expect(result.current.messages).toHaveLength(2)
    expect(result.current.conversation.id).toBe('c1')
    expect(result.current.available).toBe(true)

    await act(async () => { await result.current.send('hello', { mentions: [{ type: 'item', id: 3 }] }) })

    expect(result.current.messages).toHaveLength(4)
    expect(last(result.current.messages)?.text).toBe('You said: hello')
    expect(result.current.busy).toBe(false)
    const chat = mock.calls.find(call => call.action === 'assistant/chat')
    expect(chat?.payload).toEqual({ message: 'hello', context: { screen: 'home', mentions: [{ type: 'item', id: 3 }] } })
    expect(events.some(event => event.type === 'reply')).toBe(true)
  })

  it('reads a context getter at send time', async () => {
    const { mock, wrapper } = setup()
    let screen = 'first'

    const { result } = renderHook(() => useAssistant({ context: () => ({ screen }) }), { wrapper })
    await waitFor(() => expect(result.current.loaded).toBe(true))
    screen = 'second'

    await act(async () => { await result.current.send('hi') })

    expect(mock.calls.find(call => call.action === 'assistant/chat')?.payload.context).toEqual({ screen: 'second' })
  })

  it('does not load when told not to, or when the user may not use it', async () => {
    const { mock, wrapper } = setup()
    renderHook(() => useAssistant({ autoLoad: false }), { wrapper })
    await act(async () => { await Promise.resolve() })
    expect(mock.calls).toHaveLength(0)

    const denied = setup({ bootstrap: sampleBootstrap({ canUse: false }) })
    const { result } = renderHook(() => useAssistant(), { wrapper: denied.wrapper })
    await act(async () => { await Promise.resolve() })
    expect(denied.mock.calls).toHaveLength(0)
    expect(result.current.canUse).toBe(false)
    expect(result.current.available).toBe(false)
  })

  it('decides a pending action and resets', async () => {
    const pending = { name: 'delete-item', title: 'Delete', description: '', destructive: true, input: {} }
    const { wrapper } = setup({ reply: () => ({ text: 'Sure?', pending }) })
    const { result } = renderHook(() => useAssistant(), { wrapper })
    await waitFor(() => expect(result.current.loaded).toBe(true))

    await act(async () => { await result.current.send('delete') })
    expect(result.current.pending).toEqual(pending)

    await act(async () => { await result.current.decide(true) })
    expect(result.current.pending).toBeNull()
    expect(last(result.current.messages)?.text).toBe('Done.')

    await act(async () => { await result.current.reset() })
    expect(result.current.messages).toEqual([])
    expect(result.current.conversation.id).toBeNull()
  })
})

describe('useConversations', () => {
  it('loads the first page on mount and pages with loadMore', async () => {
    const { mock, wrapper } = setup()
    for (let i = 1; i <= 25; i += 1) mock.seed({ id: `c${i}`, title: `Chat ${i}`, updated: 1000 + i })

    const { result } = renderHook(() => useConversations({ pageSize: 10 }), { wrapper })

    await waitFor(() => expect(result.current.status).toBe('ready'))
    expect(result.current.items).toHaveLength(10)
    expect(result.current.total).toBe(25)
    expect(result.current.hasMore).toBe(true)
    expect(mock.calls[0]).toEqual({ action: 'assistant/history', payload: { search: '', archived: false, offset: 0, limit: 10 } })

    await act(async () => { await result.current.loadMore() })
    expect(result.current.items).toHaveLength(20)
    expect(last(mock.calls)?.payload).toMatchObject({ offset: 10, limit: 10 })

    await act(async () => { await result.current.loadMore() })
    expect(result.current.items).toHaveLength(25)
    expect(result.current.hasMore).toBe(false)
  })

  it('switches to archived, searches after a debounce, and opens a chat', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    try {
      const { mock, wrapper } = setup()
      mock.seed({ id: 'c1', title: 'Budget plan' })
      mock.seed({ id: 'c2', title: 'Holiday', context: { archived: true } })

      const { result } = renderHook(() => useConversations({ searchDelay: 50 }), { wrapper })
      await waitFor(() => expect(result.current.status).toBe('ready'))
      expect(result.current.items.map(item => item.id)).toEqual(['c1'])

      await act(async () => { await result.current.setArchived(true) })
      expect(result.current.archived).toBe(true)
      expect(result.current.items.map(item => item.id)).toEqual(['c2'])
      await act(async () => { await result.current.setArchived(false) })

      act(() => { result.current.setSearch('bud') })
      expect(mock.calls.filter(call => call.action === 'assistant/history')).toHaveLength(3)
      await act(async () => { vi.advanceTimersByTime(60) })
      await waitFor(() => expect(result.current.search).toBe('bud'))
      expect(result.current.items.map(item => item.title)).toEqual(['Budget plan'])

      await act(async () => { await result.current.open('c2') })
      expect(result.current.currentId).toBe('c2')
    } finally {
      vi.useRealTimers()
    }
  })

  it('renames, pins, archives and forgets', async () => {
    const { mock, wrapper } = setup()
    mock.seed({ id: 'c1', title: 'One' })
    mock.seed({ id: 'c2', title: 'Two' })
    const { result } = renderHook(() => useConversations(), { wrapper })
    await waitFor(() => expect(result.current.status).toBe('ready'))

    await act(async () => { await result.current.rename('c1', 'Renamed') })
    expect(result.current.items.find(item => item.id === 'c1')?.title).toBe('Renamed')

    await act(async () => { await result.current.setPinned('c1', true) })
    expect(result.current.items.find(item => item.id === 'c1')?.pinned).toBe(true)

    await act(async () => { await result.current.setArchivedFlag('c2', true) })
    expect(result.current.items.map(item => item.id)).toEqual(['c1'])

    await act(async () => { await result.current.forget('c1') })
    expect(result.current.items).toEqual([])
  })
})

describe('useAiSettings', () => {
  it('loads the settings into a draft and saves it', async () => {
    const { mock, wrapper } = setup()
    const { result } = renderHook(() => useAiSettings(), { wrapper })

    expect(result.current.loading).toBe(true)
    await waitFor(() => expect(result.current.draft).not.toBeNull())
    expect(result.current.dirty).toBe(false)

    act(() => { result.current.update({ max_turns: 8 }) })
    expect(result.current.dirty).toBe(true)
    expect(result.current.state?.settings.max_turns).toBe(6)

    act(() => { result.current.discard() })
    expect(result.current.dirty).toBe(false)

    act(() => { result.current.update({ max_turns: 8 }) })
    await act(async () => { await result.current.save({ api_key: 'sk-abcd' }) })

    expect(result.current.dirty).toBe(false)
    expect(result.current.state?.settings.max_turns).toBe(8)
    expect(last(mock.calls)?.payload).toMatchObject({ settings: { max_turns: 8, api_key: 'sk-abcd' } })
  })
})
