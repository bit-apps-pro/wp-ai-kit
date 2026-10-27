import { useMemo, useState } from 'react'
import { useI18n } from '../../core/context'
import type { AccessLevel, ToolInfo } from '../../core/types'
import { AccessBadge } from '../McpSettings/badges'
import { ACCESS_ORDER, accessLevels } from '../McpSettings/mcpUtils'
import { HelpText, HelpTip } from '../SettingHelp'
import { useSlots } from '../slots/useSlots'

export interface ToolListProps {
  tools: ToolInfo[]
  /** Names switched off (`settings.disabled_tools`). */
  disabled?: string[]
  /** The highest level any app gets; tools above it are off and cannot be switched. */
  accessLevel?: AccessLevel
  onToggle?: (name: string, enabled: boolean) => void
  /** Given, the access-level cards show above the list. */
  onAccessLevel?: (level: AccessLevel) => void
  /** Start with the descriptions open. */
  showDescriptions?: boolean
  readOnly?: boolean
}

type Filter = 'all' | AccessLevel

/**
 * The tools an MCP server offers, grouped by access: the level cards, then the
 * list with a switch per tool, a search box and a show-descriptions option.
 */
export default function ToolList({ tools, disabled = [], accessLevel = 'destructive', onToggle, onAccessLevel, showDescriptions = false, readOnly = false }: ToolListProps) {
  const { __, sprintf } = useI18n()
  const { Badge, Checkbox, Switch, Tabs, TextInput } = useSlots()
  const [search, setSearch] = useState('')
  const [filter, setFilter] = useState<Filter>('all')
  // Descriptions are long; the list reads as names only until asked for (the search still covers them).
  const [descriptions, setDescriptions] = useState(showDescriptions)
  const disabledSet = useMemo(() => new Set(disabled), [disabled])
  const levels = accessLevels(__)

  const withinLevel = (tool: ToolInfo) => ACCESS_ORDER[tool.access] <= ACCESS_ORDER[accessLevel]
  const allowed = (tool: ToolInfo) => withinLevel(tool) && !disabledSet.has(tool.name)

  const needle = search.trim().toLowerCase()
  const matching = tools.filter(tool => (filter === 'all' || tool.access === filter) && (!needle || `${tool.title} ${tool.name} ${tool.description}`.toLowerCase().includes(needle)))
  const enabledCount = tools.filter(allowed).length
  const filters: { key: Filter, label: string }[] = [
    { key: 'all', label: __('All') },
    { key: 'read', label: __('Read') },
    { key: 'write', label: __('Write') },
    { key: 'destructive', label: __('Delete') },
  ]

  return (
    <div className="aikit aikit-tools">
      {onAccessLevel && (
        <section className="aikit-card">
          <div className="aikit-card__body">
            <h3 className="aikit-tools__title">
              {__('What AI apps may do')}
              <HelpTip label={__('What AI apps may do')} text={__('The highest level any app gets. An app signed in with OAuth can be limited further when it is approved, never raised above this.')} />
            </h3>
            <HelpText className="aikit-muted" text={__('The highest level any app gets. An app signed in with OAuth can be limited further when it is approved, never raised above this.')} />
            <div role="radiogroup" aria-label={__('Access level')} className="aikit-tools__levels">
              {levels.map(level => (
                <label key={level.value} className={['aikit-tools__level', accessLevel === level.value ? 'aikit-tools__level--active' : ''].join(' ').trim()}>
                  <input
                    type="radio"
                    name="aikit-access-level"
                    value={level.value}
                    checked={accessLevel === level.value}
                    disabled={readOnly}
                    onChange={() => onAccessLevel(level.value)}
                  />
                  <span>
                    <strong>{level.label}</strong>
                    <small>{level.help}</small>
                  </span>
                </label>
              ))}
            </div>
          </div>
        </section>
      )}

      <section className="aikit-card">
        <div className="aikit-tools__toolbar">
          <h3 className="aikit-tools__title">{__('Tools')}</h3>
          <Checkbox checked={descriptions} onChange={setDescriptions} label={__('Show descriptions')} />
          <TextInput type="search" value={search} onChange={setSearch} placeholder={__('Search tools')} aria-label={__('Search tools')} className="aikit-tools__search" />
        </div>
        <div className="aikit-tools__filters">
          <Tabs barOnly variant="segment" activeKey={filter} onChange={key => setFilter(key as Filter)} items={filters.map(item => ({ key: item.key, label: item.label }))} />
        </div>
        {matching.length === 0 ? (
          <p className="aikit-empty">{__('No tool matches.')}</p>
        ) : (
          <div className="aikit-mcp-table-wrap">
            <table className="aikit-mcp-table">
              <tbody>
                {matching.map(tool => (
                  <tr key={tool.name}>
                    <td title={descriptions ? undefined : tool.description}>
                      <span className="aikit-tools__name">{tool.title}</span>
                      {' '}
                      <code>{tool.name}</code>
                      <span className="aikit-tools__badges">
                        {tool.tier === 'pro' && <Badge tone="accent">{__('Pro')}</Badge>}
                        {tool.personal && <Badge>{__('Personal data')}</Badge>}
                      </span>
                      {descriptions && <small className="aikit-tools__text">{tool.description}</small>}
                    </td>
                    <td className="aikit-mcp-table__fit"><AccessBadge level={tool.access} /></td>
                    <td className="aikit-mcp-table__fit">
                      <Switch
                        checked={allowed(tool)}
                        disabled={readOnly || !withinLevel(tool) || !onToggle}
                        aria-label={sprintf(__('Allow %s'), tool.title)}
                        onChange={() => onToggle?.(tool.name, !allowed(tool))}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <div className="aikit-mcp__foot">
          <span>{sprintf(__('%1$d of %2$d tools enabled'), enabledCount, tools.length)}</span>
          <small className="aikit-muted">{__('Changes apply to every app on its next call.')}</small>
        </div>
      </section>
    </div>
  )
}
