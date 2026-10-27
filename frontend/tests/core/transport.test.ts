import { AiKitError, createRestTransport, transportFromBootstrap } from '../../src/core/transport'
import { sampleBootstrap } from '../mockTransport'

type Call = { url: string, init: RequestInit }

/** A Response-shaped object: enough for the transport, without the runtime's Response. */
function fakeResponse(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  const text = body === undefined ? '' : (typeof body === 'string' ? body : JSON.stringify(body))
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: { get: (name: string) => headers[name] ?? headers[name.toLowerCase()] ?? null },
    text: async () => text,
  } as unknown as Response
}

function fakeFetch(...responses: (Response | Error)[]) {
  const calls: Call[] = []
  const queue = [...responses]
  const fetchImpl = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    calls.push({ url: String(input), init: init || {} })
    const next = queue.shift() || fakeResponse(200, {})
    if (next instanceof Error) throw next
    return next
  }) as unknown as typeof fetch
  return { fetchImpl, calls }
}

/** The AiKitError a request rejects with. */
async function failure(promise: Promise<unknown>): Promise<AiKitError> {
  try {
    await promise
  } catch (error) {
    return error as AiKitError
  }
  throw new Error('The request did not fail.')
}

const BASE = 'https://example.test/wp-json/bit-demo/v1/ai'

