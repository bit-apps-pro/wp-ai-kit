import type { ReactNode } from 'react'
import { useFeature, useI18n } from '../../core/context'
import { SettingText } from '../SettingHelp'
import { useSlots } from '../slots/useSlots'
import { StandaloneSave, useMcpForm } from './McpFormContext'

export interface McpSettingsFormProps {
  /** The "Let AI apps connect" card; default on. Off when the host shows the switch elsewhere. */
  showServerSwitch?: boolean
}

/** MCP server settings: the switch, personal data, safety limits, the activity log, advanced. */
export default function McpSettingsForm({ showServerSwitch = true }: McpSettingsFormProps) {
  const { __ } = useI18n()
  const { Spinner, Switch, TextInput } = useSlots()
  const form = useMcpForm()
  const { state, draft, update } = form
  const auditOn = useFeature('audit')
  const abilitiesOn = useFeature('abilities')
  const adapterOn = useFeature('mcpAdapter')

  if (!state || !draft) return form.loading ? <div className="aikit-empty"><Spinner /></div> : null

  const passes = (id: string) => state.requirements?.some(check => check.id === id && check.status === 'pass')
  const hasAbilities = passes('abilities_api')
  const hasAdapter = passes('mcp_adapter')
  const forcedOff = !!state.forced_off?.mcp

  const number = (value: number, min: number, max: number, onChange: (value: number) => void, label: string) => (
    <TextInput
      type="number"
      min={min}
      max={max}
      className="aikit-mcp__number"
      aria-label={label}
      value={String(value)}
      onChange={raw => onChange(Math.min(max, Math.max(min, Math.round(Number(raw) || min))))}
    />
  )

  return (
    <div className="aikit aikit-mcp-form">
      {showServerSwitch && (
        <Card title={__('MCP server')}>
          <Row
            label={__('Let AI apps connect')}
            help={__('Apps you approve can use the tools allowed on the Tools tab. Off: the endpoint does not exist.')}
            note={forcedOff ? __('Turned off for this site by a constant or filter.') : undefined}
          >
            <Switch checked={!!draft.enabled && !forcedOff} disabled={forcedOff} aria-label={__('Let AI apps connect')} onChange={checked => update({ enabled: checked })} />
          </Row>
        </Card>
      )}

      <Card title={__('Personal data')}>
        <Row
          label={__('Show personal data to apps signed in with an Application Password')}
          note={__('Off: personal data is masked before it reaches the AI provider.')}
          help={__('Email addresses, phone numbers, names, addresses and uploaded files are masked while this is off. Apps signed in with OAuth ask this on their approval page instead. Passwords and credentials are never shared.')}
        >
          <Switch checked={!!draft.allow_personal_data} aria-label={__('Show personal data')} onChange={checked => update({ allow_personal_data: checked })} />
        </Row>
      </Card>

      <Card title={__('Safety')}>
        <Row
          label={__('Ask before actions that cannot be undone')}
          help={__('The app must show the user what will happen and call again with a one-time confirmation code. Keep this on unless only trusted automation connects.')}
        >
          <Switch checked={!!draft.confirm_destructive} aria-label={__('Ask before actions that cannot be undone')} onChange={checked => update({ confirm_destructive: checked })} />
        </Row>
        <Row label={__('Tool calls per minute')} help={__('Per user and app. Calls above this get “Too many requests”.')}>
          {number(draft.calls_per_minute, 1, 1000, value => update({ calls_per_minute: value }), __('Tool calls per minute'))}
        </Row>
        <Row label={__('Changes per minute')} help={__('Per user and app, for tools that change something.')}>
          {number(draft.writes_per_minute, 1, 500, value => update({ writes_per_minute: value }), __('Changes per minute'))}
        </Row>
      </Card>

      {auditOn && (
        <Card title={__('Activity log')}>
          <Row label={__('Keep activity for (days)')} help={__('Older entries are deleted once a day.')}>
            {number(draft.audit_retention_days, 1, 365, value => update({ audit_retention_days: value }), __('Keep activity for (days)'))}
          </Row>
        </Card>
      )}

      {(abilitiesOn || adapterOn) && (
        <Card title={__('Advanced')}>
          {abilitiesOn && (
            <Row
              label={__('Offer the tools as WordPress abilities')}
              help={hasAbilities ? __('Lets WordPress AI features and other plugins use the read and write tools, and lists them under /wp-json/wp-abilities/v1 for signed-in users with access. Not needed for MCP; off keeps other requests lighter.') : undefined}
              note={hasAbilities ? undefined : __('Needs the WordPress Abilities API (WordPress 6.9 or newer).')}
            >
              <Switch checked={!!draft.abilities_in_rest} disabled={!hasAbilities} aria-label={__('Offer the tools as WordPress abilities')} onChange={checked => update({ abilities_in_rest: checked })} />
            </Row>
          )}
          {adapterOn && (
            <Row
              label={__('Also list the tools on the MCP Adapter’s default server')}
              help={hasAdapter ? __('Off: they are only on this plugin’s own MCP server.') : undefined}
              note={hasAdapter ? undefined : __('Needs the WordPress MCP Adapter plugin. This plugin’s own endpoint does not.')}
            >
              <Switch checked={!!draft.expose_on_default_server} disabled={!hasAdapter} aria-label={__('Also list the tools on the MCP Adapter’s default server')} onChange={checked => update({ expose_on_default_server: checked })} />
            </Row>
          )}
        </Card>
      )}

      <StandaloneSave form={form} />
    </div>
  )
}

function Card({ title, children }: { title: string, children: ReactNode }) {
  return (
    <section className="aikit-card">
      <div className="aikit-card__body">
        <h3 className="aikit-mcp__h3">{title}</h3>
        {children}
      </div>
    </section>
  )
}

function Row({ label, help, note, children }: { label: string, help?: string, note?: string, children: ReactNode }) {
  return (
    <div className="aikit-row aikit-mcp__row">
      <SettingText label={label} labelClass="aikit-mcp__label" help={help} note={note} />
      {children}
    </div>
  )
}
