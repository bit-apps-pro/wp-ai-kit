import { type KeyboardEvent, type ReactNode, useEffect, useRef, useState } from 'react'
import { useAiKit, useI18n } from '../../core/context'
import { useConversations } from '../../core/hooks/useConversations'
import { useStoreState } from '../../core/hooks/useStoreState'
import type { ConversationList, ConversationSummary } from '../../core/types'
import { useSlots } from '../slots/useSlots'

export interface ConversationHistoryProps {
  /** `inline` fills its container; `popover` floats right-aligned under the host's trigger and closes on Escape or an outside click. */
  anchor?: 'popover' | 'inline'
  /** Popover only: whether it shows. */
  open?: boolean
  /** Popover only. */
  onClose?: () => void
  /** After a conversation was opened. */
  onOpen?: (id: string) => void
  onNewChat?: () => void
  /** When set, no chat can be opened or started, and this says why (an unsaved preview, say). */
  blockedReason?: string
  /** A collapsible "Archived" section below the list (default true). */
  showArchived?: boolean
  pageSize?: number
  /** Extra text under a row (the host's context: a record name, say). */
  renderMeta?: (item: ConversationSummary) => ReactNode
  emptyText?: string
}

const ARCHIVED_PAGE = 50

function localeOf(wpLocale: string | undefined): string | undefined {
  return wpLocale ? wpLocale.replace('_', '-') : undefined
}

/** "5 min ago", "Yesterday", "3 Oct": how long ago a chat was last used. */
export function ago(seconds: number, locale: string | undefined, justNow: string): string {
  const diff = Date.now() / 1000 - seconds
  if (diff < 60) return justNow
  try {
    const relative = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' })
    if (diff < 3600) return relative.format(-Math.floor(diff / 60), 'minute')
    if (diff < 86400) return relative.format(-Math.floor(diff / 3600), 'hour')
    if (diff < 7 * 86400) return relative.format(-Math.floor(diff / 86400), 'day')
    return new Date(seconds * 1000).toLocaleDateString(locale, { day: 'numeric', month: 'short' })
  } catch {
    return new Date(seconds * 1000).toLocaleDateString()
  }
}

/**
 * The archived list, fetched on its own so it can sit under the live list. It
 * follows every change to the live list (an archive, an unarchive, a delete).
 */
function useArchived(enabled: boolean, search: string, liveItems: ConversationSummary[], fallback: string) {
  const { transport } = useAiKit()
  const [state, setState] = useState<{ items: ConversationSummary[], total: number, loading: boolean, error: string | null }>({ items: [], total: 0, loading: false, error: null })
  const ticket = useRef(0)

  useEffect(() => {
    if (!enabled) return
    const mine = ++ticket.current
    setState(prev => ({ ...prev, loading: true, error: null }))
    transport.request<ConversationList>('assistant/history', { search, archived: true, offset: 0, limit: ARCHIVED_PAGE }).then((list) => {
      if (mine === ticket.current) setState({ items: list.items, total: list.total, loading: false, error: null })
    }).catch((error: unknown) => {
      if (mine === ticket.current) setState(prev => ({ ...prev, loading: false, error: error instanceof Error && error.message ? error.message : fallback }))
    })
  }, [enabled, search, liveItems, transport, fallback])

  return state
}

/**
 * The user's earlier conversations: search, open, rename, pin, archive, delete
 * one or all. Rows read from the store; the host decides what opening means.
 */
