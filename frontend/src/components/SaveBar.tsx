import type { CSSProperties } from 'react'
import { useI18n } from '../core/context'
import { useSlots } from './slots/useSlots'

export interface SaveBarProps {
  /** Something differs from what is saved; the bar renders nothing otherwise. */
  dirty: boolean
  saving?: boolean
  onSave: () => void
  onDiscard: () => void
  /** Defaults to "You have unsaved changes." */
  message?: string
  /**
   * Pin the bar to the top of its scroll container while there is something to
   * save (default). The offset below a fixed host bar is `--aikit-sticky-top`
   * (WordPress: `var(--wp-admin--admin-bar--height)`), 0 by default.
   */
  sticky?: boolean
  /** The top offset for the sticky bar; sets `--aikit-sticky-top`. */
  stickyTop?: string
}

/**
 * The unsaved-changes bar a settings screen shows between its title and its
 * cards: a warning-tinted strip with Discard and Save.
 */
export default function SaveBar({ dirty, saving = false, onSave, onDiscard, message, sticky = true, stickyTop }: SaveBarProps) {
  const { __ } = useI18n()
  const { Button } = useSlots()

  if (!dirty) return null

  const style = stickyTop ? ({ '--aikit-sticky-top': stickyTop } as CSSProperties) : undefined

  return (
    <div className={['aikit', 'aikit-savebar', sticky ? 'aikit-savebar--sticky' : ''].join(' ').trim()} role="region" aria-label={__('Unsaved changes')} style={style}>
      <span className="aikit-savebar__text">{message ?? __('You have unsaved changes.')}</span>
      <span className="aikit-savebar__actions">
        <Button variant="secondary" size="sm" onClick={onDiscard} disabled={saving}>{__('Discard')}</Button>
        <Button variant="primary" size="sm" onClick={onSave} loading={saving}>{__('Save changes')}</Button>
      </span>
    </div>
  )
}
