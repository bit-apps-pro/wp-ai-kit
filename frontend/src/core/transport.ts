import type { AiKitBootstrap, Attachment, ChatView, StreamEvent } from './types'

/**
 * Everything the UI asks the site for, by name. The default transport maps
 * each action to the kit's REST route; a host with its own endpoints (admin-ajax,
 * say) supplies a transport that answers the same names with the same payloads.
 */
export type AiKitAction =
  | 'assistant/view'
  | 'assistant/chat'
  | 'assistant/decide'
  | 'assistant/reset'
  | 'assistant/history'
  | 'assistant/open'
  | 'assistant/update'
  | 'assistant/forget'
  | 'assistant/attachments/delete'
  | 'settings/assistant/get'
  | 'settings/assistant/save'
  | 'settings/mcp/get'
  | 'settings/mcp/save'
  | 'mcp/state'
  | 'mcp/activity'
  | 'mcp/activity/clear'
  | 'mcp/api-keys/list'
  | 'mcp/api-keys/create'
  | 'mcp/api-keys/revoke'
  | 'mcp/connections/list'
  | 'mcp/connections/revoke'
  | 'mcp/connections/revoke-all'

export interface RequestOptions {
  signal?: AbortSignal
}

export interface UploadOptions extends RequestOptions {
  /** 0–1 as the bytes go out. */
  onProgress?: (fraction: number) => void
}

export interface StreamOptions extends RequestOptions {
  /** Each event of the turn as it happens: text pieces, tools starting and finishing. */
  onEvent: (event: StreamEvent) => void
  /** `decide`: the payload is a decision (`assistant/decide/stream`), not a message. */
  action?: 'chat' | 'decide'
}

export interface Transport {
  request<T = unknown>(action: AiKitAction, payload?: Record<string, unknown>, options?: RequestOptions): Promise<T>
  /** Upload one file for the next message; absent on a transport without attachments. */
  upload?(file: File, options?: UploadOptions): Promise<Attachment>
  /**
   * Send a chat message and follow the turn as it runs (`assistant/stream`), or with
   * `action: 'decide'` a decision (`assistant/decide/stream`). Resolves with the chat
   * view, as `request('assistant/chat')` would, and rejects the same way.
   * Absent: the store sends without streaming.
   */
  stream?(payload: Record<string, unknown>, options: StreamOptions): Promise<ChatView>
}

/**
 * A failed request. `status` is the HTTP status (0 when the network failed),
 * `code` WordPress's error code (`rest_cookie_invalid_nonce`, `ai_forbidden`, …)
 * and `data` the body, when there was one: a failed chat turn (422) carries the
 * conversation view there, so the panel can show what was saved.
 */
export class AiKitError extends Error {
  constructor(
    message: string,
    public readonly code: string,
    public readonly status: number,
    public readonly data: unknown = null,
  ) {
    super(message)
    this.name = 'AiKitError'
  }

  /** The reply was a chat view (a refused turn), not a bare error. */
  get view(): Record<string, unknown> | null {
    return this.data && typeof this.data === 'object' && 'messages' in (this.data as object) ? (this.data as Record<string, unknown>) : null
  }
}

type Method = 'GET' | 'POST' | 'PATCH' | 'DELETE'

/** action → [method, path]; `{id}` is taken from the payload and removed from it. */
const ROUTES: Record<AiKitAction, [Method, string]> = {
  'assistant/view': ['GET', 'assistant/view'],
  'assistant/chat': ['POST', 'assistant/chat'],
  'assistant/decide': ['POST', 'assistant/decide'],
  'assistant/reset': ['POST', 'assistant/reset'],
  'assistant/history': ['GET', 'assistant/history'],
  'assistant/open': ['POST', 'assistant/conversations/{id}/open'],
  'assistant/update': ['PATCH', 'assistant/conversations/{id}'],
  'assistant/forget': ['DELETE', 'assistant/conversations/{id}'],
  'assistant/attachments/delete': ['DELETE', 'assistant/attachments/{id}'],
  'settings/assistant/get': ['GET', 'settings/assistant'],
  'settings/assistant/save': ['POST', 'settings/assistant'],
  'settings/mcp/get': ['GET', 'settings/mcp'],
  'settings/mcp/save': ['POST', 'settings/mcp'],
  'mcp/state': ['GET', 'mcp/state'],
  'mcp/activity': ['GET', 'mcp/activity'],
  'mcp/activity/clear': ['DELETE', 'mcp/activity'],
  'mcp/api-keys/list': ['GET', 'mcp/api-keys'],
  'mcp/api-keys/create': ['POST', 'mcp/api-keys'],
  'mcp/api-keys/revoke': ['DELETE', 'mcp/api-keys/{id}'],
  'mcp/connections/list': ['GET', 'mcp/connections'],
  'mcp/connections/revoke': ['DELETE', 'mcp/connections/{id}'],
  'mcp/connections/revoke-all': ['DELETE', 'mcp/connections'],
}

