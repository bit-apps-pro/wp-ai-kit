import { useEffect, useMemo, useRef, useState } from 'react'
import { useAiKit, useFeature, useI18n } from '../../core/context'
import { useMcpState } from '../../core/hooks/useSettings'
import type { ActivityPage, AuditEvent } from '../../core/types'
import { useSlots } from '../slots/useSlots'
import { utcNow } from '../McpSettings/mcpUtils'
import { clientById, clients as defaultClients, mergeClients, type ClientDefinition, type ClientFlow, type ServerInfo } from './clients'

const POLL_MS = 4000
const POLL_LIMIT_MS = 5 * 60 * 1000

export interface ConnectAppGuideProps {
  server: ServerInfo
  /** Which flow to show; the default follows the saved OAuth setting. `both` lets the user pick in step 2. */
  auth?: 'oauth' | 'basic' | 'both'
  /** Extra or replacement apps, merged onto the defaults by id. */
  clients?: ClientDefinition[]
  /** `stepper` is the three-step card; `tabs` puts the apps in a tab bar with the guide below. */
  layout?: 'stepper' | 'tabs'
  /** The Application Passwords section of the user's profile. */
  profileUrl?: string
  /** The first call after the user said they added the app. */
  onConnected?: (client: string, tool: string) => void
  /** Whether the saved settings have the server on; default from the MCP state. */
  serverOn?: boolean
  /** OAuth was switched on in the form but not saved yet. */
  oauthUnsaved?: boolean
  /** Shown when an app needs OAuth and it is off: a way to the switch. */
  goToConnections?: () => void
}

/**
 * "Connect your AI app": pick the app, add the site to it (one-click link,
 * manual steps, snippet), then wait for its first call to confirm it works.
 */
