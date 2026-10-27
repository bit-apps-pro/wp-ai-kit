import type { OpenRequest } from './types'

/**
 * How code outside React opens the assistant: a `CustomEvent` on `window`
 * named `bitapps-ai-kit:<slug>`, so a template chooser, a record page or a
 * plain script can say "ask this". `useAiKitEvents()` in the chat listens.
 */
export function busEventName(slug: string): string {
  return `bitapps-ai-kit:${slug}`
}

export interface AiKitBus {
  open: (detail?: OpenRequest) => void
  subscribe: (listener: (detail: OpenRequest) => void) => () => void
}

export function aiKitBus(slug: string): AiKitBus {
  const name = busEventName(slug)
  return {
    open(detail = {}) {
      if (typeof window !== 'undefined') window.dispatchEvent(new CustomEvent<OpenRequest>(name, { detail }))
    },
    subscribe(listener) {
      if (typeof window === 'undefined') return () => {}
      const handler = (event: Event) => listener(((event as CustomEvent<OpenRequest>).detail) || {})
      window.addEventListener(name, handler)
      return () => window.removeEventListener(name, handler)
    },
  }
}
