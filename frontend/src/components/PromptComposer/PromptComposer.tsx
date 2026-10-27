import {
  type KeyboardEvent, type PointerEvent as ReactPointerEvent, type ReactNode, useEffect, useId, useLayoutEffect, useRef, useState,
} from 'react'
import { useI18n } from '../../core/context'
import type { Mention } from '../../core/types'
import { useSlots } from '../slots/useSlots'

/** A row of the @ menu: something on the site the message can point at. */
export interface MentionOption {
  id: string
  label: string
  hint: string
  mention: Mention
}

/** Where the @ menu's rows come from; `search` may be async (a REST lookup). */
export interface MentionsProvider {
  trigger?: '@'
  search: (query: string) => MentionOption[] | Promise<MentionOption[]>
  emptyText?: string
  heading?: string
}

/** A row of the / menu: a prompt to start from, or something the panel does. */
export interface CommandOption {
  id: string
  label: string
  hint?: string
  /** Put in the input (a trailing space leaves room to finish the sentence). */
  prompt?: string
  run?: () => void
}

/** Where the / menu's rows come from: a fixed list (filtered here) or a function of the query (filtered by the host). */
export interface CommandsProvider {
  items: CommandOption[] | ((query: string) => CommandOption[])
  heading?: string
}

export interface PromptComposerProps {
  value?: string
  defaultValue?: string
  onChange?: (value: string) => void
  onSubmit: (text: string, meta: { mentions: Mention[] }) => void
  /** A turn is in flight: the send button waits. */
  busy?: boolean
  disabled?: boolean
  placeholder?: string
  minHeight?: number
  maxHeight?: number
  resizable?: boolean
  mentions?: MentionsProvider
  commands?: CommandsProvider
  /** The line under the input; defaults to the menu keys when providers are given. */
  hint?: ReactNode
  /** localStorage key for the remembered height. */
  storageKey?: string
  autoFocus?: boolean
}

type MenuState = { kind: '@' | '/', query: string, start: number }

const MENU_ROWS = 8
const DEFAULT_HEIGHT = 64

function readHeight(key: string, min: number, max: number): number {
  try {
    const stored = Number(window.localStorage.getItem(key))
    return stored >= min && stored <= max ? stored : Math.min(max, Math.max(min, DEFAULT_HEIGHT))
  } catch {
    return Math.min(max, Math.max(min, DEFAULT_HEIGHT))
  }
}

function storeHeight(key: string, height: number): void {
  try { window.localStorage.setItem(key, String(height)) } catch { /* private window: not remembered */ }
}

/**
 * The menu the text before the caret asks for: `@…` after a space or at the
 * start, `/…` only as the whole message so far (a URL or a date never opens it).
 */
export function menuAt(text: string, caret: number): MenuState | null {
  const before = text.slice(0, caret)
  const command = before.match(/^\/([^\s/]*)$/)
  if (command) return { kind: '/', query: command[1], start: 0 }
  const mention = before.match(/(^|\s)@([^\s@]*)$/)
  if (mention) return { kind: '@', query: mention[2], start: caret - mention[2].length - 1 }
  return null
}

function matches(label: string, hint: string, query: string): boolean {
  const q = query.toLowerCase()
  return !q || label.toLowerCase().includes(q) || hint.toLowerCase().includes(q)
}

/** The rows of a command list that match what was typed after the slash. */
export function filterCommands(items: CommandOption[], query: string): CommandOption[] {
  return items.filter(item => matches(`${item.id} ${item.label}`, item.hint || '', query))
}

function resolveCommands(provider: CommandsProvider | undefined, query: string): CommandOption[] {
  if (!provider) return []
  return typeof provider.items === 'function' ? provider.items(query) : filterCommands(provider.items, query)
}

/**
 * The message box: grows by dragging its top edge, sends from the button
 * inside it, and offers @ (things to point at) and / (prompts and actions)
 * menus driven from the keyboard. Controlled through `value`, or on its own.
 */
