import { useSyncExternalStore } from 'react'
import { useAiKit } from '../context'
import type { AiKitState } from '../store'

/**
 * Read a slice of the store; the component re-renders only when the selected
 * value changes (by identity), so activity polling never re-renders the chat.
 */
export function useStoreState<T>(selector: (state: AiKitState) => T): T {
  const { store } = useAiKit()
  return useSyncExternalStore(store.subscribe, () => selector(store.getState()), () => selector(store.getState()))
}
