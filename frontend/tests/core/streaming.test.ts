import { createAiKitStore } from '../../src/core/store'
import { AiKitError, createRestTransport } from '../../src/core/transport'
import { createMockTransport, sampleBootstrap } from '../mockTransport'

const streaming = (stream: boolean) => sampleBootstrap({ features: { ...sampleBootstrap().features, stream } })

describe('AiKitStore: streamed replies', () => {
  it('follows the turn live, then shows the saved reply', async () => {
    const mock = createMockTransport({ stream: true, latency: 2, reply: () => ({ text: 'Two forms here.', tools: [{ name: 'list-forms', title: 'List forms' }] }) })
    const store = createAiKitStore(mock.transport, streaming(true))
    const seen: string[] = []
    store.subscribe(() => {
      const { live } = store.getState().assistant
      if (live) seen.push(`${live.tools.map(tool => (tool.running ? `~${tool.title}` : tool.title)).join(',')}|${live.text}`)
    })

    const view = await store.send('How many forms?')

    expect(mock.calls.map(call => call.action)).toContain('assistant/stream')
    expect(seen).toContain('~List forms|')
    expect(seen).toContain('List forms|Two ')
    expect(seen[seen.length - 1]).toBe('List forms|Two forms here.')
    expect(store.getState().assistant.live).toBeNull()
    expect(view?.messages[1].text).toBe('Two forms here.')
  })

  it('streams the rest of a turn after an approval', async () => {
    const mock = createMockTransport({
      stream: true,
      reply: () => ({ text: 'Delete it?', pending: { name: 'delete-item', title: 'Delete item', summary: 'Delete item 7', input: {} } as never }),
      decided: () => ({ text: 'Item 7 is deleted.', tools: [{ name: 'delete-item', title: 'Delete item' }] }),
    })
    const store = createAiKitStore(mock.transport, streaming(true))
    await store.send('Delete item 7')
    const seen: string[] = []
    store.subscribe(() => {
      const { live } = store.getState().assistant
      if (live) seen.push(live.text)
    })

    const view = await store.decide(true)

    expect(mock.calls.map(call => call.action)).toContain('assistant/decide/stream')
    expect(seen).toContain('Item ')
    expect(store.getState().assistant.live).toBeNull()
    expect(view?.messages[view.messages.length - 1].text).toBe('Item 7 is deleted.')
    expect(store.getState().assistant.pending).toBeNull()
  })

  it('sends without streaming unless the plugin ships it', async () => {
    const mock = createMockTransport({ stream: true })
    await createAiKitStore(mock.transport, streaming(false)).send('hello')
    expect(mock.calls.map(call => call.action)).toEqual(['assistant/chat'])
  })
})

describe('createRestTransport().stream', () => {
  const sse = (blocks: string[]) => new Response(new ReadableStream({
    start(controller) {
      blocks.forEach(block => controller.enqueue(new TextEncoder().encode(block)))
      controller.close()
    },
  }), { status: 200, headers: { 'Content-Type': 'text/event-stream' } })

  it('hands over events, even split across chunks, and resolves with the view', async () => {
    const view = { messages: [{ role: 'assistant', text: 'Hi' }], pending: null, conversation: { id: 'c1' } }
    const fetch = vi.fn(async () => sse(['event: text\ndata: {"type":"text","del', 'ta":"Hi"}\n\n', `event: done\ndata: ${JSON.stringify({ status: 200, view })}\n\n`]))
    const events: unknown[] = []

    const result = await createRestTransport({ restBase: 'https://s/wp-json/x/v1/ai', nonce: 'n', fetch }).stream!({ message: 'hey' }, { onEvent: event => events.push(event) })

    expect(events).toEqual([{ type: 'text', delta: 'Hi' }])
    expect(result).toEqual(view)
    expect((fetch.mock.calls[0] as unknown[])[0]).toBe('https://s/wp-json/x/v1/ai/assistant/stream')
  })

  it('rejects a refused turn with its view, as chat does, and a cut-off stream', async () => {
    const refused = { error: 'Daily limit reached.', reason: 'daily_limit', messages: [] }
    const transport = createRestTransport({ restBase: 'https://s/ai', nonce: 'n', fetch: async () => sse([`event: done\ndata: ${JSON.stringify({ status: 422, view: refused })}\n\n`]) })
    await expect(transport.stream!({}, { onEvent: () => undefined })).rejects.toMatchObject({ code: 'daily_limit', status: 422, data: refused })

    const cut = createRestTransport({ restBase: 'https://s/ai', nonce: 'n', fetch: async () => sse(['event: text\ndata: {"delta":"Hi"}\n\n']) })
    await expect(cut.stream!({}, { onEvent: () => undefined })).rejects.toBeInstanceOf(AiKitError)
  })

  it('reports a refusal before the turn as a JSON error', async () => {
    const transport = createRestTransport({ restBase: 'https://s/ai', nonce: 'n', fetch: async () => new Response(JSON.stringify({ code: 'ai_empty_message', message: 'Type a message first.' }), { status: 400, headers: { 'Content-Type': 'application/json' } }) })
    await expect(transport.stream!({}, { onEvent: () => undefined })).rejects.toMatchObject({ code: 'ai_empty_message', status: 400 })
  })
})