export default function PromptComposer({
  value, defaultValue = '', onChange, onSubmit, busy = false, disabled = false, placeholder,
  minHeight = 44, maxHeight = 360, resizable = true, mentions, commands, hint,
  storageKey = 'aikit-composer-height', autoFocus,
}: PromptComposerProps) {
  const { __ } = useI18n()
  const { Button, Icon } = useSlots()
  const input = useRef<HTMLTextAreaElement>(null)
  const [inner, setInner] = useState(defaultValue)
  const text = value !== undefined ? value : inner
  const [height, setHeight] = useState(() => readHeight(storageKey, minHeight, maxHeight))
  const [menu, setMenu] = useState<MenuState | null>(null)
  const [active, setActive] = useState(0)
  const [picked, setPicked] = useState<MentionOption[]>([])
  const [mentionRows, setMentionRows] = useState<MentionOption[]>([])
  const menuId = useId()

  const setText = (next: string) => {
    if (value === undefined) setInner(next)
    onChange?.(next)
  }

  useEffect(() => { if (!text) setPicked([]) }, [text])

  // The @ rows come from the provider, possibly later; a stale answer is dropped.
  // The provider is read through a ref so an inline object does not re-run the lookup every render.
  const mentionsRef = useRef(mentions)
  mentionsRef.current = mentions
  const ticket = useRef(0)
  useEffect(() => {
    const provider = mentionsRef.current
    if (!menu || menu.kind !== '@' || !provider) return
    const mine = ++ticket.current
    Promise.resolve(provider.search(menu.query)).then((rows) => {
      if (mine === ticket.current) setMentionRows(rows.filter(row => matches(row.label, row.hint, menu.query)))
    }).catch(() => { if (mine === ticket.current) setMentionRows([]) })
  }, [menu?.kind, menu?.query])

  const rows: (MentionOption | CommandOption)[] = !menu ? [] : (menu.kind === '@' ? mentionRows : resolveCommands(commands, menu.query)).slice(0, MENU_ROWS)

  useEffect(() => setActive(0), [menu?.kind, menu?.query])

  const openMenu = (next: MenuState | null) => {
    if (next?.kind === '@' && !mentions) next = null
    if (next?.kind === '/' && !commands) next = null
    setMenu(next)
  }

  const watchCaret = (current: string) => {
    const caret = input.current?.selectionStart ?? current.length
    openMenu(menuAt(current, caret))
  }

  // Where the caret goes once the textarea shows the text a menu choice put in (a controlled host may take a render longer).
  const caretAfter = useRef<{ text: string, caret: number } | null>(null)
  useLayoutEffect(() => {
    const wanted = caretAfter.current
    const el = input.current
    if (!wanted || !el || el.value !== wanted.text) return
    caretAfter.current = null
    el.focus()
    el.setSelectionRange(wanted.caret, wanted.caret)
  })

  const place = (next: string, caret: number) => {
    caretAfter.current = { text: next, caret }
    setText(next)
    setMenu(null)
  }

  const choose = (row: MentionOption | CommandOption) => {
    if (!menu) return
    const caret = input.current?.selectionStart ?? text.length
    if ('mention' in row) {
      const token = `@${row.label} `
      setPicked(prev => [...prev.filter(p => p.id !== row.id), row])
      place(text.slice(0, menu.start) + token + text.slice(caret), menu.start + token.length)
      return
    }
    if (row.run) {
      place(text.slice(caret), 0)
      row.run()
      return
    }
    const prompt = row.prompt || ''
    place(prompt + text.slice(caret), prompt.length)
  }

  const canSend = !busy && !disabled && !!text.trim()

  const send = () => {
    if (!canSend) return
    // Only what is still written in the message counts.
    const picks = picked.filter(p => text.includes(`@${p.label}`)).map(p => p.mention)
    setMenu(null)
    onSubmit(text, { mentions: picks })
  }

  const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (menu && rows.length) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault()
        setActive(i => (i + (e.key === 'ArrowDown' ? 1 : rows.length - 1)) % rows.length)
        return
      }
      if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault()
        choose(rows[active])
        return
      }
    }
    if (menu && e.key === 'Escape') {
      e.preventDefault()
      e.stopPropagation()
      setMenu(null)
      return
    }
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      send()
    }
  }

  // Drag the top edge: up grows the box, down shrinks it.
  const startResize = (e: ReactPointerEvent<HTMLDivElement>) => {
    e.preventDefault()
    const startY = e.clientY
    const startHeight = height
    let next = startHeight
    const move = (event: PointerEvent) => {
      next = Math.min(maxHeight, Math.max(minHeight, startHeight + startY - event.clientY))
      setHeight(next)
    }
    const stop = () => {
      window.removeEventListener('pointermove', move)
      window.removeEventListener('pointerup', stop)
      storeHeight(storageKey, next)
    }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', stop)
  }

  const resizeByKey = (e: KeyboardEvent<HTMLDivElement>) => {
    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return
    e.preventDefault()
    const next = Math.min(maxHeight, Math.max(minHeight, height + (e.key === 'ArrowUp' ? 20 : -20)))
    setHeight(next)
    storeHeight(storageKey, next)
  }

  const defaultHint = [mentions && __('@ mention'), commands && __('/ commands'), __('Shift+Enter new line')].filter(Boolean).join(' · ')
  const hintNode = hint !== undefined ? hint : ((mentions || commands) ? defaultHint : null)
  const emptyText = menu?.kind === '@' ? (mentions?.emptyText || __('Nothing matches.')) : __('No command matches.')
  const heading = menu?.kind === '@' ? (mentions?.heading || __('Mention')) : (commands?.heading || __('Prompts and actions'))

  return (
    <div className="aikit aikit-composer">
      {menu && (
        <ul className="aikit-composer__menu" id={menuId} role="listbox" aria-label={menu.kind === '@' ? __('Mention') : __('Commands')}>
          <li className="aikit-composer__menu-head" role="presentation">{heading}</li>
          {rows.length === 0 && <li className="aikit-composer__menu-empty" role="presentation">{emptyText}</li>}
          {rows.map((row, i) => (
            <li
              key={row.id}
              role="option"
              aria-selected={i === active}
              className={['aikit-composer__menu-row', i === active ? 'aikit-composer__menu-row--active' : ''].join(' ').trim()}
              onMouseEnter={() => setActive(i)}
              // Keep the caret in the input while choosing.
              onMouseDown={e => e.preventDefault()}
              onClick={() => choose(row)}
            >
              <span className="aikit-composer__menu-label">{'mention' in row ? `@${row.label}` : `/${row.id}`}</span>
              <span className="aikit-composer__menu-hint">{'mention' in row ? row.hint : row.label}</span>
            </li>
          ))}
        </ul>
      )}

      <div className={['aikit-composer__box', disabled ? 'aikit-composer__box--disabled' : ''].join(' ').trim()}>
        {resizable && (
          <div
            className="aikit-composer__grip"
            role="separator"
            aria-orientation="horizontal"
            aria-label={__('Resize the message box')}
            aria-valuemin={minHeight}
            aria-valuemax={maxHeight}
            aria-valuenow={height}
            tabIndex={0}
            onPointerDown={startResize}
            onKeyDown={resizeByKey}
          />
        )}
        <textarea
          ref={input}
          className="aikit-composer__input"
          style={{ height }}
          value={text}
          placeholder={placeholder}
          aria-label={__('Message')}
          aria-autocomplete="list"
          aria-controls={menu ? menuId : undefined}
          aria-expanded={!!menu}
          disabled={disabled}
          autoFocus={autoFocus}
          onChange={(e) => {
            setText(e.target.value)
            watchCaret(e.target.value)
          }}
          onClick={() => watchCaret(text)}
          onKeyUp={(e) => { if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'Home' || e.key === 'End') watchCaret(text) }}
          onBlur={() => setMenu(null)}
          onKeyDown={onKeyDown}
        />
        <div className="aikit-composer__bar">
          <span className="aikit-composer__hint">{hintNode}</span>
          <Button
            variant="primary"
            size="sm"
            className="aikit-composer__send"
            icon={<Icon name="send" />}
            loading={busy}
            disabled={!canSend}
            aria-label={__('Send')}
            title={__('Send')}
            onClick={send}
          />
        </div>
      </div>
    </div>
  )
}
