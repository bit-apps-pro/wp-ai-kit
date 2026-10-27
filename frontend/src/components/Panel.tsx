import { type KeyboardEvent, type ReactNode, useEffect, useRef } from 'react'
import { createPortal } from 'react-dom'
import { useI18n } from '../core/context'

export interface PanelProps {
  open: boolean
  onClose: () => void
  /** `compact`: a 400px box at the bottom-right corner; `full`: a 400px column down the right edge. */
  size?: 'compact' | 'full'
  header?: ReactNode
  footer?: ReactNode
  /** Render into `document.body` instead of in place. */
  portal?: boolean
  className?: string
  children?: ReactNode
  ariaLabel?: string
}

/**
 * A thin positioned container for the chat: no backdrop, no focus trap, so the
 * screen beside it stays usable (the assistant talks about what is on it).
 * Escape closes it; focus moves into it when it opens. Docking into the host's
 * layout (narrowing the admin content, say) is the host's job.
 */
export default function Panel({ open, onClose, size = 'compact', header, footer, portal = false, className, children, ariaLabel }: PanelProps) {
  const { __ } = useI18n()
  const root = useRef<HTMLElement>(null)

  useEffect(() => {
    if (open) root.current?.focus()
  }, [open])

  if (!open) return null

  const onKeyDown = (event: KeyboardEvent<HTMLElement>) => {
    if (event.key !== 'Escape' || event.defaultPrevented) return
    event.stopPropagation()
    onClose()
  }

  const node = (
    <aside
      ref={root}
      className={['aikit', 'aikit-panel', `aikit-panel--${size}`, className || ''].join(' ').trim()}
      aria-label={ariaLabel || __('AI assistant')}
      tabIndex={-1}
      onKeyDown={onKeyDown}
    >
      {header !== undefined && <header className="aikit-panel__head">{header}</header>}
      <div className="aikit-panel__body">{children}</div>
      {footer !== undefined && <footer className="aikit-panel__foot">{footer}</footer>}
    </aside>
  )

  return portal && typeof document !== 'undefined' ? createPortal(node, document.body) : node
}
