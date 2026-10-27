import { useState, type ReactNode } from 'react'
import { useBootstrap, useFeature, useI18n } from '../../core/context'
import { useMcpSettings } from '../../core/hooks/useSettings'
import type { McpState } from '../../core/types'
import ActivityLog from '../ActivityLog/ActivityLog'
import ApiKeysCard from '../ApiKeysCard/ApiKeysCard'
import ConnectAppGuide from '../ConnectAppGuide/ConnectAppGuide'
import type { ClientDefinition } from '../ConnectAppGuide/clients'
import ConnectionsList from '../ConnectionsList/ConnectionsList'
import DiagnosticsList from '../DiagnosticsList/DiagnosticsList'
import { McpIcon } from '../icons/ai-clients'
import ToolList from '../ToolList/ToolList'
import { useSlots } from '../slots/useSlots'
import { McpFormProvider, type McpForm } from './McpFormContext'
import McpSettingsForm from './McpSettingsForm'
import { HelpModeProvider, HelpText, HelpTip, type HelpMode, SettingText } from '../SettingHelp'

export type McpTabId = 'overview' | 'connections' | 'tools' | 'activity' | 'settings'

const ALL_TABS: McpTabId[] = ['overview', 'connections', 'tools', 'activity', 'settings']

export interface McpSaveBarProps {
  dirty: boolean
  saving: boolean
  save: () => void
  discard: () => void
}

export interface McpSettingsProps {
  /** The plugin the server belongs to: its name for the apps, the slug they file it under. */
  server: { name: string, slug: string, icon?: ReactNode }
  /** Which tabs to show, in order; default all five. */
  tabs?: McpTabId[]
  /** Controlled tab (hosts with routes); pair with `onTabChange`. */
  tab?: McpTabId
  defaultTab?: McpTabId
  onTabChange?: (tab: McpTabId) => void
  /** Extra or replacement apps for the connect guide. */
  clients?: ClientDefinition[]
  /** The Application Passwords section of the user's profile. */
  profileUrl?: string
  /** Default: the bootstrap's `docsUrl`. */
  docsUrl?: string
  /** The title row, links and lead paragraph; default on. */
  showIntro?: boolean
  /** The intro's lead paragraph; default a generic one naming `server.name`. */
  description?: ReactNode
  /** What each setting does: behind an info button (`tooltip`, default) or printed under its label (`inline`). */
  help?: HelpMode
  onSaved?: (state: McpState) => void
  /** Replace the sticky "unsaved changes" bar. */
  renderSaveBar?: (props: McpSaveBarProps) => ReactNode
}

/**
 * The MCP server screen: Overview (switch, URL, connect guide, recent calls,
 * diagnostics), Connections (OAuth apps, API keys), Tools, Activity, Settings.
 * One draft is shared by every tab and saved from one bar.
 */
