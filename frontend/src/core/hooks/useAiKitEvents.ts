import { useEffect, useRef } from 'react'
import { aiKitBus } from '../bus'
import { useAiKit } from '../context'
import type { AiKitEvent, OpenRequest } from '../types'

/**
 * Listen to the store's events and to the window bus (`aiKitBus(slug).open()`),
 * which arrives as `{ type: 'open', detail }`.
 */
export function useAiKitEvents(listener: (event: AiKitEvent) => void): void {
  const { store, bootstrap } = useAiKit()
  const ref = useRef(listener)
  ref.current = listener

  useEffect(() => {
    const offStore = store.onEvent(event => ref.current(event))
    const offBus = aiKitBus(bootstrap.slug).subscribe((detail: OpenRequest) => ref.current({ type: 'open', detail }))
    return () => {
      offStore()
      offBus()
    }
  }, [store, bootstrap.slug])
}