describe('createRestTransport', () => {
  it('sends a GET with the payload as a query string and the nonce header', async () => {
    const { fetchImpl, calls } = fakeFetch(fakeResponse(200, { items: [], total: 0 }))
    const transport = createRestTransport({ restBase: `${BASE}/`, nonce: 'n1', fetch: fetchImpl })

    const result = await transport.request('assistant/history', { search: 'x y', archived: true, offset: 0, limit: 20, empty: '', skip: null })

    expect(result).toEqual({ items: [], total: 0 })
    expect(calls[0].url).toBe(`${BASE}/assistant/history?search=x+y&archived=1&offset=0&limit=20`)
    expect(calls[0].init.method).toBe('GET')
    expect(calls[0].init.credentials).toBe('same-origin')
    expect((calls[0].init.headers as Record<string, string>)['X-WP-Nonce']).toBe('n1')
    expect(calls[0].init.body).toBeUndefined()
  })

  it('joins the query with & under plain permalinks', async () => {
    const { fetchImpl, calls } = fakeFetch()
    const transport = createRestTransport({ restBase: 'https://example.test/?rest_route=/bit-demo/v1/ai', nonce: 'n', fetch: fetchImpl })

    await transport.request('mcp/activity', { page: 2 })

    expect(calls[0].url).toBe('https://example.test/?rest_route=/bit-demo/v1/ai/mcp/activity&page=2')
  })

  it('posts JSON bodies', async () => {
    const { fetchImpl, calls } = fakeFetch(fakeResponse(200, { messages: [] }))
    const transport = createRestTransport({ restBase: BASE, nonce: 'n', fetch: fetchImpl })

    await transport.request('assistant/chat', { message: 'hi', context: { screen: 'x' } })

    expect(calls[0].url).toBe(`${BASE}/assistant/chat`)
    expect(calls[0].init.method).toBe('POST')
    expect((calls[0].init.headers as Record<string, string>)['Content-Type']).toBe('application/json')
    expect(JSON.parse(String(calls[0].init.body))).toEqual({ message: 'hi', context: { screen: 'x' } })
  })

  it('substitutes {id} into the path and keeps it out of the body or query', async () => {
    const { fetchImpl, calls } = fakeFetch()
    const transport = createRestTransport({ restBase: BASE, nonce: 'n', fetch: fetchImpl })

    await transport.request('assistant/open', { id: 'a/b' })
    await transport.request('assistant/update', { id: '7', title: 'New' })
    await transport.request('mcp/api-keys/revoke', { id: 'k1' })

    expect(calls[0].url).toBe(`${BASE}/assistant/conversations/a%2Fb/open`)
    expect(JSON.parse(String(calls[0].init.body))).toEqual({})
    expect(calls[1].init.method).toBe('PATCH')
    expect(calls[1].url).toBe(`${BASE}/assistant/conversations/7`)
    expect(JSON.parse(String(calls[1].init.body))).toEqual({ title: 'New' })
    expect(calls[2].init.method).toBe('DELETE')
    expect(calls[2].url).toBe(`${BASE}/mcp/api-keys/k1`)
  })

  it('turns a WP_Error body into an AiKitError with code and status', async () => {
    const { fetchImpl } = fakeFetch(fakeResponse(403, { code: 'ai_forbidden', message: 'Not allowed.', data: { status: 403 } }))
    const transport = createRestTransport({ restBase: BASE, nonce: 'n', fetch: fetchImpl })

    const error = await failure(transport.request('assistant/view'))

    expect(error).toBeInstanceOf(AiKitError)
    expect(error.code).toBe('ai_forbidden')
    expect(error.status).toBe(403)
    expect(error.message).toBe('Not allowed.')
    expect(error.view).toBeNull()
  })

  it('exposes the chat view a refused turn carries', async () => {
    const view = { messages: [{ role: 'user', text: 'hi' }], pending: null, conversation: { id: '1', title: 'hi', pinned: false, archived: false }, error: 'Switched off.', reason: 'disabled' }
    const { fetchImpl } = fakeFetch(fakeResponse(422, view))
    const transport = createRestTransport({ restBase: BASE, nonce: 'n', fetch: fetchImpl })

    const error = await failure(transport.request('assistant/chat', { message: 'hi' }))

    expect(error.status).toBe(422)
    expect(error.code).toBe('disabled')
    expect(error.message).toBe('Switched off.')
    expect(error.view).toEqual(view)
  })

  it('falls back to a generic message and http_<status> code for a non-JSON failure', async () => {
    const { fetchImpl } = fakeFetch(fakeResponse(500, '<html>oops</html>'))
    const transport = createRestTransport({ restBase: BASE, nonce: 'n', fetch: fetchImpl })

    const error = await failure(transport.request('assistant/view'))

    expect(error.code).toBe('http_500')
    expect(error.message).toBe('Request failed (500)')
    expect(error.data).toBe('<html>oops</html>')
  })

  it('reports a network failure as status 0', async () => {
    const { fetchImpl } = fakeFetch(new TypeError('Failed to fetch'))
    const transport = createRestTransport({ restBase: BASE, nonce: 'n', fetch: fetchImpl })

    const error = await failure(transport.request('assistant/view'))

    expect(error).toBeInstanceOf(AiKitError)
    expect(error.status).toBe(0)
    expect(error.code).toBe('network_error')
    expect(error.data).toBeInstanceOf(TypeError)
  })

  it('refreshes the nonce from the response header', async () => {
    const { fetchImpl, calls } = fakeFetch(fakeResponse(200, {}, { 'X-WP-Nonce': 'n2' }), fakeResponse(200, {}))
    const onNonce = vi.fn()
    const transport = createRestTransport({ restBase: BASE, nonce: 'n1', fetch: fetchImpl, onNonce })

    await transport.request('assistant/view')
    await transport.request('assistant/view')

    expect(onNonce).toHaveBeenCalledWith('n2')
    expect(onNonce).toHaveBeenCalledTimes(1)
    expect((calls[1].init.headers as Record<string, string>)['X-WP-Nonce']).toBe('n2')
  })

  it('passes the abort signal through', async () => {
    const { fetchImpl, calls } = fakeFetch()
    const transport = createRestTransport({ restBase: BASE, nonce: 'n', fetch: fetchImpl })
    const controller = new AbortController()

    await transport.request('assistant/view', {}, { signal: controller.signal })

    expect(calls[0].init.signal).toBe(controller.signal)
  })
})

describe('transportFromBootstrap', () => {
  it('builds the REST transport from the bootstrap', async () => {
    const { fetchImpl, calls } = fakeFetch()
    const transport = transportFromBootstrap(sampleBootstrap(), fetchImpl)

    await transport!.request('assistant/view')

    expect(calls[0].url).toBe(`${BASE}/assistant/view`)
    expect((calls[0].init.headers as Record<string, string>)['X-WP-Nonce']).toBe('nonce')
  })

  it('is null without REST routes', () => {
    expect(transportFromBootstrap(sampleBootstrap({ restBase: null }))).toBeNull()
  })
})
