import type { AiKitBootstrap } from './types'

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

export interface Transport {
  request<T = unknown>(action: AiKitAction, payload?: Record<string, unknown>, options?: RequestOptions): Promise<T>
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

      const record = body && typeof body === 'object' ? (body as Record<string, unknown>) : {}
      const message = typeof record.message === 'string' ? record.message
        : typeof record.error === 'string' ? record.error
          : `Request failed (${response.status})`
      const code = typeof record.code === 'string' ? record.code
        : typeof record.reason === 'string' ? record.reason
          : `http_${response.status}`
      throw new AiKitError(message, code, response.status, body)
    },
  }
}

/** The transport a bootstrap describes, or null when the plugin did not opt into the REST routes. */
export function transportFromBootstrap(bootstrap: AiKitBootstrap, fetchImpl?: typeof fetch): Transport | null {
  return bootstrap.restBase ? createRestTransport({ restBase: bootstrap.restBase, nonce: bootstrap.nonce, fetch: fetchImpl }) : null
}