export default function McpSettings({ server, tabs: requestedTabs = ALL_TABS, tab, defaultTab, onTabChange, clients, profileUrl, docsUrl, showIntro = true, description, help, onSaved, renderSaveBar }: McpSettingsProps) {
  const { __, sprintf } = useI18n()
  const bootstrap = useBootstrap()
  const { Badge, Button, Spinner, Tabs } = useSlots()
  const form = useMcpSettings()
  const auditOn = useFeature('audit')
  const oauthOn = useFeature('oauth')
  const apiKeysOn = useFeature('apiKeys')
  // Parts the plugin left out take their tab with them.
  const tabs = requestedTabs.filter(id => (id !== 'activity' || auditOn) && (id !== 'connections' || oauthOn || apiKeysOn || !!profileUrl))
  const [ownTab, setOwnTab] = useState<McpTabId>(defaultTab && tabs.includes(defaultTab) ? defaultTab : tabs[0])
  const active: McpTabId = tab && tabs.includes(tab) ? tab : (tabs.includes(ownTab) ? ownTab : tabs[0])
  const goTo = (id: McpTabId) => {
    if (!tabs.includes(id)) return
    if (tab === undefined) setOwnTab(id)
    onTabChange?.(id)
  }

  const { state, draft } = form
  const serverOn = !!state?.settings.enabled && !state?.forced_off?.mcp
  const connected = state?.oauth?.connections?.length || 0
  const statusText = serverOn
    ? (connected ? sprintf(connected === 1 ? __('MCP server on · %d app connected') : __('MCP server on · %d apps connected'), connected) : __('MCP server on'))
    : __('MCP server off')

  const save = () => {
    void form.save().then((saved) => { if (saved) onSaved?.(saved) })
  }
  const barProps: McpSaveBarProps = { dirty: form.dirty, saving: form.saving, save, discard: form.discard }

  const labels: Record<McpTabId, string> = {
    overview: __('Overview'),
    connections: __('Connections'),
    tools: __('Tools'),
    activity: __('Activity'),
    settings: __('Settings'),
  }

  const docs = docsUrl ?? bootstrap.docsUrl

  return (
    <HelpModeProvider mode={help}>
      <div className="aikit aikit-mcp">
        {showIntro && (
          <>
            <div className="aikit-mcp__header">
              <div className="aikit-mcp__title-row">
                <h2 className="aikit-mcp__title">
                  {server.icon ?? <McpIcon size={24} className="aikit-mcp__title-icon" />}
                  {__('MCP Server')}
                </h2>
                {state && <Badge tone={serverOn ? 'success' : 'neutral'}>{statusText}</Badge>}
              </div>
              <span className="aikit-mcp__links">
                {docs && <a href={docs} target="_blank" rel="noreferrer">{__('Documentation')}</a>}
                <a href="https://modelcontextprotocol.io/docs/getting-started/intro" target="_blank" rel="noreferrer">{__('What is MCP?')}</a>
              </span>
            </div>
            <p className="aikit-mcp__lead">
              {description ?? sprintf(__('Let AI apps such as Claude, ChatGPT, Cursor and VS Code work with %s through the Model Context Protocol (MCP). It stays off until you switch it on, and every app acts as a WordPress user you approve.'), server.name)}
            </p>
          </>
        )}

        {renderSaveBar ? renderSaveBar(barProps) : form.dirty && (
          <div className="aikit-mcp__save-bar" role="region" aria-label={__('Unsaved changes')}>
            <span>{__('You have unsaved changes.')}</span>
            <span className="aikit-mcp__save-actions">
              <Button size="sm" onClick={form.discard} disabled={form.saving}>{__('Discard')}</Button>
              <Button size="sm" variant="primary" loading={form.saving} onClick={save}>{__('Save changes')}</Button>
            </span>
          </div>
        )}
        {form.saveError && <p className="aikit-notice aikit-notice--danger">{form.saveError}</p>}

        {form.loading && !state && <div className="aikit-empty"><Spinner size="md" /></div>}
        {!form.loading && !state && (
          <p className="aikit-notice aikit-notice--danger">{form.error || __('The MCP settings are not available on this site.')}</p>
        )}

        {state && draft && (
          <McpFormProvider form={form}>
            {tabs.length > 1 && (
              <div className="aikit-mcp__tabs">
                <Tabs barOnly activeKey={active} onChange={key => goTo(key as McpTabId)} items={tabs.map(id => ({ key: id, label: labels[id] }))} />
              </div>
            )}
            <div className="aikit-mcp__body" role="tabpanel">
              {active === 'overview' && (
                <Overview
                  form={form}
                  server={{ name: server.name, slug: server.slug, endpoint: state.endpoint }}
                  clients={clients}
                  profileUrl={profileUrl}
                  goTo={goTo}
                  hasTab={id => tabs.includes(id)}
                />
              )}
              {active === 'connections' && (
                <>
                  {oauthOn && <ConnectionsList />}
                  {apiKeysOn && <ApiKeysCard />}
                  {profileUrl && (
                    <section className="aikit-card">
                      <div className="aikit-card__body">
                        <h3 className="aikit-mcp__h3">
                          {__('Application Passwords')}
                          <HelpTip label={__('Application Passwords')} text={__('Apps that send a fixed header (Claude Code, Cursor, VS Code, Codex, scripts) can also sign in with a WordPress Application Password. Each password acts as its user, works only over HTTPS, and is revoked from that user’s profile.')} />
                        </h3>
                        <HelpText className="aikit-muted aikit-mcp__block" text={__('Apps that send a fixed header (Claude Code, Cursor, VS Code, Codex, scripts) can also sign in with a WordPress Application Password. Each password acts as its user, works only over HTTPS, and is revoked from that user’s profile.')} />
                        <a href={profileUrl} target="_blank" rel="noreferrer">{__('Manage Application Passwords')}</a>
                      </div>
                    </section>
                  )}
                </>
              )}
              {active === 'tools' && (
                <ToolList
                  tools={state.tools}
                  disabled={draft.disabled_tools}
                  accessLevel={draft.access_level}
                  onAccessLevel={level => form.update({ access_level: level })}
                  onToggle={(name, enabled) => {
                    const next = new Set(draft.disabled_tools)
                    if (enabled) next.delete(name)
                    else next.add(name)
                    form.update({ disabled_tools: [...next] })
                  }}
                />
              )}
              {active === 'activity' && <ActivityLog />}
              {active === 'settings' && <McpSettingsForm />}
            </div>
          </McpFormProvider>
        )}
      </div>
    </HelpModeProvider>
  )
}