export interface RestTransportOptions {
  /** `bootstrap.restBase`, e.g. `https://site/wp-json/bitform/v1/ai`. */
  restBase: string
  /** The `wp_rest` nonce from the bootstrap. */
  nonce: string
  fetch?: typeof fetch
  /** Called with the new nonce when a reply carries `X-WP-Nonce` (WordPress refreshes it). */
  onNonce?: (nonce: string) => void
}

/** A failed reply as an AiKitError: WordPress's `{ code, message }`, or a refused chat view's `{ error, reason }`. */
function errorFrom(status: number, body: unknown): AiKitError {
  const record = body && typeof body === 'object' ? (body as Record<string, unknown>) : {}
  const message = typeof record.message === 'string' ? record.message
    : typeof record.error === 'string' ? record.error
      : `Request failed (${status})`
  const code = typeof record.code === 'string' ? record.code
    : typeof record.reason === 'string' ? record.reason
      : `http_${status}`
  return new AiKitError(message, code, status, body)
}

/** The default transport: the kit's `<ns>/ai/*` REST routes with WordPress cookie auth. */
export function createRestTransport(options: RestTransportOptions): Transport {
  let nonce = options.nonce
  const doFetch = options.fetch || ((input, init) => fetch(input, init))
  const base = options.restBase.replace(/\/+$/, '')

  return {
    async request<T>(action: AiKitAction, payload: Record<string, unknown> = {}, requestOptions: RequestOptions = {}): Promise<T> {
      const [method, template] = ROUTES[action]
      const { id, ...rest } = payload
      const path = template.replace('{id}', encodeURIComponent(String(id ?? '')))
      // Plain permalinks keep the REST route in a query string: more parameters join with `&`.
      let url = `${base}/${path}`
      const init: RequestInit = {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-WP-Nonce': nonce },
        signal: requestOptions.signal,
      }
      if (method === 'GET' || method === 'DELETE') {
        const query = new URLSearchParams()
        Object.entries(rest).forEach(([key, value]) => {
          if (value === undefined || value === null || value === '') return
          query.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value))
        })
        const qs = query.toString()
        if (qs) url += (url.includes('?') ? '&' : '?') + qs
      } else {
        init.headers = { ...init.headers, 'Content-Type': 'application/json' }
        init.body = JSON.stringify(rest)
      }

      let response: Response
      try {
        response = await doFetch(url, init)
      } catch (cause) {
        throw new AiKitError('The request failed. Check your connection and try again.', 'network_error', 0, cause)
      }

      const refreshed = response.headers.get('X-WP-Nonce')
      if (refreshed && refreshed !== nonce) {
        nonce = refreshed
        options.onNonce?.(refreshed)
      }

      const text = await response.text()
      let body: unknown = null
      if (text) {
        try {
          body = JSON.parse(text)
        } catch {
          body = text
        }
      }

      if (response.ok) return body as T

      throw errorFrom(response.status, body)
    },

    async stream(payload: Record<string, unknown>, streamOptions: StreamOptions): Promise<ChatView> {
      let response: Response
      try {
        response = await doFetch(`${base}/assistant/${streamOptions.action === 'decide' ? 'decide/stream' : 'stream'}`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'text/event-stream', 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
          body: JSON.stringify(payload),
          signal: streamOptions.signal,
        })
      } catch (cause) {
        throw new AiKitError('The request failed. Check your connection and try again.', 'network_error', 0, cause)
      }

      // Refused before the turn started: a plain JSON error.
      if (!response.ok || !response.body || !(response.headers.get('Content-Type') || '').includes('text/event-stream')) {
        const text = await response.text()
        let body: unknown = text
        try { body = text ? JSON.parse(text) : null } catch { /* not JSON */ }
        throw errorFrom(response.status, body)
      }

      const reader = response.body.getReader()
      const decoder = new TextDecoder()
      let buffer = ''
      let done: { status: number, view: ChatView } | null = null
      const handle = (block: string) => {
        let name = 'message'
        const data: string[] = []
        block.split('\n').forEach((line) => {
          if (line.startsWith('event:')) name = line.slice(6).trim()
          else if (line.startsWith('data:')) data.push(line.slice(5).trim())
        })
        if (!data.length) return
        let parsed: Record<string, unknown>
        try { parsed = JSON.parse(data.join('\n')) as Record<string, unknown> } catch { return }
        if (name === 'done') done = parsed as unknown as { status: number, view: ChatView }
        else streamOptions.onEvent({ ...parsed, type: name } as StreamEvent)
      }

      try {
        for (;;) {
          // eslint-disable-next-line no-await-in-loop
          const chunk = await reader.read()
          if (chunk.done) break
          buffer += decoder.decode(chunk.value, { stream: true })
          let end = buffer.indexOf('\n\n')
          while (end !== -1) {
            handle(buffer.slice(0, end).replace(/\r/g, ''))
            buffer = buffer.slice(end + 2)
            end = buffer.indexOf('\n\n')
          }
        }
        if (buffer.trim()) handle(buffer.replace(/\r/g, ''))
      } catch (cause) {
        throw new AiKitError('The reply was cut off. The conversation was still saved; reload it to see the answer.', 'stream_interrupted', 0, cause)
      }

      const finished = done as { status: number, view: ChatView } | null
      if (!finished) throw new AiKitError('The reply was cut off. The conversation was still saved; reload it to see the answer.', 'stream_interrupted', 0, null)
      if (finished.status >= 400) throw errorFrom(finished.status, finished.view)
      return finished.view
    },

    // XMLHttpRequest, not fetch: only it reports upload progress.
    upload(file: File, uploadOptions: UploadOptions = {}): Promise<Attachment> {
      return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest()
        xhr.open('POST', `${base}/assistant/attachments`)
        xhr.withCredentials = true
        xhr.setRequestHeader('X-WP-Nonce', nonce)
        xhr.setRequestHeader('Accept', 'application/json')
        xhr.upload.onprogress = (event) => {
          if (event.lengthComputable) uploadOptions.onProgress?.(event.loaded / event.total)
        }
        xhr.onerror = () => reject(new AiKitError('The upload failed. Check your connection and try again.', 'network_error', 0))
        xhr.onabort = () => reject(new AiKitError('The upload was cancelled.', 'aborted', 0))
        xhr.onload = () => {
          const refreshed = xhr.getResponseHeader('X-WP-Nonce')
          if (refreshed && refreshed !== nonce) {
            nonce = refreshed
            options.onNonce?.(refreshed)
          }
          let body: unknown = null
          try {
            body = xhr.responseText ? JSON.parse(xhr.responseText) : null
          } catch {
            body = xhr.responseText
          }
          if (xhr.status >= 200 && xhr.status < 300) {
            resolve(body as Attachment)
            return
          }
          const record = body && typeof body === 'object' ? (body as Record<string, unknown>) : {}
          reject(new AiKitError(typeof record.message === 'string' ? record.message : `Upload failed (${xhr.status})`, typeof record.code === 'string' ? record.code : `http_${xhr.status}`, xhr.status, body))
        }
        uploadOptions.signal?.addEventListener('abort', () => xhr.abort())
        const form = new FormData()
        form.append('file', file, file.name)
        xhr.send(form)
      })
    },
  }
}

/** The transport a bootstrap describes, or null when the plugin did not opt into the REST routes. */
export function transportFromBootstrap(bootstrap: AiKitBootstrap, fetchImpl?: typeof fetch): Transport | null {
  return bootstrap.restBase ? createRestTransport({ restBase: bootstrap.restBase, nonce: bootstrap.nonce, fetch: fetchImpl }) : null
}
