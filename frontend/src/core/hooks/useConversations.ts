import { useCallback, useEffect, useRef } from 'react'
import { useAiKit } from '../context'
import { useStoreState } from './useStoreState'

export interface UseConversationsOptions {
  /** Load on mount (default true). */
  autoLoad?: boolean
  pageSize?: number
  /** Debounce for the search box, ms (default 300). */
  searchDelay?: number
}

/** The history list: search, paging, archived or live, rename, pin, archive, delete. */
export function useConversations(options: UseConversationsOptions = {}) {
  const { store } = useAiKit()
  const conversations = useStoreState(state => state.conversations)
  const current = useStoreState(state => state.assistant.conversation)
  const pageSize = options.pageSize ?? 20
  const timer = useRef<number | null>(null)

  useEffect(() => {
    if (options.autoLoad !== false && conversations.status === 'idle') void store.loadHistory({ limit: pageSize })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [store, options.autoLoad])

  const setSearch = useCallback((search: string) => {
    if (timer.current) window.clearTimeout(timer.current)
    timer.current = window.setTimeout(() => { void store.loadHistory({ search, limit: pageSize }) }, search ? (options.searchDelay ?? 300) : 0)
  }, [store, pageSize, options.searchDelay])

  useEffect(() => () => { if (timer.current) window.clearTimeout(timer.current) }, [])

  return {
    ...conversations,
    currentId: current.id,
    loading: conversations.status === 'loading',
    hasMore: conversations.items.length < conversations.total,
    setSearch,
    setArchived: useCallback((archived: boolean) => store.loadHistory({ archived, limit: pageSize }), [store, pageSize]),
    loadMore: useCallback(() => store.loadHistory({ more: true, limit: pageSize }), [store, pageSize]),
    refresh: useCallback(() => store.loadHistory({ limit: pageSize }), [store, pageSize]),
    open: useCallback((id: string) => store.open(id), [store]),
    rename: useCallback((id: string, title: string) => store.rename(id, title), [store]),
    setPinned: useCallback((id: string, pinned: boolean) => store.setFlags(id, { pinned }), [store]),
    setArchivedFlag: useCallback((id: string, archived: boolean) => store.setFlags(id, { archived }), [store]),
    forget: useCallback((id: string) => store.forget(id), [store]),
  }
}
