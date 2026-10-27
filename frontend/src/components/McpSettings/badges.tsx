import { useI18n } from '../../core/context'
import type { BadgeProps } from '../../core/slots'
import type { AccessLevel } from '../../core/types'
import { useSlots } from '../slots/useSlots'
import { accessLevels, statusLabels } from './mcpUtils'

const ACCESS_TONE: Record<AccessLevel, BadgeProps['tone']> = { read: 'info', write: 'warning', destructive: 'danger' }

const RESULT_TONE: Record<string, BadgeProps['tone']> = {
  ok: 'success',
  denied: 'warning',
  error: 'danger',
  rate_limited: 'danger',
  confirmation_required: 'info',
}

/** A tool's or grant's access level as a coloured pill. */
export function AccessBadge({ level }: { level: AccessLevel | string }) {
  const { __ } = useI18n()
  const { Badge } = useSlots()
  const known = accessLevels(__).find(item => item.value === level)
  return <Badge tone={known ? ACCESS_TONE[known.value] : 'neutral'}>{known ? known.label : level}</Badge>
}

/** How a tool call ended, as a coloured pill. */
export function ResultBadge({ status }: { status: string }) {
  const { __ } = useI18n()
  const { Badge } = useSlots()
  return <Badge tone={RESULT_TONE[status] || 'neutral'}>{statusLabels(__)[status] || status}</Badge>
}
