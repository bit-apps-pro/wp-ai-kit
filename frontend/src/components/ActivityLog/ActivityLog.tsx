import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useI18n } from '../../core/context'
import { useActivity, useMcpState } from '../../core/hooks/useSettings'
import type { ActivityFilters, AuditEvent } from '../../core/types'
import { ResultBadge } from '../McpSettings/badges'
import { channelLabels, formatTime, statusLabels, timeAgo } from '../McpSettings/mcpUtils'
import { HelpText, HelpTip } from '../SettingHelp'
import { useSlots } from '../slots/useSlots'

export interface ActivityLogProps {
  pageSize?: number
  /** The filter toolbar; default on. */
  filters?: boolean
  /** Start on one channel (`assistant`, `mcp`, …). */
  channel?: string
  /** The caption of the record column; default "Record". */
  resourceLabel?: string
  /** The cells of one row (`<td>`s) in place of the defaults; the row and its key stay the list's. */
  renderRow?: (event: AuditEvent) => ReactNode
  /** The last few calls only, no filters or paging: the "Recent activity" card. */
  compact?: boolean
}

const EMPTY = { channel: '', tool: '', status: '', client_ref: '', user_id: '', resource_id: '', from: '', to: '' }
type Filters = typeof EMPTY

/** Local date input (YYYY-MM-DD) → UTC bound the log can compare with. */
const dayBoundUtc = (day: string, end: boolean): string => {
  if (!day) return ''
  const local = new Date(`${day}T${end ? '23:59:59' : '00:00:00'}`)
  return Number.isNaN(local.getTime()) ? '' : local.toISOString().slice(0, 19).replace('T', ' ')
}

/**
 * Every tool call by the assistant and the connected apps: filters, a table
 * with clickable values, paging and "Clear log" for users who may manage.
 */
export default function ActivityLog({ pageSize, filters: withFilters = true, channel = '', resourceLabel, renderRow, compact = false }: ActivityLogProps) {
  if (compact) return <CompactLog limit={pageSize ?? 5} renderRow={renderRow} />
  return <FullLog pageSize={pageSize ?? 25} withFilters={withFilters} channel={channel} resourceLabel={resourceLabel} renderRow={renderRow} />
}

function CompactLog({ limit, renderRow }: { limit: number, renderRow?: ActivityLogProps['renderRow'] }) {
  const i18n = useI18n()
  const { __ } = i18n
  const { Spinner } = useSlots()
  const { data, status } = useMcpState()
  const rows = (data?.recent || []).slice(0, limit)

  if (status === 'loading' || status === 'idle') return <div className="aikit-empty"><Spinner /></div>
  if (rows.length === 0) return <p className="aikit-empty">{__('No calls yet.')}</p>
  return (
    <ul className="aikit aikit-activity__recent">
      {rows.map(row => (
        <li key={`${row.id || row.created_at}-${row.tool}-${row.args_digest}`}>
          {renderRow ? renderRow(row) : (
            <>
              <code>{row.tool}</code>
              <small>{row.client_ref || row.channel}</small>
              <small className="aikit-activity__when">{timeAgo(i18n, row.created_at)}</small>
            </>
          )}
        </li>
      ))}
    </ul>
  )
}

interface FullLogProps {
  pageSize: number
  withFilters: boolean
  channel: string
  resourceLabel?: string
  renderRow?: ActivityLogProps['renderRow']
}

