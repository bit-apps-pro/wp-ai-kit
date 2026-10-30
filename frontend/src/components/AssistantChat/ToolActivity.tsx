import { useState } from 'react'
import { useI18n } from '../../core/context'
import type { ToolUse } from '../../core/types'
import { useSlots } from '../slots/useSlots'

/** How a reply shows the tools behind it: one summary line (default), a chip per call, or nothing. */
export type ToolActivityMode = 'summary' | 'chips' | 'hidden'

interface ToolGroup {
  name: string
  title: string
  count: number
  failed: number
  skipped: number
}

function groupTools(tools: ToolUse[]): ToolGroup[] {
  const groups = new Map<string, ToolGroup>()
  tools.filter(tool => !tool.running).forEach((tool) => {
    const group = groups.get(tool.name) ?? { name: tool.name, title: tool.title, count: 0, failed: 0, skipped: 0 }
    group.count += 1
    if (tool.skipped) group.skipped += 1
    else if (tool.error) group.failed += 1
    groups.set(tool.name, group)
  })
  return [...groups.values()]
}

/**
 * What the assistant used for a reply, readable without knowing what a tool is:
 * "Used List forms, Search entries ×27 · 2 failed", with the per-tool counts
 * behind Details. Calls the assistant did not get to run (too many at once, or
 * waiting on an approval) are counted apart from failures.
 */
export default function ToolActivity({ tools, mode = 'summary' }: { tools: ToolUse[], mode?: ToolActivityMode }) {
  const { __, sprintf } = useI18n()
  const { Badge, Button, Icon, Spinner } = useSlots()
  const [open, setOpen] = useState(false)

  if (mode === 'hidden' || tools.length === 0) return null

  if (mode === 'chips') {
    return (
      <div className="aikit-chat__tools">
        {tools.map((tool, i) => (
          // eslint-disable-next-line react/no-array-index-key
          <Badge key={i} tone={tool.error ? 'danger' : 'accent'} title={tool.name}>{tool.title}</Badge>
        ))}
      </div>
    )
  }

  const groups = groupTools(tools)
  const running = tools.find(tool => tool.running)
  const failed = groups.reduce((sum, group) => sum + group.failed, 0)
  const skipped = groups.reduce((sum, group) => sum + group.skipped, 0)
  const names = groups.map(group => (group.count > 1 ? sprintf(__('%1$s ×%2$d'), group.title, group.count) : group.title)).join(', ')

  return (
    <div className="aikit-chat__activity">
      <div className="aikit-chat__activity-line">
        {running ? <Spinner size="sm" /> : <Icon name={failed > 0 ? 'warning' : 'check'} size={12} />}
        <span className="aikit-chat__activity-text">{running ? sprintf(__('Using %s…'), running.title) : sprintf(__('Used %s'), names)}</span>
        {failed > 0 && <span className="aikit-chat__activity-failed">{sprintf(__('· %d failed'), failed)}</span>}
        {skipped > 0 && (
          <span className="aikit-chat__activity-skipped" title={__('The assistant asked for more at once than it may run in one step, and answered with what it had.')}>
            {sprintf(__('· %d not run'), skipped)}
          </span>
        )}
        {groups.length > 0 && (
          <Button variant="ghost" size="sm" className="aikit-chat__activity-toggle" aria-expanded={open} onClick={() => setOpen(o => !o)}>
            {open ? __('Hide details') : __('Details')}
          </Button>
        )}
      </div>
      {open && (
        <ul className="aikit-chat__activity-list">
          {groups.map(group => (
            <li key={group.name} title={group.name}>
              <span>{group.title}</span>
              <span className="aikit-chat__activity-count">
                {[
                  sprintf(__('%d run'), group.count - group.failed - group.skipped),
                  group.failed > 0 ? sprintf(__('%d failed'), group.failed) : '',
                  group.skipped > 0 ? sprintf(__('%d not run'), group.skipped) : '',
                ].filter(Boolean).join(' · ')}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