export default function ConnectAppGuide({ server, auth, clients: extraClients, layout = 'stepper', profileUrl, onConnected, serverOn, oauthUnsaved = false, goToConnections }: ConnectAppGuideProps) {
  const { __ } = useI18n()
  const { Button, TextInput, Tabs } = useSlots()
  const { data: state } = useMcpState()
  const oauthShipped = useFeature('oauth')
  // A plugin without OAuth sign-in cannot connect the apps that only sign in that way.
  const list = useMemo(() => mergeClients(defaultClients(__), extraClients).filter(item => oauthShipped || !item.oauthOnly), [__, extraClients, oauthShipped])

  const [step, setStep] = useState(1)
  const [clientId, setClientId] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const client = clientById(list, clientId)

  const oauthOn = !!state?.oauth?.enabled
  const mode: 'oauth' | 'basic' | 'both' = auth || (oauthOn ? 'oauth' : 'basic')
  const isOn = serverOn ?? (!!state?.settings?.enabled && !state?.forced_off?.mcp)

  const choose = (id: string) => {
    setClientId(id)
    setStep(2)
  }
  const reset = () => {
    setStep(1)
    setClientId(null)
  }

  const effort = (item: ClientDefinition) => {
    if (item.oauthOnly && mode === 'basic') return __('Needs OAuth sign-in')
    return (mode === 'basic' && item.basicEffort) || item.effort
  }

  const visible = list.filter(item => item.name.toLowerCase().includes(search.trim().toLowerCase()))
  const steps = [__('Choose app'), __('Add site'), __('Confirm')]

  const guide = client && (
    <GuideStep client={client} server={server} mode={mode} oauthUnsaved={oauthUnsaved} serverOn={isOn} profileUrl={profileUrl} goToConnections={oauthShipped ? goToConnections : undefined} />
  )

  if (layout === 'tabs') {
    const active = client || list[0]
    return (
      <section className="aikit aikit-card aikit-connect aikit-connect--tabs">
        <div className="aikit-card__head">
          <div>
            <h3>{__('Connect your AI app')}</h3>
            <small>{__('Pick the app you use')}</small>
          </div>
        </div>
        <div className="aikit-card__body">
          <Tabs
            barOnly
            variant="segment"
            activeKey={active.id}
            onChange={(key) => { setClientId(key); setStep(2) }}
            items={list.map(item => ({ key: item.id, label: <span className="aikit-connect__tab"><item.Icon size={14} />{item.name}</span> }))}
          />
          <div className="aikit-connect__tab-body">
            {step === 3 && client
              ? <ConfirmStep client={client} onConnected={onConnected} />
              : <GuideStep client={active} server={server} mode={mode} oauthUnsaved={oauthUnsaved} serverOn={isOn} profileUrl={profileUrl} goToConnections={oauthShipped ? goToConnections : undefined} />}
            <div className="aikit-connect__actions">
              {step !== 3 && <Button variant="primary" size="sm" disabled={!isOn} onClick={() => { setClientId(active.id); setStep(3) }}>{__('I added it')}</Button>}
              {step === 3 && <Button size="sm" onClick={() => setStep(2)}>{__('Back')}</Button>}
            </div>
          </div>
        </div>
      </section>
    )
  }

  return (
    <section className="aikit aikit-card aikit-connect">
      <div className="aikit-card__head aikit-connect__head">
        <div>
          <h3>{__('Connect your AI app')}</h3>
          <small className="aikit-connect__chosen">
            {client && <client.Icon size={14} />}
            {client ? client.name : __('Pick the app you use')}
          </small>
        </div>
        <ol className="aikit-connect__stepper" aria-label={__('Steps')}>
          {steps.map((label, index) => (
            <li key={label} className={['aikit-connect__step', step === index + 1 ? 'aikit-connect__step--active' : ''].join(' ').trim()} aria-current={step === index + 1 ? 'step' : undefined}>
              <span className="aikit-connect__step-num">{index + 1}</span>
              {label}
            </li>
          ))}
        </ol>
      </div>
      <div className="aikit-card__body">
        {step === 1 && (
          <>
            <div className="aikit-connect__search">
              <strong>{__('Which app do you want to connect?')}</strong>
              <TextInput type="search" value={search} onChange={setSearch} placeholder={__('Find an app')} aria-label={__('Find an app')} className="aikit-connect__search-input" />
            </div>
            {visible.length === 0 && <p className="aikit-empty">{__('No app matches.')}</p>}
            <div className="aikit-connect__tiles">
              {visible.map(item => (
                <Button key={item.id} variant="ghost" className="aikit-connect__tile" onClick={() => choose(item.id)}>
                  <span className="aikit-connect__tile-logo"><item.Icon size={20} /></span>
                  <span className="aikit-connect__tile-text">
                    <strong>{item.name}</strong>
                    <small>{effort(item)}</small>
                  </span>
                </Button>
              ))}
            </div>
          </>
        )}

        {step === 2 && guide}
        {step === 3 && client && <ConfirmStep client={client} onConnected={onConnected} />}

        {step > 1 && (
          <div className="aikit-connect__actions">
            <Button size="sm" onClick={() => setStep(step - 1)}>{__('Back')}</Button>
            {step === 2 && <Button variant="primary" size="sm" onClick={() => setStep(3)} disabled={!isOn}>{__('I added it')}</Button>}
            {step === 3 && <Button size="sm" onClick={reset}>{__('Connect another app')}</Button>}
          </div>
        )}
      </div>
    </section>
  )
}

interface GuideStepProps {
  client: ClientDefinition
  server: ServerInfo
  mode: 'oauth' | 'basic' | 'both'
  oauthUnsaved: boolean
  serverOn: boolean
  profileUrl?: string
  goToConnections?: () => void
}