function FullLog({ pageSize, withFilters, channel, resourceLabel, renderRow }: FullLogProps) {
  const i18n = useI18n()
  const { __, sprintf } = i18n
  const { Button, Icon, Modal, Spinner, Badge } = useSlots()
  const activity = useActivity({ per_page: pageSize, channel: channel || undefined })
  const { data: mcp } = useMcpState()
  const [filters, setFilters] = useState<Filters>({ ...EMPTY, channel })
  const [page, setPage] = useState(1)
  const [panel, setPanel] = useState(false)
  const [confirmClear, setConfirmClear] = useState(false)
  const [clearing, setClearing] = useState(false)
  const initialStatus = useRef(activity.status)
  const first = useRef(true)

  useEffect(() => {
    // The hook's own first load already carries the preset; a store left on another query by an earlier mount is reloaded.
    if (first.current) {
      first.current = false
      if (initialStatus.current === 'idle') return
    }
    void activity.setQuery({
      page,
      per_page: pageSize,
      channel: filters.channel,
      tool: filters.tool,
      status: filters.status,
      client_ref: filters.client_ref,
      resource_id: filters.resource_id,
      user_id: filters.user_id ? Number(filters.user_id) : undefined,
      from: dayBoundUtc(filters.from, false),
      to: dayBoundUtc(filters.to, true),
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters, page, pageSize])

  const setFilter = (key: keyof Filters, value: string) => {
    setPage(1)
    setFilters(prev => ({ ...prev, [key]: value }))
  }
  const clearAll = () => {
    setPage(1)
    setFilters(EMPTY)
  }

  const clear = async () => {
    setClearing(true)
    try {
      await activity.clear()
      setConfirmClear(false)
    } finally {
      setClearing(false)
    }
  }

  const statuses = statusLabels(__)
  const channels = channelLabels(__)
  const options: Partial<ActivityFilters> = activity.data?.filters || {}
  const total = activity.data?.total || 0
  const pages = Math.max(1, Math.ceil(total / pageSize))
  const anyFilter = Object.values(filters).some(value => value !== '')
  const toolTitle = (name: string) => mcp?.tools.find(tool => tool.name === name)?.title || name
  const userName = (id: string) => options.user?.find(user => String(user.id) === id)?.name || `#${id}`
  const recordLabel = resourceLabel || __('Record')
  /** The app's name without the channel the badge beside it already shows ("Assistant · OpenAI" → "OpenAI"). */
  const appName = (row: AuditEvent): string => {
    const name = row.client_ref || ''
    const label = channels[row.channel] || row.channel
    return name.startsWith(`${label} · `) ? name.slice(label.length + 3) : (name || '—')
  }

  // One chip per filter in use (the two dates make one), each removable.
  const chips: { key: string, label: string, remove: () => void }[] = []
  const chip = (key: keyof Filters, name: string, value: string) => {
    if (filters[key]) chips.push({ key, label: `${name}: ${value}`, remove: () => setFilter(key, '') })
  }
  chip('channel', __('Source'), channels[filters.channel] || filters.channel)
  chip('client_ref', __('App'), filters.client_ref)
  chip('tool', __('Tool'), toolTitle(filters.tool))
  chip('status', __('Result'), statuses[filters.status] || filters.status)
  chip('resource_id', recordLabel, `#${filters.resource_id}`)
  chip('user_id', __('User'), userName(filters.user_id))
  if (filters.from || filters.to) {
    chips.push({ key: 'date', label: `${__('Date')}: ${filters.from || '…'} – ${filters.to || '…'}`, remove: () => { setPage(1); setFilters(prev => ({ ...prev, from: '', to: '' })) } })
  }

  /** A row value the user can click to see only calls like it. */
  const only = (key: keyof Filters, value: string, children: ReactNode) => (
    withFilters && value && filters[key] !== value
      ? <Button variant="ghost" size="sm" className="aikit-activity__cell-filter" title={__('Show only these calls')} onClick={() => setFilter(key, value)}>{children}</Button>
      : children
  )

  const rows = activity.data?.rows || []

  return (
    <section className="aikit aikit-card aikit-activity">
      <div className="aikit-card__head">
        <div>
          <h3>
            {__('Activity')}
            <span className="aikit-activity__count">{total}</span>
            <HelpTip label={__('Activity')} text={__('Every tool call by the AI assistant and connected apps. Arguments and results are never stored.')} />
          </h3>
          <HelpText text={__('Every tool call by the AI assistant and connected apps. Arguments and results are never stored.')} />
        </div>
        {activity.canClear && (
          <Button size="sm" icon={<Icon name="trash" />} aria-label={__('Clear log')} title={__('Clear log')} disabled={!total} onClick={() => setConfirmClear(true)} />
        )}
      </div>

      {withFilters && (
        <div className="aikit-activity__toolbar">
          <span className="aikit-activity__filters">
            <Button size="sm" variant={chips.length ? 'primary' : 'secondary'} icon={<Icon name="filter" />} aria-pressed={panel} onClick={() => setPanel(!panel)}>
              {__('Filters')}
              {chips.length > 0 && <span className="aikit-activity__filter-count">{chips.length}</span>}
            </Button>
            {panel && (
              <FilterPanel
                filters={filters}
                setFilter={setFilter}
                clearAll={clearAll}
                close={() => setPanel(false)}
                options={options}
                toolTitle={toolTitle}
                recordLabel={recordLabel}
              />
            )}
          </span>
          {chips.map(item => (
            <span key={item.key} className="aikit-activity__chip">
              {item.label}
              <Button variant="ghost" size="sm" icon={<Icon name="close" size={10} />} aria-label={sprintf(__('Remove filter %s'), item.label)} title={__('Remove')} onClick={item.remove} />
            </span>
          ))}
          {chips.length > 1 && <Button variant="ghost" size="sm" onClick={clearAll}>{__('Clear all')}</Button>}
          <span className="aikit-activity__toolbar-end">
            {activity.loading && <Spinner />}
            {anyFilter && !activity.loading && sprintf(total === 1 ? __('%d call matches') : __('%d calls match'), total)}
          </span>
        </div>
      )}

      {activity.error && <p className="aikit-notice aikit-notice--danger aikit-activity__error">{activity.error}</p>}

      {rows.length > 0 ? (
        <div className="aikit-mcp-table-wrap">
          <table className="aikit-mcp-table aikit-activity__table">
            <thead>
              <tr>
                <th>{__('When')}</th>
                <th>{__('Tool')}</th>
                <th>{__('App')}</th>
                <th>{__('User')}</th>
                <th>{recordLabel}</th>
                <th>{__('Result')}</th>
                <th className="aikit-mcp-table__right">{__('Time taken')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map(row => (
                <tr key={row.id ?? `${row.created_at}-${row.tool}`}>
                  {renderRow ? renderRow(row) : (
                    <>
                      <td title={formatTime(row.created_at)} className="aikit-mcp-table__fit">{timeAgo(i18n, row.created_at)}</td>
                      <td>
                        {only('tool', row.tool, <span className="aikit-activity__tool">{toolTitle(row.tool)}</span>)}
                        <code className="aikit-activity__code">{row.tool}</code>
                      </td>
                      <td>
                        {only('client_ref', row.client_ref || '', appName(row))}
                        {' '}
                        <Badge tone={row.channel === 'assistant' ? 'accent' : 'neutral'}>{channels[row.channel] || row.channel}</Badge>
                      </td>
                      <td>{only('user_id', row.user_id ? String(row.user_id) : '', row.user || (row.user_id ? `#${row.user_id}` : '—'))}</td>
                      <td>{row.resource_id ? only('resource_id', row.resource_id, `#${row.resource_id}`) : (row.item_count ? (row.item_count === 1 ? __('1 item') : sprintf(__('%d items'), row.item_count)) : '—')}</td>
                      <td>
                        {only('status', row.status, <ResultBadge status={row.status} />)}
                        {row.error_code && <code className="aikit-activity__code">{row.error_code}</code>}
                      </td>
                      <td className="aikit-mcp-table__right aikit-muted">{`${row.duration_ms} ms`}</td>
                    </>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        !activity.loading && (
          <div className="aikit-empty">
            <strong>{anyFilter ? __('No call matches these filters.') : __('No calls yet.')}</strong>
            {!anyFilter && <small className="aikit-activity__hint">{__('When the assistant or an AI app uses a tool, it shows up here.')}</small>}
          </div>
        )
      )}

      {total > pageSize && (
        <div className="aikit-mcp__foot">
          <span>{sprintf(__('Page %1$d of %2$d'), page, pages)}</span>
          <span className="aikit-activity__pager">
            <Button size="sm" onClick={() => setPage(page - 1)} disabled={page <= 1}>{__('Previous')}</Button>
            <Button size="sm" onClick={() => setPage(page + 1)} disabled={page >= pages}>{__('Next')}</Button>
          </span>
        </div>
      )}

      <Modal
        open={confirmClear}
        onClose={() => setConfirmClear(false)}
        title={__('Clear log')}
        footer={(
          <>
            <Button onClick={() => setConfirmClear(false)} disabled={clearing}>{__('Cancel')}</Button>
            <Button variant="danger" loading={clearing} onClick={() => { void clear() }}>{__('Delete all')}</Button>
          </>
        )}
      >
        {__('Delete every entry in the activity log (AI assistant and MCP apps)? This cannot be undone.')}
      </Modal>
    </section>
  )
}

interface FilterPanelProps {
  filters: Filters
  setFilter: (key: keyof Filters, value: string) => void
  clearAll: () => void
  close: () => void
  options: Partial<ActivityFilters>
  toolTitle: (name: string) => string
  recordLabel: string
}

/** The Filters panel: every filter, labelled; each change applies at once. */
function FilterPanel({ filters, setFilter, clearAll, close, options, toolTitle, recordLabel }: FilterPanelProps) {
  const { __ } = useI18n()
  const { Button, Select, TextInput } = useSlots()
  const [record, setRecord] = useState(filters.resource_id)
  useEffect(() => setRecord(filters.resource_id), [filters.resource_id])
  const applyRecord = () => {
    const value = record.trim().replace(/^#/, '')
    if (value !== filters.resource_id) setFilter('resource_id', value)
  }
  const statuses = statusLabels(__)
  const channels = channelLabels(__)
  const withAll = (all: string, items: { value: string, label: string }[]) => [{ value: '', label: all }, ...items]

  return (
    <div className="aikit-activity__panel" role="dialog" aria-label={__('Filters')}>
      <div className="aikit-activity__panel-grid">
        <label className="aikit-activity__field">
          <span>{__('Source')}</span>
          <Select value={filters.channel} onChange={value => setFilter('channel', value)} options={withAll(__('All sources'), (options.channel || []).map(value => ({ value, label: channels[value] || value })))} />
        </label>
        <label className="aikit-activity__field">
          <span>{__('App')}</span>
          <Select value={filters.client_ref} onChange={value => setFilter('client_ref', value)} options={withAll(__('All apps'), (options.client_ref || []).map(value => ({ value, label: value })))} />
        </label>
        <label className="aikit-activity__field">
          <span>{__('Tool')}</span>
          <Select value={filters.tool} onChange={value => setFilter('tool', value)} options={withAll(__('All tools'), (options.tool || []).map(value => ({ value, label: toolTitle(value) })))} />
        </label>
        <label className="aikit-activity__field">
          <span>{__('Result')}</span>
          <Select value={filters.status} onChange={value => setFilter('status', value)} aria-label={__('Result')} options={withAll(__('All results'), (options.status || []).map(value => ({ value, label: statuses[value] || value })))} />
        </label>
        <label className="aikit-activity__field">
          <span>{recordLabel}</span>
          <TextInput value={record} onChange={setRecord} placeholder={__('Any')} onBlur={applyRecord} onKeyDown={(event) => { if (event.key === 'Enter') applyRecord() }} />
        </label>
        <label className="aikit-activity__field">
          <span>{__('User')}</span>
          <Select value={filters.user_id} onChange={value => setFilter('user_id', value)} options={withAll(__('All users'), (options.user || []).map(user => ({ value: String(user.id), label: user.name || `#${user.id}` })))} />
        </label>
        <label className="aikit-activity__field">
          <span>{__('From')}</span>
          <input type="date" className="aikit-input" value={filters.from} max={filters.to || undefined} onChange={event => setFilter('from', event.target.value)} />
        </label>
        <label className="aikit-activity__field">
          <span>{__('To')}</span>
          <input type="date" className="aikit-input" value={filters.to} min={filters.from || undefined} onChange={event => setFilter('to', event.target.value)} />
        </label>
      </div>
      <div className="aikit-activity__panel-foot">
        <small className="aikit-muted">{__('Tip: click a tool, app, record or user in the list to filter by it.')}</small>
        <span>
          <Button variant="ghost" size="sm" onClick={clearAll}>{__('Clear all')}</Button>
          <Button size="sm" onClick={close}>{__('Done')}</Button>
        </span>
      </div>
    </div>
  )
}
