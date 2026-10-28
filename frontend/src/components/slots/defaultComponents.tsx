import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import { useSlotsWith } from '../../core/context'
import type {
  BadgeProps, ButtonProps, CheckboxProps, CopyFieldProps, MenuProps, ModalProps, SelectProps, SlotComponents,
  SpinnerProps, SwitchProps, TabsProps, TextInputProps, TooltipProps,
} from '../../core/slots'
import Icon from '../icons/Icon'

/**
 * Plain-HTML primitives: what renders when a host maps nothing. Styled by
 * `styles/base.css` through `aikit-*` classes and the `--aikit-*` tokens.
 */

function Button({ variant = 'secondary', size = 'md', loading, disabled, icon, type = 'button', title, className, onClick, children, ...aria }: ButtonProps) {
  const iconOnly = !!icon && !children
  return (
    <button
      type={type}
      className={['aikit-btn', `aikit-btn--${variant}`, size === 'sm' ? 'aikit-btn--sm' : '', iconOnly ? 'aikit-btn--icon' : '', className || ''].join(' ').trim()}
      disabled={disabled || loading}
      title={title}
      onClick={onClick}
      aria-label={aria['aria-label']}
      aria-pressed={aria['aria-pressed']}
      aria-expanded={aria['aria-expanded']}
      aria-haspopup={aria['aria-haspopup']}
      aria-controls={aria['aria-controls']}
    >
      {loading ? <span className="aikit-spinner" aria-hidden="true" /> : icon}
      {children}
    </button>
  )
}

function TextInput({ value, onChange, type = 'text', className, onKeyDown, onBlur, ...rest }: TextInputProps) {
  return (
    <input
      {...rest}
      type={type}
      className={['aikit-input', className || ''].join(' ').trim()}
      value={value}
      onChange={e => onChange(e.target.value)}
      onKeyDown={onKeyDown}
      onBlur={onBlur}
    />
  )
}

function Select({ value, onChange, options, disabled, id, className, ...aria }: SelectProps) {
  return (
    <select id={id} className={['aikit-select', className || ''].join(' ').trim()} value={value} disabled={disabled} aria-label={aria['aria-label']} onChange={e => onChange(e.target.value)}>
      {options.map(option => <option key={option.value} value={option.value} disabled={option.disabled}>{option.label}</option>)}
    </select>
  )
}

function Switch({ checked, onChange, disabled, id, ...aria }: SwitchProps) {
  return (
    <button
      type="button"
      role="switch"
      id={id}
      className="aikit-switch"
      aria-checked={checked}
      aria-label={aria['aria-label']}
      disabled={disabled}
      onClick={() => onChange(!checked)}
    />
  )
}

function Checkbox({ checked, onChange, disabled, label, id }: CheckboxProps) {
  return (
    <label className="aikit-checkbox" htmlFor={id}>
      <input id={id} type="checkbox" checked={checked} disabled={disabled} onChange={e => onChange(e.target.checked)} />
      {label}
    </label>
  )
}

function Spinner({ size = 'sm', className }: SpinnerProps) {
  return <span className={['aikit-spinner', size === 'md' ? 'aikit-spinner--md' : '', className || ''].join(' ').trim()} role="status" aria-label="Loading" />
}

/** A `<dialog>`: native focus trap and Escape handling, no portal needed. */
function Modal({ open, onClose, title, footer, width = 480, children }: ModalProps) {
  const ref = useRef<HTMLDialogElement>(null)
  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return
    if (open && !dialog.open) dialog.showModal?.()
    if (!open && dialog.open) dialog.close()
  }, [open])
  if (!open) return null
  return (
    <dialog ref={ref} className="aikit aikit-modal" style={{ width }} onClose={onClose} onCancel={onClose}>
      {title !== undefined && (
        <div className="aikit-modal__head">
          <span>{title}</span>
          <Button variant="ghost" size="sm" icon={<Icon name="close" />} aria-label="Close" onClick={onClose} />
        </div>
      )}
      <div className="aikit-modal__body">{children}</div>
      {footer && <div className="aikit-modal__foot">{footer}</div>}
    </dialog>
  )
}

function Tabs({ items, activeKey, onChange, barOnly, variant = 'line' }: TabsProps) {
  const active = items.find(item => item.key === activeKey)
  return (
    <div className="aikit-tabs__wrap">
      <div className={['aikit-tabs', variant === 'segment' ? 'aikit-tabs--segment' : ''].join(' ').trim()} role="tablist">
        {items.map(item => (
          <button key={item.key} type="button" role="tab" className="aikit-tabs__tab" aria-selected={item.key === activeKey} onClick={() => onChange(item.key)}>
            {item.label}
          </button>
        ))}
      </div>
      {!barOnly && active?.children !== undefined && <div className="aikit-tabs__panel" role="tabpanel">{active.children}</div>}
    </div>
  )
}

