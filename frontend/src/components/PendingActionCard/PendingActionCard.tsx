import { useI18n } from '../../core/context'
import type { PendingAction } from '../../core/types'
import { useSlots } from '../slots/useSlots'

export interface PendingActionCardProps {
  pending: PendingAction
  onDecide: (approve: boolean) => void
  busy?: boolean
}

function describe(value: unknown): string {
  return value !== null && typeof value === 'object' ? JSON.stringify(value) : String(value)
}

/**
 * The assistant wants to run a tool that changes something: what it is, with
 * what input, and the Allow / Cancel pair. Nothing runs until the user decides.
 */
export default function PendingActionCard({ pending, onDecide, busy = false }: PendingActionCardProps) {
  const { __ } = useI18n()
  const { Button } = useSlots()
  const entries = Object.entries(pending.input || {})

  return (
    <div className={['aikit', 'aikit-pending', pending.destructive ? 'aikit-pending--destructive' : ''].join(' ').trim()} role="alertdialog" aria-label={__('Confirm action')}>
      <div className="aikit-pending__title">{__('The assistant wants to:')} {pending.title}</div>
      {pending.description && <div className="aikit-pending__text">{pending.description}</div>}
      {entries.length > 0 && (
        <ul className="aikit-pending__args">
          {entries.map(([key, value]) => <li key={key}><code>{key}</code>: {describe(value)}</li>)}
        </ul>
      )}
      <div className="aikit-pending__note">{pending.destructive ? __('This cannot be undone.') : __('This changes your site. Nothing runs until you allow it.')}</div>
      <div className="aikit-pending__actions">
        <Button variant="secondary" size="sm" disabled={busy} onClick={() => onDecide(false)}>{__('Cancel')}</Button>
        <Button variant={pending.destructive ? 'danger' : 'primary'} size="sm" disabled={busy} onClick={() => onDecide(true)}>{__('Allow')}</Button>
      </div>
    </div>
  )
}
