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
import ToolActivity, { type ToolActivityMode } from './ToolActivity'

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
  /**
   * How a reply shows the tools the assistant used: `summary` (default) one line
   * with a Details toggle, `chips` a badge per call, `hidden` nothing.
   */
  toolActivity?: ToolActivityMode
}

/**
 * The steps where the assistant only used tools (no text) are folded into the
 * reply that follows them, so one answer shows one line of activity instead of a
 * bubble per step. Each message keeps its index in the transcript.
 */
function withMergedTools(messages: ChatMessage[], mode: ToolActivityMode): { message: ChatMessage, index: number }[] {
  if (mode === 'chips') return messages.map((message, index) => ({ message, index }))
  const out: { message: ChatMessage, index: number }[] = []
  let carried: NonNullable<ChatMessage['tools']> = []
  messages.forEach((message, index) => {
    if (message.role !== 'assistant') {
      carried = []
      out.push({ message, index })
      return
    }
    const tools = [...carried, ...(message.tools || [])]
    const next = messages[index + 1]
    if (!message.text.trim() && next?.role === 'assistant') {
      carried = tools
      return
    }
    carried = []
    out.push({ message: tools.length ? { ...message, tools } : message, index })
  })
  return out
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
  settingsHref, className, toolActivity = 'summary',
}: AssistantChatProps) {
  const { __, sprintf } = useI18n()
  const bootstrap = useBootstrap()
  const { Button, Icon } = useSlots()
  const assistant = useAssistant({ context })
  const [historyOpen, setHistoryOpen] = useState(false)
  const historyOn = useFeature('history')
  // Opt-in (PluginContext FEATURE_ATTACHMENTS): off unless the plugin says so.
  const attachmentsOn = bootstrap.features.attachments === true
  const attachmentRules = attachmentsOn ? bootstrap.attachments : null
  const bottom = useRef<HTMLDivElement>(null)
  const { messages, pending, busy, loaded, error, engine, engineReason, draft, conversation, extra, live } = assistant
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
  const latest = useRef({ loaded, blocked, count: messages.length, send, reset, open: assistant.open, setDraft: assistant.setDraft })
  latest.current = { loaded, blocked, count: messages.length, send, reset, open: assistant.open, setDraft: assistant.setDraft }
  // A request for a new chat while switching is blocked: it waits here, never goes into the current chat.
  const [held, setHeld] = useState<OpenRequest | null>(null)

  const handleOpen = useCallback((detail: OpenRequest) => {
    let waited = 0
    const run = async () => {
      if (!latest.current.loaded && waited < OPEN_WAIT_MS) {
        waited += 100
        window.setTimeout(run, 100)
        return
      }
      if (!detail.conversationId && detail.newChat && latest.current.count > 0 && latest.current.blocked) {
        setHeld(detail)
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

  useEffect(() => {
    if (!held || blocked) return
    setHeld(null)
    handleOpen(held)
  }, [held, blocked, handleOpen])

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
        {mine && message.attachments && message.attachments.length > 0 && (
          <ul className="aikit-chat__files" aria-label={__('Attached files')}>
            {message.attachments.map(file => (
              <li
                key={file.id}
                className={['aikit-chat__file', file.expired ? 'aikit-chat__file--expired' : ''].join(' ').trim()}
                title={file.expired ? __('No longer kept on this site, so the assistant cannot read it again. Attach it again if needed.') : undefined}
              >
                <Icon name="attach" size={12} />
                <span>{file.name}</span>
                {file.expired && <span className="aikit-chat__file-state">{__('expired')}</span>}
              </li>
            ))}
          </ul>
        )}
        {message.tools && message.tools.length > 0 && <ToolActivity tools={message.tools} mode={toolActivity} />}
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
  // A named chat shows its name; the assistant's name moves next to the engine.
  const chatTitle = conversation.id ? (conversation.title || '').trim() : ''
  const engineLabel = engine ? engine.label : __('Not connected')
  const composerDisabled = !!pending || !engine || !assistant.canUse

  return (
    <div className={['aikit', 'aikit-chat', className || ''].join(' ').trim()}>
      {showHeader && (
        <header className="aikit-chat__head">
          <div className="aikit-chat__heading">
            <div className="aikit-chat__title">
              <Icon name="spark" size={18} className="aikit-chat__title-icon" />
              <span className="aikit-chat__title-text" title={chatTitle || undefined}>{chatTitle || heading}</span>
            </div>
            <small className="aikit-chat__subtitle">{chatTitle ? `${heading} · ${engineLabel}` : engineLabel}</small>
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
              variant="ghost"
              size="sm"
              icon={<Icon name="new-chat" />}
              aria-label={__('New chat')}
              disabled={busy || !!blocked || messages.length === 0}
              title={blocked || __('New chat')}
              onClick={() => { void reset() }}
            />
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

        {withMergedTools(messages, toolActivity).map(({ message, index }) => bubble(message, index))}

        {pending && <PendingActionCard pending={pending} busy={busy} onDecide={approve => { void assistant.decide(approve) }} />}

        {busy && live && (live.text || live.tools.length > 0) ? (
          <div className="aikit-chat__message aikit-chat__message--assistant">
            <div className="aikit-chat__bubble aikit-chat__bubble--assistant aikit-chat__bubble--live" aria-busy="true">
              {live.text && <Markdown text={live.text} linkPolicy={linkPolicy} />}
              <ToolActivity tools={live.tools} mode={toolActivity === 'chips' ? 'summary' : toolActivity} />
            </div>
          </div>
        ) : busy && <ThinkingIndicator />}

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

        {held && blocked && (
          <div className="aikit-chat__error" role="status">
            {blocked}
            {' '}
            {__('Your request starts a new chat then.')}
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
          attachments={attachmentRules ? {
            items: assistant.attachments,
            accept: attachmentRules.accept,
            onAdd: assistant.attach,
            onRemove: assistant.detach,
            note: sprintf(__('Files go to the AI provider with your message and are deleted from this site after %d hours.'), attachmentRules.ttlHours),
          } : undefined}
        />
        {footerNote !== null && (
          <div className="aikit-chat__note">{footerNote ?? __('AI can be wrong. Personal data is masked unless allowed in the assistant settings.')}</div>
        )}
      </footer>
    </div>
  )
}