export default function ConversationHistory({
  anchor = 'inline', open = true, onClose, onOpen, onNewChat, blockedReason, showArchived = true, pageSize, renderMeta, emptyText,
}: ConversationHistoryProps) {
  const { __, sprintf } = useI18n()
  const { bootstrap } = useAiKit()
  const { Button, TextInput, Menu, Modal, Spinner, Icon } = useSlots()
  const history = useConversations({ pageSize, autoLoad: anchor === 'inline' || open })
  const busy = useStoreState(state => state.assistant.busy)
  const [query, setQuery] = useState(history.search)
  const [renaming, setRenaming] = useState<{ id: string, title: string } | null>(null)
  const [confirming, setConfirming] = useState<string | null>(null)
  const [archivedOpen, setArchivedOpen] = useState(false)
  const archived = useArchived(showArchived && archivedOpen, history.search, history.items, __('Could not load the archived chats.'))
  const root = useRef<HTMLDivElement>(null)
  const locale = localeOf(bootstrap.user?.locale)
  const popover = anchor === 'popover'

  // The popover closes on Escape and on a click anywhere outside it, except on the host's trigger (marked `data-aikit-history-trigger`).
  useEffect(() => {
    if (!popover || !open || !onClose) return
    const away = (event: MouseEvent) => {
      const target = event.target as Element | null
      if (root.current?.contains(target) || target?.closest?.('[data-aikit-history-trigger]')) return
      onClose()
    }
    const esc = (event: globalThis.KeyboardEvent) => { if (event.key === 'Escape') onClose() }
    document.addEventListener('mousedown', away)
    document.addEventListener('keydown', esc)
    return () => {
      document.removeEventListener('mousedown', away)
      document.removeEventListener('keydown', esc)
    }
  }, [popover, open, onClose])

  // The store keeps the live list; the component only ever shows that one here.
  useEffect(() => { if (history.archived && (anchor === 'inline' || open)) void history.setArchived(false) }, [history.archived, anchor, open]) // eslint-disable-line react-hooks/exhaustive-deps

  // A popover that opens again shows what happened since (the chat that just ended, say).
  useEffect(() => { if (popover && open && history.status !== 'idle') void history.refresh() }, [popover, open]) // eslint-disable-line react-hooks/exhaustive-deps

  if (popover && !open) return null

  const search = (value: string) => {
    setQuery(value)
    history.setSearch(value)
  }

  const openChat = (id: string) => {
    if (blockedReason || busy || id === history.currentId) return
    void history.open(id).then((ok) => { if (ok) onOpen?.(id) })
  }

  const commitRename = () => {
    if (!renaming) return
    const title = renaming.title.trim()
    const current = [...history.items, ...archived.items].find(item => item.id === renaming.id)
    if (title && title !== current?.title) void history.rename(renaming.id, title)
    setRenaming(null)
  }

  const onRenameKey = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Enter') { event.preventDefault(); commitRename() }
    if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); setRenaming(null) }
  }

  const forget = (id: string) => {
    setConfirming(null)
    void history.forget(id)
  }

  const row = (item: ConversationSummary) => {
    const current = item.id === history.currentId
    const name = item.title || __('Untitled chat')
    const meta = renderMeta?.(item)
    return (
      <li
        key={item.id}
        className={['aikit-history__row', current ? 'aikit-history__row--current' : '', item.pinned ? 'aikit-history__row--pinned' : ''].join(' ').trim()}
        aria-current={current ? 'true' : undefined}
      >
        {renaming?.id === item.id ? (
          <TextInput
            value={renaming.title}
            onChange={title => setRenaming({ id: item.id, title })}
            onKeyDown={onRenameKey}
            onBlur={commitRename}
            autoFocus
            className="aikit-history__rename"
            aria-label={__('Chat title')}
          />
        ) : (
          <Button
            variant="ghost"
            className="aikit-history__open"
            disabled={!!blockedReason || current || busy}
            title={blockedReason || undefined}
            onClick={() => openChat(item.id)}
          >
            <span className="aikit-history__title">
              {item.pinned && <Icon name="pin" size={12} className="aikit-history__pin" />}
              <span className="aikit-history__title-text">{name}</span>
            </span>
            <span className="aikit-history__meta">
              {ago(item.updated, locale, __('Just now'))}
              {meta !== undefined && meta !== null && meta !== '' && <> · {meta}</>}
            </span>
          </Button>
        )}
        <span className="aikit-history__actions">
          <Menu
            aria-label={sprintf(__('Actions for “%s”'), name)}
            trigger={<span className="aikit-history__more" aria-label={sprintf(__('Actions for “%s”'), name)} title={__('Actions')}><Icon name="more" /></span>}
            items={[
              { key: 'rename', label: __('Rename'), icon: <Icon name="edit" />, onClick: () => setRenaming({ id: item.id, title: item.title }) },
              { key: 'pin', label: item.pinned ? __('Unpin') : __('Pin'), icon: <Icon name="pin" />, onClick: () => { void history.setPinned(item.id, !item.pinned) } },
              { key: 'archive', label: item.archived ? __('Unarchive') : __('Archive'), icon: <Icon name={item.archived ? 'unarchive' : 'archive'} />, onClick: () => { void history.setArchivedFlag(item.id, !item.archived) } },
              { key: 'delete', label: __('Delete'), icon: <Icon name="trash" />, danger: true, onClick: () => setConfirming(item.id) },
            ]}
          />
        </span>
      </li>
    )
  }

  const confirmingItem = confirming && confirming !== 'all' ? [...history.items, ...archived.items].find(item => item.id === confirming) : null

  return (
    <div ref={root} className={['aikit', 'aikit-history', popover ? 'aikit-history--popover' : 'aikit-history--inline'].join(' ')} role={popover ? 'dialog' : undefined} aria-label={__('Chat history')}>
      <input
        type="search"
        className="aikit-input aikit-history__search"
        placeholder={__('Search chats')}
        aria-label={__('Search chats')}
        value={query}
        onChange={e => search(e.target.value)}
      />

      {blockedReason && <div className="aikit-notice aikit-notice--info aikit-history__note">{blockedReason}</div>}
      {history.error && <div className="aikit-notice aikit-notice--danger aikit-history__note">{history.error}</div>}

      {onNewChat && (
        <Button variant="ghost" className="aikit-history__new" icon={<Icon name="plus" />} disabled={!!blockedReason || busy} title={blockedReason || undefined} onClick={onNewChat}>
          {__('New chat')}
        </Button>
      )}

      {!history.loading && history.items.length === 0 && !history.error && (
        <div className="aikit-empty aikit-history__empty">{history.search ? __('No chat matches that search.') : (emptyText || __('No earlier chats yet.'))}</div>
      )}

      <ul className="aikit-history__list">{history.items.map(row)}</ul>

      {history.loading && <div className="aikit-history__loading"><Spinner /></div>}
      {!history.loading && history.hasMore && (
        <Button variant="secondary" size="sm" className="aikit-history__more-btn" onClick={() => { void history.loadMore() }}>{__('Load more')}</Button>
      )}

      {showArchived && (
        <div className="aikit-history__archived">
          <Button
            variant="ghost"
            size="sm"
            className="aikit-history__archived-toggle"
            icon={<Icon name={archivedOpen ? 'chevron-down' : 'chevron-right'} />}
            aria-pressed={archivedOpen}
            onClick={() => setArchivedOpen(o => !o)}
          >
            {archivedOpen && !archived.loading ? sprintf(__('Archived (%d)'), archived.total) : __('Archived')}
          </Button>
          {archivedOpen && (
            <>
              {archived.error && <div className="aikit-notice aikit-notice--danger aikit-history__note">{archived.error}</div>}
              {archived.loading && <div className="aikit-history__loading"><Spinner /></div>}
              {!archived.loading && !archived.error && archived.items.length === 0 && <div className="aikit-empty aikit-history__empty">{__('No archived chats.')}</div>}
              <ul className="aikit-history__list">{archived.items.map(row)}</ul>
            </>
          )}
        </div>
      )}

      {history.items.length > 0 && !history.search && (
        <div className="aikit-history__footer">
          <Button variant="ghost" size="sm" className="aikit-history__clear" onClick={() => setConfirming('all')}>{__('Delete all chats')}</Button>
        </div>
      )}

      <Modal
        open={confirming !== null}
        onClose={() => setConfirming(null)}
        title={confirming === 'all' ? __('Delete all chats?') : __('Delete this chat?')}
        width={400}
        footer={(
          <>
            <Button variant="secondary" size="sm" onClick={() => setConfirming(null)}>{__('Keep')}</Button>
            <Button variant="danger" size="sm" onClick={() => { if (confirming) forget(confirming) }}>{confirming === 'all' ? __('Delete all') : __('Delete')}</Button>
          </>
        )}
      >
        {confirming === 'all'
          ? __('Every chat is deleted for good. This cannot be undone.')
          : sprintf(__('“%s” is deleted for good. This cannot be undone.'), confirmingItem?.title || __('Untitled chat'))}
      </Modal>
    </div>
  )
}
