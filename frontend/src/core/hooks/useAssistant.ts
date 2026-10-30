import { useCallback, useEffect, useRef } from 'react'
import { useAiKit } from '../context'
import type { SendOptions } from '../store'
import type { AiKitEvent, ChatView, Mention } from '../types'
import { useStoreState } from './useStoreState'

export type PageContext = Record<string, unknown> | (() => Record<string, unknown>)

export interface UseAssistantOptions {
  /**
   * Where the user is, as ids the server resolves (Bit Form: `{screen, form_id}`).
   * A getter is read at send time. Nothing in it reaches the model unless the
   * plugin's `chatContextHook` puts it there.
   */
  context?: PageContext
  /** Load the conversation on mount (default true). */
  autoLoad?: boolean
  onEvent?: (event: AiKitEvent) => void
}

/**
 * The chat panel's state and actions. Headless: a host may build its own
 * panel on this, or render `<AssistantChat>` which does.
 */
export function useAssistant(options: UseAssistantOptions = {}) {
  const { store, bootstrap } = useAiKit()
  const assistant = useStoreState(state => state.assistant)
  const contextRef = useRef(options.context)
  contextRef.current = options.context
  const onEvent = useRef(options.onEvent)
  onEvent.current = options.onEvent

  useEffect(() => {
    if (options.autoLoad !== false && !assistant.loaded && !assistant.busy && bootstrap.canUse) void store.loadView()
    // Only on mount and when access changes: loadView() itself flips `loaded`.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [store, bootstrap.canUse, options.autoLoad])

  useEffect(() => store.onEvent(event => onEvent.current?.(event)), [store])

  const context = useCallback((): Record<string, unknown> => {
    const value = contextRef.current
    return typeof value === 'function' ? value() : (value || {})
  }, [])

  const send = useCallback((text: string, extra: Omit<SendOptions, 'context'> & { context?: Record<string, unknown> } = {}): Promise<ChatView | null> => (
    store.send(text, { mentions: extra.mentions as Mention[] | undefined, context: { ...context(), ...(extra.context || {}) } })
  ), [store, context])

  const decide = useCallback((approve: boolean): Promise<ChatView | null> => store.decide(approve, context()), [store, context])

  return {
    ...assistant,
    canUse: bootstrap.canUse,
    /** The assistant is switched on for this site and this user may use it. */
    available: bootstrap.canUse && bootstrap.assistantOn && !!assistant.engine,
    send,
    decide,
    reset: useCallback(() => store.reset(), [store]),
    open: useCallback((id: string) => store.open(id), [store]),
    reload: useCallback(() => store.loadView(), [store]),
    setDraft: useCallback((draft: string) => store.setDraft(draft), [store]),
    attach: useCallback((files: File[]) => store.attach(files), [store]),
    detach: useCallback((localId: string) => store.detach(localId), [store]),
  }
}