function Badge({ tone = 'neutral', title, children }: BadgeProps) {
  return <span className={`aikit-badge aikit-badge--${tone}`} title={title}>{children}</span>
}

function CopyField({ value, label, masked, className }: CopyFieldProps) {
  const [copied, setCopied] = useState(false)
  const copy = () => {
    navigator.clipboard?.writeText(value).then(() => {
      setCopied(true)
      window.setTimeout(() => setCopied(false), 1500)
    }).catch(() => {})
  }
  return (
    <span className={['aikit-copy', className || ''].join(' ').trim()}>
      <span className="aikit-copy__value" title={masked ? undefined : value} aria-label={label}>{masked ? '•'.repeat(Math.min(24, value.length)) : value}</span>
      <Button variant="ghost" size="sm" icon={<Icon name={copied ? 'check' : 'copy'} />} aria-label={copied ? 'Copied' : 'Copy'} title={copied ? 'Copied' : 'Copy'} onClick={copy} />
    </span>
  )
}

function Tooltip({ title, children }: TooltipProps) {
  return (
    <span className="aikit-tooltip">
      {children}
      <span className="aikit-tooltip__bubble" role="tooltip">{title}</span>
    </span>
  )
}

const MENU_GAP = 4
const MENU_EDGE = 8

/**
 * Put an open menu under its trigger, right edges aligned, or above it when
 * there is no room below. The list is `position: fixed`, so a scrolling parent
 * (the chat history's list) cannot clip it; an ancestor with a transform (a
 * sliding dock) moves where fixed starts, so that offset is measured first.
 */
function placeMenu(trigger: HTMLElement, list: HTMLElement) {
  list.style.top = '0px'
  list.style.left = '0px'
  const origin = list.getBoundingClientRect()
  const anchor = trigger.getBoundingClientRect()
  const below = anchor.bottom + MENU_GAP
  const above = anchor.top - MENU_GAP - origin.height
  const top = below + origin.height > window.innerHeight - MENU_EDGE && above >= MENU_EDGE ? above : below
  const left = Math.max(MENU_EDGE, Math.min(anchor.right - origin.width, window.innerWidth - MENU_EDGE - origin.width))
  list.style.top = `${top - origin.top}px`
  list.style.left = `${left - origin.left}px`
  list.style.visibility = 'visible'
}

function Menu({ trigger, items, ...aria }: MenuProps) {
  const [open, setOpen] = useState(false)
  const id = useId()
  const wrap = useRef<HTMLSpanElement>(null)
  const button = useRef<HTMLSpanElement>(null)
  const list = useRef<HTMLUListElement>(null)
  useLayoutEffect(() => {
    if (!open) return
    const place = () => { if (button.current && list.current) placeMenu(button.current, list.current) }
    place()
    // Fixed, the list would stay put while its trigger scrolls away: follow it.
    window.addEventListener('scroll', place, true)
    window.addEventListener('resize', place)
    return () => {
      window.removeEventListener('scroll', place, true)
      window.removeEventListener('resize', place)
    }
  }, [open])
  useEffect(() => {
    if (!open) return
    const away = (event: MouseEvent) => { if (!wrap.current?.contains(event.target as Node)) setOpen(false) }
    const esc = (event: KeyboardEvent) => { if (event.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', away)
    document.addEventListener('keydown', esc)
    return () => {
      document.removeEventListener('mousedown', away)
      document.removeEventListener('keydown', esc)
    }
  }, [open])
  return (
    <span className="aikit-menu" ref={wrap}>
      <span ref={button} role="button" tabIndex={0} aria-haspopup="menu" aria-expanded={open} aria-controls={id} onClick={() => setOpen(o => !o)} onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setOpen(o => !o) } }}>
        {trigger}
      </span>
      {open && (
        <ul ref={list} className="aikit-menu__list" role="menu" id={id} aria-label={aria['aria-label']}>
          {items.map(item => (
            <li key={item.key} role="none">
              <button type="button" role="menuitem" className={['aikit-menu__item', item.danger ? 'aikit-menu__item--danger' : ''].join(' ').trim()} disabled={item.disabled} onClick={() => { setOpen(false); item.onClick() }}>
                {item.icon}
                {item.label}
              </button>
            </li>
          ))}
        </ul>
      )}
    </span>
  )
}

export const defaultComponents: SlotComponents = {
  Button,
  TextInput,
  Select,
  Switch,
  Checkbox,
  Spinner,
  Modal,
  Tabs,
  Badge,
  CopyField,
  Tooltip,
  Menu,
  Icon,
}

/** The host's slot map with the defaults filling every gap. */
export function useSlots(): SlotComponents {
  return useSlotsWith(defaultComponents)
}
