import {
  createContext, type CSSProperties, type ReactNode, useCallback, useContext, useEffect, useId, useRef, useState,
} from 'react'
import { useI18n } from '../core/context'
import { useSlots } from './slots/useSlots'

/**
 * How the settings screens show what a setting does: `tooltip` (default) puts it
 * behind an info button beside the label, so the screen reads as a list of
 * settings; `inline` prints it under the label. A short `note` (what a setting
 * changes for privacy or security, or its current state) shows in both modes.
 */
export type HelpMode = 'tooltip' | 'inline'

const HelpModeContext = createContext<HelpMode>('tooltip')

export function HelpModeProvider({ mode, children }: { mode?: HelpMode, children: ReactNode }) {
  const parent = useContext(HelpModeContext)
  return <HelpModeContext.Provider value={mode ?? parent}>{children}</HelpModeContext.Provider>
}

export function useHelpMode(): HelpMode {
  return useContext(HelpModeContext)
}

/** Room the bubble keeps from the viewport edges, and its widest size. */
const EDGE = 12
const HALF_WIDTH = 140

/**
 * The info button beside a label (tooltip mode only). Opens on hover, focus and
 * tap; the text is the button's description for screen readers. The bubble is
 * fixed-positioned so cards that clip their overflow do not cut it off.
 */
export function HelpTip({ text, label }: { text?: ReactNode, label: string }) {
  const mode = useHelpMode()
  const { __, sprintf } = useI18n()
  const { Icon } = useSlots()
  const id = useId()
  const button = useRef<HTMLButtonElement>(null)
  const [style, setStyle] = useState<CSSProperties | null>(null)

  const show = useCallback(() => {
    const rect = button.current?.getBoundingClientRect()
    if (!rect) return
    const below = rect.top < 140
    const center = Math.min(Math.max(rect.left + rect.width / 2, EDGE + HALF_WIDTH), window.innerWidth - EDGE - HALF_WIDTH)
    setStyle({
      left: center,
      top: below ? rect.bottom + 8 : rect.top - 8,
      transform: below ? 'translateX(-50%)' : 'translate(-50%, -100%)',
    })
  }, [])
  const hide = useCallback(() => setStyle(null), [])

  // A scrolled page would leave the bubble behind.
  useEffect(() => {
    if (!style) return undefined
    window.addEventListener('scroll', hide, true)
    window.addEventListener('resize', hide)
    return () => {
      window.removeEventListener('scroll', hide, true)
      window.removeEventListener('resize', hide)
    }
  }, [style, hide])

  if (mode !== 'tooltip' || !text) return null

  return (
    <span className="aikit-help-tip" onMouseEnter={show} onMouseLeave={hide}>
      <button
        ref={button}
        type="button"
        className="aikit-help-tip__btn"
        aria-label={sprintf(__('About: %s'), label)}
        aria-describedby={id}
        onFocus={show}
        onBlur={hide}
        onClick={(event) => {
          event.preventDefault()
          if (style) hide()
          else show()
        }}
        onKeyDown={(event) => {
          if (event.key === 'Escape' && style) {
            event.preventDefault()
            event.stopPropagation()
            hide()
          }
        }}
      >
        <Icon name="info" size={14} />
      </button>
      <span id={id} role="tooltip" className={['aikit-help-tip__bubble', style ? 'aikit-help-tip__bubble--open' : ''].join(' ').trim()} style={style ?? undefined}>
        {text}
      </span>
    </span>
  )
}

/** The same text under the label, in inline mode only. */
export function HelpText({ text, className }: { text?: ReactNode, className?: string }) {
  if (useHelpMode() !== 'inline' || !text) return null
  return <small className={['aikit-help-text', className || ''].join(' ').trim()}>{text}</small>
}

export interface SettingTextProps {
  label: ReactNode
  /** Names the info button for screen readers when `label` is not plain text. */
  labelText?: string
  /** Points the label at its control. */
  htmlFor?: string
  /** Render the label as this row's heading (`h3` with this class). */
  headingClass?: string
  labelClass?: string
  /** What the setting does: behind the info button, or under the label in inline mode. */
  help?: ReactNode
  /** Always shown: a privacy or security consequence, or the setting's current state. Keep it to one line. */
  note?: ReactNode
}

/** A setting's label column: the label, its info button, and the note. */
export function SettingText({ label, labelText, htmlFor, headingClass, labelClass, help, note }: SettingTextProps) {
  const name = labelText ?? (typeof label === 'string' ? label : '')
  const labelNode = htmlFor
    ? <label htmlFor={htmlFor} className={labelClass}>{label}</label>
    : <span className={labelClass}>{label}</span>

  return (
    <div className="aikit-row__text">
      <span className="aikit-row__title">
        {headingClass ? <h3 className={headingClass}>{labelNode}</h3> : labelNode}
        <HelpTip text={help} label={name} />
      </span>
      {note && <small className="aikit-row__note">{note}</small>}
      <HelpText text={help} />
    </div>
  )
}