interface OverviewProps {
  form: McpForm
  server: { name: string, slug: string, endpoint: string }
  clients?: ClientDefinition[]
  profileUrl?: string
  goTo: (id: McpTabId) => void
  hasTab: (id: McpTabId) => boolean
}

function Overview({ form, server, clients, profileUrl, goTo, hasTab }: OverviewProps) {
  const { __ } = useI18n()
  const auditOn = useFeature('audit')
  const { Button, CopyField, Switch } = useSlots()
  const state = form.state as McpState
  const draft = form.draft as NonNullable<McpForm['draft']>
  const forcedOff = !!state.forced_off?.mcp
  // Connecting an app only makes sense with the server on (the switch shows it at once, before saving).
  const draftOn = !!draft.enabled && !forcedOff
  const savedOn = !!state.settings.enabled && !forcedOff
  const oauthUnsaved = !state.oauth?.enabled && !!draft.oauth && !state.settings.oauth

  return (
    <div className="aikit-mcp__overview">
      <section className="aikit-card">
        <div className="aikit-card__body">
          <div className="aikit-row">
            <SettingText
              label={__('MCP server')}
              headingClass="aikit-mcp__h3"
              help={__('Lets AI apps you approve use the tools allowed on the Tools tab. Off: the endpoint does not exist.')}
              note={forcedOff ? __('Turned off for this site by a constant or filter.') : undefined}
            />
            <Switch checked={draftOn} disabled={forcedOff} aria-label={__('MCP server')} onChange={checked => form.update({ enabled: checked })} />
          </div>
          {draftOn && (
            <div className="aikit-row">
              <SettingText label={__('Server URL')} labelClass="aikit-mcp__label" help={__('Paste this into your AI app, or follow the steps below.')} />
              <div className="aikit-mcp__url">
                <CopyField value={state.endpoint} label={__('Server URL')} />
              </div>
            </div>
          )}
        </div>
      </section>

      {draftOn && (
        <ConnectAppGuide
          server={server}
          clients={clients}
          profileUrl={profileUrl}
          serverOn={savedOn}
          oauthUnsaved={oauthUnsaved}
          goToConnections={hasTab('connections') ? () => goTo('connections') : undefined}
        />
      )}

      {auditOn && (
        <section className="aikit-card">
          <div className="aikit-card__head">
            <h3>{__('Recent activity')}</h3>
            {hasTab('activity') && <Button variant="ghost" size="sm" onClick={() => goTo('activity')}>{__('View all')}</Button>}
          </div>
          <ActivityLog compact />
        </section>
      )}

      <DiagnosticsList requirements={state.requirements} />
    </div>
  )
}
