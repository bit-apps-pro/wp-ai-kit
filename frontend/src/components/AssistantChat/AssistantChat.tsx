import { type ReactNode, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useBootstrap, useFeature, useI18n } from '../../core/context'
import { useAiKitEvents } from '../../core/hooks/useAiKitEvents'
import { type PageContext, useAssistant } from '../../core/hooks/useAssistant'
import type { AiKitEvent, ChatMessage, ConversationRef, Mention, OpenRequest } from '../../core/types'
import ConversationHistory from '../ConversationHistory/ConversationHistory'
import Markdown, { type MarkdownProps } from '../Markdown'
import PendingActionCard from '../PendingActionCard/PendingActionCard'
import PromptComposer, {
  type CommandOption, type CommandsProvider, filterCommands, type MentionOption, type MentionsProvider,
} from '../PromptComposer/PromptComposer'
import { useSlots } from '../slots/useSlots'
import ThinkingIndicator from '../ThinkingIndicator'

export type { CommandOption, CommandsProvider, MentionOption, MentionsProvider }

export interface AssistantChatProps {
  title?: ReactNode
  showHeader?: boolean
  /** Shows a close button in the header. */
  onClose?: () => void
  /** Where the user is, sent with every message (see `useAssistant`). */
  context?: PageContext
  mentions?: MentionsProvider
  /** The host's / commands; `new` (start a new chat) and `history` (earlier chats) are always there. */
  commands?: CommandsProvider
  /** Prompts offered in the empty state; clicking one sends it. */
  suggestions?: string[]
  emptyState?: ReactNode
  /** Rendered in the footer above the composer (a host's preview bar, say). */
  renderPreviewBar?: (ctx: { extra: Record<string, unknown>, busy: boolean, conversation: ConversationRef }) => ReactNode
  renderMessageFooter?: (message: ChatMessage, index: number) => ReactNode
  /** Replace or wrap a bubble; `defaultNode` is what would render otherwise. */
  renderMessage?: (message: ChatMessage, defaultNode: ReactNode) => ReactNode
  footerNote?: ReactNode
  /** The composer's placeholder (default "Ask anything…"). */
  placeholder?: string
  onEvent?: (event: AiKitEvent) => void
  /** Before a message is sent; return `false` to keep it in the composer. */
  onSend?: (text: string, meta: { mentions: Mention[] }) => void | false
  onError?: (error: string) => void
  /** Why switching chats is blocked right now (an unsaved preview); disables New chat and the history. */
  switchBlockedReason?: string
  linkPolicy?: MarkdownProps['linkPolicy']
  /** Linked from the error line when no engine is set up (default `bootstrap.settingsUrl`). */
  settingsHref?: string
  className?: string
}

/** How long an open request waits for the first load before giving up. */
const OPEN_WAIT_MS = 5000

/**
 * The chat itself: header, messages, the pending-action card, the thinking
 * line, errors and the composer. Put it in a `Panel`, a drawer or a page.
 */