/** Step 2: the one-click link when the app has one, the manual steps, the URL and the snippet. */
function GuideStep({ client, server, mode, oauthUnsaved, serverOn, profileUrl, goToConnections }: GuideStepProps) {
  const { __, sprintf } = useI18n()
  const { Button, CopyField, Tabs } = useSlots()
  const [pick, setPick] = useState<'oauth' | 'basic'>('oauth')

  if (!serverOn) {
    return <p className="aikit-notice aikit-notice--warn">{__('Switch the MCP server on and save first; until then this site has no MCP endpoint.')}</p>
  }

  if (client.oauthOnly && mode === 'basic' && oauthUnsaved) {
    return <p className="aikit-notice aikit-notice--info">{sprintf(__('%s signs in with OAuth. Save your changes to turn on OAuth sign-in.'), client.name)}</p>
  }

  if (client.oauthOnly && mode === 'basic') {
    return (
      <div className="aikit-notice aikit-notice--warn aikit-connect__notice">
        {sprintf(__('%s signs in with OAuth, which is switched off on this site.'), client.name)}
        {goToConnections && <Button variant="ghost" size="sm" onClick={goToConnections}>{__('Turn on OAuth sign-in')}</Button>}
      </div>
    )
  }

  const canPick = mode === 'both' && !!client.basic && !client.oauthOnly
  const useBasic = mode === 'basic' || (canPick && pick === 'basic')
  const flow: ClientFlow = (useBasic ? client.basic : client.oauth) ?? client.oauth
  const deepLink = flow.deepLink ? flow.deepLink(server) : null

  return (
    <div className="aikit-connect__guide">
      {canPick && (
        <Tabs
          barOnly
          variant="segment"
          activeKey={pick}
          onChange={key => setPick(key as 'oauth' | 'basic')}
          items={[{ key: 'oauth', label: __('Sign in with OAuth') }, { key: 'basic', label: __('Application Password') }]}
        />
      )}
      {useBasic && (
        <p className="aikit-notice aikit-notice--info aikit-connect__notice">
          <span>
            {__('This app signs in with an Application Password.')}
            {' '}
            {profileUrl && <a href={profileUrl} target="_blank" rel="noreferrer">{__('Create one in Users → Profile')}</a>}
          </span>
          {oauthUnsaved && mode === 'basic' && <span>{__('Or save your changes to use OAuth sign-in.')}</span>}
          {!oauthUnsaved && mode === 'basic' && goToConnections && <Button variant="ghost" size="sm" onClick={goToConnections}>{__('Or turn on OAuth sign-in')}</Button>}
        </p>
      )}
      {deepLink && (
        <div className="aikit-connect__one-click">
          <a
            className="aikit-connect__deep-link"
            href={deepLink}
            // App links (cursor://, vscode:) stay in this tab: the app takes them, and a new tab would be left empty.
            {...(/^https?:/.test(deepLink) ? { target: '_blank', rel: 'noreferrer' } : {})}
          >
            {flow.deepLinkLabel}
          </a>
          {flow.deepLinkHint && <small>{flow.deepLinkHint}</small>}
        </div>
      )}
      {deepLink && <strong className="aikit-connect__manual">{__('Or add it manually')}</strong>}
      <ol className="aikit-connect__steps">
        {flow.steps.map(text => <li key={text}>{text}</li>)}
      </ol>
      <div className="aikit-connect__url">
        <small>{__('Server URL')}</small>
        <CopyField value={server.endpoint} label={__('Server URL')} />
      </div>
      {flow.snippet && <pre className="aikit-connect__snippet">{flow.snippet(server)}</pre>}
    </div>
  )
}

/** Step 3: waits for the first tool call after the user said they added the app. */
function ConfirmStep({ client, onConnected }: { client: ClientDefinition, onConnected?: (client: string, tool: string) => void }) {
  const { __, sprintf } = useI18n()
  const { Spinner } = useSlots()
  const { transport } = useAiKit()
  const [found, setFound] = useState<AuditEvent | null>(null)
  const [timedOut, setTimedOut] = useState(false)
  const since = useRef(utcNow())
  const notify = useRef(onConnected)
  notify.current = onConnected

  useEffect(() => {
    let cancelled = false
    let timer: number | undefined
    const started = Date.now()
    // The transport directly: the shared activity store keeps the Activity tab's own query and page.
    const poll = () => transport.request<ActivityPage>('mcp/activity', { from: since.current, per_page: 1, page: 1 }).then((page) => {
      if (cancelled) return
      const row = page?.rows?.[0] ?? null
      if (row) {
        setFound(row)
        notify.current?.(client.id, row.tool)
      } else if (Date.now() - started > POLL_LIMIT_MS) {
        setTimedOut(true)
      } else {
        timer = window.setTimeout(poll, POLL_MS)
      }
    }).catch(() => {
      if (!cancelled) timer = window.setTimeout(poll, POLL_MS)
    })
    poll()
    return () => {
      cancelled = true
      if (timer) window.clearTimeout(timer)
    }
  }, [transport, client.id])

  if (found) {
    return <p className="aikit-notice aikit-notice--success">{sprintf(__('It works: %1$s called %2$s.'), found.client_ref || client.name, found.tool)}</p>
  }

  return (
    <p className={['aikit-notice', timedOut ? 'aikit-notice--warn' : 'aikit-notice--info', 'aikit-connect__notice'].join(' ')}>
      {!timedOut && <Spinner size="sm" />}
      {timedOut
        ? __('No call arrived in five minutes. Check the steps again, or look at Diagnostics.')
        : sprintf(__('Waiting for the first call from %s… Ask it what it can do with this site.'), client.name)}
    </p>
  )
}
