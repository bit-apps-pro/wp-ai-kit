import { useState } from 'react'
import { useI18n } from '../../core/context'
import { useDiagnostics } from '../../core/hooks/useSettings'
import type { RequirementCheck } from '../../core/types'
import { useSlots } from '../slots/useSlots'

export interface DiagnosticsListProps {
  /** Default: the checks from the MCP state. */
  requirements?: RequirementCheck[]
  /** Only the warnings and failures. */
  onlyFailing?: boolean
  /** Default: a one-line summary with "Show checks"; false lists the checks at once. */
  collapsible?: boolean
}

const STATUS_ICON: Record<string, string> = { pass: '✓', warn: '!', info: 'i', fail: '×' }

/** The requirement checks (PHP, permalinks, HTTPS, …) as a card with a summary line. */
export default function DiagnosticsList({ requirements, onlyFailing = false, collapsible = true }: DiagnosticsListProps) {
  const { __, sprintf } = useI18n()
  const { Button, Spinner } = useSlots()
  const fromState = useDiagnostics()
  const checks = requirements ?? fromState.requirements
  const loading = !requirements && fromState.loading
  const [open, setOpen] = useState(!collapsible)

  const issues = checks.filter(check => check.status === 'warn' || check.status === 'fail')
  const shown = onlyFailing ? issues : checks
  const summary = issues.length
    ? sprintf(__('%1$d of %2$d checks need a look.'), issues.length, checks.length)
    : sprintf(__('All %d checks passed.'), checks.length)

  return (
    <section className="aikit aikit-card aikit-diag">
      <div className="aikit-card__head">
        <div>
          <h3>{__('Diagnostics')}</h3>
          <small>{loading ? __('Checking…') : summary}</small>
        </div>
        {loading && <Spinner />}
        {!loading && collapsible && <Button size="sm" onClick={() => setOpen(!open)} aria-pressed={open}>{open ? __('Hide') : __('Show checks')}</Button>}
      </div>
      {open && !loading && (
        <div className="aikit-card__body">
          {shown.length === 0 && <p className="aikit-empty">{onlyFailing ? __('Nothing needs a look.') : __('No checks to show.')}</p>}
          <ul className="aikit-diag__list">
            {shown.map(check => (
              <li key={check.id} className="aikit-diag__check">
                <span className={`aikit-diag__status aikit-diag__status--${check.status}`} aria-hidden="true">{STATUS_ICON[check.status] || '•'}</span>
                <span>
                  <strong>{check.label}</strong>
                  <small>{check.detail}</small>
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}