export default function AssistantChat({
  title, showHeader = true, onClose, context, mentions, commands, suggestions = [], emptyState, renderPreviewBar,
  renderMessageFooter, renderMessage, footerNote, placeholder, onEvent, onSend, onError, switchBlockedReason, linkPolicy,
  settingsHref, className,
}: AssistantChatProps) {
  const { __, sprintf } = useI18n()
  const bootstrap = useBootstrap()
  const { Button, Badge, Icon } = useSlots()
  const assistant = useAssistant({ context })
  const [historyOpen, setHistoryOpen] = useState(false)
  const historyOn = useFeature('history')
  const bottom = useRef<HTMLDivElement>(null)
  const { messages, pending, busy, loaded, error, engine, engineReason, draft, conversation, extra } = assistant
  const blocked = switchBlockedReason || ''
  const settingsLink = settingsHref ?? bootstrap.settingsUrl ?? undefined

  const send = useCallback((text: string, meta: { mentions: Mention[] } = { mentions: [] }, requestContext?: Record<string, unknown>) => {
    if (onSend?.(text, meta) === false) return Promise.resolve(null)
    return assistant.send(text, { mentions: meta.mentions.length ? meta.mentions : undefined, context: requestContext })
  }, [assistant.send, onSend]) // eslint-disable-line react-hooks/exhaustive-deps

  const reset = useCallback(() => {
    if (blocked) return Promise.resolve()
    setHistoryOpen(false)
    return assistant.reset()
  }, [assistant.reset, blocked]) // eslint-disable-line react-hooks/exhaustive-deps

  // Store events and the window bus (`aiKitBus(slug).open()`), both to the host and to the open handler.
  const latest = useRef({ loaded, count: messages.length, send, reset, open: assistant.open, setDraft: assistant.setDraft })
  latest.current = { loaded, count: messages.length, send, reset, open: assistant.open, setDraft: assistant.setDraft }

  const handleOpen = useCallback((detail: OpenRequest) => {
    let waited = 0
    const run = async () => {
      if (!latest.current.loaded && waited < OPEN_WAIT_MS) {
        waited += 100
        window.setTimeout(run, 100)
        return
      }
      if (detail.conversationId) await latest.current.open(detail.conversationId)
      else if (detail.newChat && latest.current.count > 0) await latest.current.reset()
      if (!detail.message) return
      latest.current.setDraft(detail.message)
      // The next render's send sees the reset's state.
      if (detail.autoSend !== false) window.setTimeout(() => { void latest.current.send(detail.message as string, { mentions: [] }, detail.context) }, 0)
    }
    void run()
  }, [])

  useAiKitEvents((event) => {
    onEvent?.(event)
    if (event.type === 'open') handleOpen(event.detail)
  })

  useEffect(() => { if (error) onError?.(error) }, [error]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => { bottom.current?.scrollIntoView?.({ block: 'end' }) }, [messages, pending, busy])

  const allCommands = useMemo<CommandsProvider>(() => {
    const builtIn: CommandOption[] = [
      { id: 'new', label: __('Start a new chat'), run: () => { void reset() } },
      ...(historyOn ? [{ id: 'history', label: __('Show earlier chats'), run: () => setHistoryOpen(true) }] : []),
    ]
    return {
      heading: commands?.heading,
      items: (query: string) => {
        const host = !commands ? [] : (typeof commands.items === 'function' ? commands.items(query) : filterCommands(commands.items, query))
        return [...host, ...filterCommands(builtIn, query)]
      },
    }
  }, [commands, reset, __, historyOn])

  const bubble = (message: ChatMessage, index: number): ReactNode => {
    const mine = message.role === 'user'
    const node = (
      <div className={['aikit-chat__bubble', mine ? 'aikit-chat__bubble--user' : 'aikit-chat__bubble--assistant', message.optimistic ? 'aikit-chat__bubble--optimistic' : ''].join(' ').trim()}>
        {mine ? <div className="aikit-chat__text">{message.text}</div> : <Markdown text={message.text} linkPolicy={linkPolicy} />}
        {message.tools && message.tools.length > 0 && (
          <div className="aikit-chat__tools">
            {message.tools.map((tool, i) => (
              // eslint-disable-next-line react/no-array-index-key
              <Badge key={i} tone={tool.error ? 'danger' : 'accent'} title={tool.name}>{tool.title}</Badge>
            ))}
          </div>
        )}
        {renderMessageFooter?.(message, index)}
      </div>
    )
    return (
      // eslint-disable-next-line react/no-array-index-key
      <div key={index} className={['aikit-chat__message', mine ? 'aikit-chat__message--user' : 'aikit-chat__message--assistant'].join(' ')}>
        {renderMessage ? renderMessage(message, node) : node}
      </div>
    )
  }

  const heading = title ?? sprintf(__('%s assistant'), bootstrap.name)
  const composerDisabled = !!pending || !engine || !assistant.canUse

  return (
    <div className={['aikit', 'aikit-chat', className || ''].join(' ').trim()}>
      {showHeader && (
        <header className="aikit-chat__head">
          <div className="aikit-chat__heading">
            <div className="aikit-chat__title">
              <Icon name="spark" size={18} className="aikit-chat__title-icon" />
              <span>{heading}</span>
            </div>
            <small className="aikit-chat__subtitle">{engine ? engine.label : __('Not connected')}</small>
          </div>
          <div className="aikit-chat__head-actions">
            {historyOn && (
              <span data-aikit-history-trigger="" className="aikit-chat__history-anchor">
                <Button
                  variant="ghost"
                  size="sm"
                  icon={<Icon name="history" />}
                  aria-label={__('Chat history')}
                  aria-pressed={historyOpen}
                  title={__('Chat history')}
                  onClick={() => setHistoryOpen(o => !o)}
                />
                <ConversationHistory
                  anchor="popover"
                  open={historyOpen}
                  onClose={() => setHistoryOpen(false)}
                  onOpen={() => setHistoryOpen(false)}
                  onNewChat={() => { void reset() }}
                  blockedReason={blocked || undefined}
                />
              </span>
            )}
            <Button
              variant="secondary"
              size="sm"
              disabled={busy || !!blocked || messages.length === 0}
              title={blocked || undefined}
              onClick={() => { void reset() }}
            >
              {__('New chat')}
            </Button>
            {onClose && <Button variant="ghost" size="sm" icon={<Icon name="close" />} aria-label={__('Close the assistant')} onClick={onClose} />}
          </div>
        </header>
      )}

      <div className="aikit-chat__body" aria-live="polite">
        {loaded && messages.length === 0 && !error && (
          <div className="aikit-chat__empty">
            {emptyState ?? <p>{__('Ask about this site. The assistant uses the same tools and limits as connected AI apps.')}</p>}
            {suggestions.length > 0 && (
              <div className="aikit-chat__suggestions">
                {/* Plain buttons: a prompt chip, not one of the host's action buttons. */}
                {suggestions.map(text => (
                  <button key={text} type="button" className="aikit-chat__suggestion" disabled={composerDisabled || busy} onClick={() => { void send(text) }}>{text}</button>
                ))}
              </div>
            )}
          </div>
        )}

        {messages.map(bubble)}

        {pending && <PendingActionCard pending={pending} busy={busy} onDecide={approve => { void assistant.decide(approve) }} />}

        {busy && <ThinkingIndicator />}

        {/* No engine: say why and where to set one up, instead of a silent, disabled composer. */}
        {loaded && !engine && !error && assistant.canUse && (
          <div className="aikit-chat__error" role="status">
            {engineReason || __('No AI engine is set up.')}
            {settingsLink && (
              <>
                {' '}
                <a href={settingsLink}>{__('Assistant settings')}</a>
              </>
            )}
          </div>
        )}

        {error && (
          <div className="aikit-chat__error" role="alert">
            {error}
            {!engine && settingsLink && (
              <>
                {' '}
                <a href={settingsLink}>{__('Assistant settings')}</a>
              </>
            )}
          </div>
        )}
        <div ref={bottom} />
      </div>

      <footer className="aikit-chat__foot">
        {renderPreviewBar && <div className="aikit-chat__preview-bar">{renderPreviewBar({ extra, busy, conversation })}</div>}
        <PromptComposer
          value={draft}
          onChange={assistant.setDraft}
          onSubmit={(text, meta) => { void send(text, meta) }}
          busy={busy}
          disabled={composerDisabled}
          placeholder={pending ? __('Confirm or cancel the action first') : (placeholder ?? __('Ask anything…'))}
          mentions={mentions}
          commands={allCommands}
        />
        {footerNote !== null && (
          <div className="aikit-chat__note">{footerNote ?? __('AI can be wrong. Personal data is masked unless allowed in the assistant settings.')}</div>
        )}
      </footer>
    </div>
  )
}
