import type { I18n } from '../../core/i18n'
import type { AccessLevel } from '../../core/types'

/** The kit stores times in UTC (`YYYY-MM-DD HH:MM:SS`); show them in the browser's time zone. */
export const utcToDate = (value: string | null | undefined): Date | null => (value ? new Date(`${String(value).replace(' ', 'T')}Z`) : null)

export const formatTime = (value: string | null | undefined): string => {
  const date = utcToDate(value)
  return date && !Number.isNaN(date.getTime()) ? date.toLocaleString() : ''
}

export const timeAgo = ({ __, sprintf }: I18n, value: string | null | undefined): string => {
  const date = utcToDate(value)
  if (!date || Number.isNaN(date.getTime())) return ''
  const seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000))
  if (seconds < 60) return __('just now')
  if (seconds < 3600) return sprintf(__('%d min ago'), Math.floor(seconds / 60))
  if (seconds < 86400) return sprintf(__('%d h ago'), Math.floor(seconds / 3600))
  return date.toLocaleDateString()
}

/** Unix seconds (OAuth grants, API keys) to a local date-time. */
export const formatUnix = (seconds: number | null | undefined): string => (seconds ? new Date(seconds * 1000).toLocaleString() : '—')

/** `now` in the kit's UTC format, for "since" filters. */
export const utcNow = (): string => new Date().toISOString().slice(0, 19).replace('T', ' ')

export const statusLabels = (__: I18n['__']): Record<string, string> => ({
  ok: __('OK'),
  denied: __('Denied'),
  error: __('Error'),
  rate_limited: __('Rate limited'),
  confirmation_required: __('Needs confirmation'),
})

export const channelLabels = (__: I18n['__']): Record<string, string> => ({
  mcp: 'MCP',
  assistant: __('Assistant'),
  abilities: __('Abilities API'),
  cli: 'WP-CLI',
})

export const accessLevels = (__: I18n['__']): { value: AccessLevel, label: string, help: string }[] => [
  { value: 'read', label: __('Read only'), help: __('Reads your data and settings. Changes nothing.') },
  { value: 'write', label: __('Read and write'), help: __('Also makes changes that can be undone, like renaming.') },
  { value: 'destructive', label: __('Read, write and delete'), help: __('Also actions that cannot be undone, each confirmed first.') },
]

export const ACCESS_ORDER: Record<AccessLevel, number> = { read: 0, write: 1, destructive: 2 }
