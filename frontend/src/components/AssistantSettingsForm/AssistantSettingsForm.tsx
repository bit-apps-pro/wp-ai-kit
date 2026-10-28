import { useEffect, useId, useState, type ReactNode } from 'react'
import { useBootstrap, useFeature, useI18n } from '../../core/context'
import { useActivity, useAiSettings } from '../../core/hooks/useSettings'
import type { I18n } from '../../core/i18n'
import type { BadgeProps } from '../../core/slots'
import type { AccessLevel, AiSettings, AssistantState, AuditEvent, Provider } from '../../core/types'
import SaveBar from '../SaveBar'
import { HelpModeProvider, HelpText, HelpTip, type HelpMode, SettingText } from '../SettingHelp'
import { useSlots } from '../slots/useSlots'

type Translate = I18n['__']

const PROVIDERS: { value: Provider, label: string }[] = [
  { value: 'claude', label: 'Anthropic Claude' },
  { value: 'openai', label: 'OpenAI' },
  { value: 'gemini', label: 'Google Gemini' },
  { value: 'openai_compatible', label: 'OpenAI-compatible (Mistral, Groq, OpenRouter…)' },
]

/** The three access levels with their labels and help, translated. */
export function accessLevels(__: Translate): { value: AccessLevel, label: string, help: string }[] {
  return [
    { value: 'read', label: __('Read only'), help: __('Reads content, settings and statistics. Changes nothing.') },
    { value: 'write', label: __('Read and write'), help: __('Also makes changes that can be undone, like renaming.') },
    { value: 'destructive', label: __('Read, write and delete'), help: __('Also actions that cannot be undone, each confirmed first.') },
  ]
}

/** How a tool call ended, as a label. */
export function statusLabel(status: string, __: Translate): string {
  const labels: Record<string, string> = {
    ok: __('OK'),
    denied: __('Denied'),
    error: __('Error'),
    rate_limited: __('Rate limited'),
    confirmation_required: __('Needs confirmation'),
  }
  return labels[status] || status
}

const STATUS_TONES: Record<string, BadgeProps['tone']> = {
  ok: 'success',
  denied: 'danger',
  error: 'danger',
  rate_limited: 'warning',
  confirmation_required: 'info',
}

export function statusTone(status: string): BadgeProps['tone'] {
  return STATUS_TONES[status] || 'neutral'
}

/** The kit stores times in UTC (`YYYY-MM-DD HH:MM:SS`); show them in the browser's time zone. */
export function utcToDate(value: string | null | undefined): Date | null {
  if (!value) return null
  const date = new Date(/[Zz]|[+-]\d\d:?\d\d$/.test(value) ? value : `${String(value).replace(' ', 'T')}Z`)
  return Number.isNaN(date.getTime()) ? null : date
}

export function timeAgo(value: string | null | undefined, { __, sprintf }: I18n, now: number = Date.now()): string {
  const date = utcToDate(value)
  if (!date) return ''
  const seconds = Math.max(0, Math.round((now - date.getTime()) / 1000))
  if (seconds < 60) return __('just now')
  if (seconds < 3600) return sprintf(__('%d min ago'), Math.floor(seconds / 60))
  if (seconds < 86400) return sprintf(__('%d h ago'), Math.floor(seconds / 3600))
  return date.toLocaleDateString()
}

/** The site's URL from the REST base (`https://site/wp-json/...` or a plain-permalink `?rest_route=`). */
export function siteUrlFromRestBase(restBase: string | null | undefined): string {
  if (!restBase) return ''
  const match = restBase.match(/^(.*?)\/(?:wp-json(?:\/|$)|index\.php\?rest_route=|\?rest_route=)/)
  if (match) return match[1]
  try {
    return new URL(restBase).origin
  } catch {
    return ''
  }
}

export interface AssistantSettingsFormProps {
  /** The page title row (icon, name, On/Off badge, documentation link) and lead text. */
  showIntro?: boolean
  /** The intro's lead paragraph; default a generic one. */
  description?: ReactNode
  /** What each setting does: behind an info button (`tooltip`, default) or printed under its label (`inline`). */
  help?: HelpMode
  showEngineCard?: boolean
  showAccessCard?: boolean
  showLimitsCard?: boolean
  showRecentActivity?: boolean
  /** WordPress Settings → Connectors; defaults to `<site>/wp-admin/options-connectors.php`. */
  connectorsUrl?: string
  /** Host-specific rows, appended to the "Privacy and limits" card. */
  extraFields?: (ctx: { draft: AiSettings, update: (patch: Partial<AiSettings>) => void, state: AssistantState }) => ReactNode
  onSaved?: (state: AssistantState) => void
  /** "View all" in the Recent activity card; the link is hidden without it. */
  onViewAllActivity?: () => void
  /** Render the unsaved-changes bar (default). A host with its own bar passes false. */
  saveBar?: boolean
  className?: string
}

/**
 * The AI assistant's settings: on/off, which AI engine answers, what it may do,
 * personal data and limits, and its latest tool calls. Provider keys are
 * write-only: a typed key goes with the next save and only a hint comes back.
 */
export default function AssistantSettingsForm({
  showIntro = false,
  description,
  help,
  showEngineCard = true,
  showAccessCard = true,
  showLimitsCard = true,
  showRecentActivity = true,
  connectorsUrl,
  extraFields,
  onSaved,
  onViewAllActivity,
  saveBar = true,
  className,
}: AssistantSettingsFormProps) {
  const i18n = useI18n()
  const { __, sprintf } = i18n
  const bootstrap = useBootstrap()
  const { Badge, Button, Icon, Spinner, Switch } = useSlots()
  const form = useAiSettings()
  const { state, draft, update } = form
  const auditOn = useFeature('audit')
  const uid = useId()
  const ids = {
    enabled: `${uid}-enabled`,
    provider: `${uid}-provider`,
    model: `${uid}-model`,
    baseUrl: `${uid}-base-url`,
    models: `${uid}-models`,
    key: `${uid}-key`,
    turns: `${uid}-turns`,
    daily: `${uid}-daily`,
    history: `${uid}-history`,
  }

  // A new key being typed; write-only, so it lives here and not in the draft.
  const [apiKey, setApiKey] = useState('')
  const [savedAt, setSavedAt] = useState(0)

  // A fresh server state (first load, save, reload) clears what was typed.
  useEffect(() => { setApiKey('') }, [state])

  useEffect(() => {
    if (!savedAt) return undefined
    const timer = window.setTimeout(() => setSavedAt(0), 3000)
    return () => window.clearTimeout(timer)
  }, [savedAt])

  const dirty = form.dirty || apiKey.trim() !== ''

  const save = async (extra: { clear_api_key?: boolean } = {}) => {
    const key = apiKey.trim()
    const next = await form.save({ ...(key ? { api_key: key } : {}), ...extra })
    if (next) {
      setSavedAt(Date.now())
      onSaved?.(next)
    }
  }

  const discard = () => {
    form.discard()
    setApiKey('')
  }

  const savedOn = !!state && !!state.settings.enabled && !state.forced_off
  // The sections below only matter with the assistant on (the switch shows it at once, before saving).
  const on = !!state && !!draft && !!draft.enabled && !state.forced_off

  const connectors = connectorsUrl || `${siteUrlFromRestBase(bootstrap.restBase)}/wp-admin/options-connectors.php`

  const bar = saveBar && <SaveBar dirty={dirty} saving={form.saving} onSave={() => { void save() }} onDiscard={discard} />

  return (
    <HelpModeProvider mode={help}>
      <div className={['aikit', 'aikit-settings', className || ''].join(' ').trim()}>
        {showIntro && (
          <>
            <div className="aikit-settings__header">
              <div className="aikit-settings__title-row">
                <h2 className="aikit-settings__title">
                  <Icon name="spark" size={24} className="aikit-settings__title-icon" />
                  {__('AI Assistant')}
                </h2>
                {state && <Badge tone={savedOn ? 'success' : 'neutral'}>{savedOn ? __('On') : __('Off')}</Badge>}
              </div>
              {bootstrap.docsUrl && (
                <span className="aikit-settings__links">
                  <a href={bootstrap.docsUrl} target="_blank" rel="noreferrer">{__('Documentation')}</a>
                </span>
              )}
            </div>
            {bar}
            <p className="aikit-settings__lead">{description ?? __('An “Ask AI” panel in the admin. It answers questions about your content and makes the changes you ask for; anything that cannot be undone asks you first.')}</p>
          </>
        )}
        {!showIntro && bar}

        <div className="aikit-settings__body">
          {form.saveError && <p className="aikit-notice aikit-notice--danger" role="alert">{form.saveError}</p>}
          {savedAt > 0 && <p className="aikit-notice aikit-notice--success" role="status">{__('Settings saved.')}</p>}

          {!state || !draft ? (
            form.error ? (
              <p className="aikit-notice aikit-notice--danger" role="alert">
                {form.error}
                {' '}
                <Button variant="ghost" size="sm" onClick={() => { void form.reload() }}>{__('Retry')}</Button>
              </p>
            ) : (
              <div className="aikit-settings__loading"><Spinner size="md" /></div>
            )
          ) : (
            <>
              <section className="aikit-card aikit-settings__card">
                <div className="aikit-card__body">
                  <div className="aikit-settings__row">
                    <SettingText
                      label={__('AI assistant')}
                      htmlFor={ids.enabled}
                      headingClass="aikit-settings__h3"
                      help={__('Adds an “Ask AI” panel to the admin screens. It acts as the signed-in user, and every call it makes is logged.')}
                      note={state.forced_off ? __('Turned off for this site by a constant or filter.') : undefined}
                    />
                    <Switch id={ids.enabled} checked={on} disabled={state.forced_off} aria-label={__('AI assistant')} onChange={enabled => update({ enabled })} />
                  </div>
                  {on && (
                    <p className={`aikit-notice ${state.engine ? 'aikit-notice--info' : 'aikit-notice--warn'}`}>
                      {state.engine ? sprintf(__('Answers come from: %s'), state.engine.label) : (state.engine_reason || __('No AI engine is set up yet.'))}
                    </p>
                  )}
                </div>
              </section>

              {on && showEngineCard && (
                <EngineCard
                  state={state}
                  draft={draft}
                  update={update}
                  ids={ids}
                  apiKey={apiKey}
                  setApiKey={setApiKey}
                  connectorsUrl={connectors}
                  onRemoveKey={() => { void save({ clear_api_key: true }) }}
                  saving={form.saving}
                />
              )}

              {on && showAccessCard && (
                <section className="aikit-card aikit-settings__card">
                  <div className="aikit-card__body">
                    <h3 className="aikit-settings__h3">
                      {__('What the assistant may do')}
                      <HelpTip label={__('What the assistant may do')} text={__('Actions that cannot be undone always ask you in the chat first.')} />
                    </h3>
                    <div role="radiogroup" aria-label={__('Assistant access level')} className="aikit-choices">
                      {accessLevels(__).map(level => (
                        <Choice key={level.value} name={`${uid}-access`} value={level.value} current={draft.access_level} onChange={value => update({ access_level: value as AccessLevel })} label={level.label} help={level.help} />
                      ))}
                    </div>
                    <HelpText className="aikit-settings__hint" text={__('Actions that cannot be undone always ask you in the chat first.')} />
                  </div>
                </section>
              )}

              {on && showLimitsCard && (
                <section className="aikit-card aikit-settings__card">
                  <div className="aikit-card__body">
                    <h3 className="aikit-settings__h3">{__('Privacy and limits')}</h3>
                    <div className="aikit-settings__row">
                      <SettingText
                        label={__('Send personal data to the AI provider')}
                        note={__('Off: personal data is masked before the provider sees it.')}
                        help={__('Email addresses, phone numbers, names, addresses and files in your content are masked while this is off. Messages and tool results are sent to the provider either way.')}
                      />
                      <Switch checked={!!draft.allow_personal_data} aria-label={__('Send personal data to the AI provider')} onChange={allow_personal_data => update({ allow_personal_data })} />
                    </div>
                    <NumberRow id={ids.turns} label={__('Steps per answer')} help={__('How many times the assistant may call the AI model for one message (1–10).')} value={draft.max_turns} min={1} max={10} onChange={max_turns => update({ max_turns })} />
                    <NumberRow id={ids.daily} label={__('Messages per user per day')} help={__('Keeps provider costs predictable.')} value={draft.daily_messages} min={1} max={2000} onChange={daily_messages => update({ daily_messages })} />
                    <NumberRow id={ids.history} label={__('Keep chat history (days)')} help={__('Conversations untouched for longer are deleted (1–365). Chats can quote your content.')} value={draft.history_days ?? 30} min={1} max={365} onChange={history_days => update({ history_days })} />
                    {extraFields?.({ draft, update, state })}
                  </div>
                </section>
              )}

              {showRecentActivity && auditOn && <RecentActivity onViewAll={onViewAllActivity} />}
            </>
          )}
        </div>
      </div>
    </HelpModeProvider>
  )
}

interface EngineCardProps {
  state: AssistantState
  draft: AiSettings
  update: (patch: Partial<AiSettings>) => void
  ids: { provider: string, model: string, baseUrl: string, models: string, key: string }
  apiKey: string
  setApiKey: (value: string) => void
  connectorsUrl: string
  onRemoveKey: () => void
  saving: boolean
}

function EngineCard({ state, draft, update, ids, apiKey, setApiKey, connectorsUrl, onRemoveKey, saving }: EngineCardProps) {
  const { __, sprintf } = useI18n()
  const { Button, Icon, Select, TextInput } = useSlots()
  const uid = useId()
  const key = state.keys?.[draft.provider] || { set: false, hint: '' }
  const suggestions = state.suggested_models?.[draft.provider] || []
  const usesOwnKey = draft.engine === 'provider' || (draft.engine === 'auto' && !state.wordpress_ai)
  const setEngine = (value: string) => update({ engine: value as AiSettings['engine'] })

  return (
    <section className="aikit-card aikit-settings__card">
      <div className="aikit-card__body">
        <h3 className="aikit-settings__h3">{__('AI engine')}</h3>
        <div role="radiogroup" aria-label={__('AI engine')} className="aikit-choices">
          <Choice name={`${uid}-engine`} value="auto" current={draft.engine} onChange={setEngine} label={__('Automatic (recommended)')} help={__('WordPress AI if it is set up, otherwise your API key.')} />
          <Choice
            name={`${uid}-engine`}
            value="wordpress"
            current={draft.engine}
            onChange={setEngine}
            label={__('WordPress AI (Settings → Connectors)')}
            help={state.wordpress_ai_api
              ? (state.wordpress_ai ? __('Set up on this site. No key is stored here.') : __('No provider is set up yet.'))
              : __('Needs WordPress 7.0 or newer.')}
          />
          <Choice name={`${uid}-engine`} value="provider" current={draft.engine} onChange={setEngine} label={__('My own API key')} help={__('Calls the provider directly with your key.')} />
        </div>
        {state.wordpress_ai_api && !state.wordpress_ai && (
          <a className="aikit-settings__link" href={connectorsUrl} target="_blank" rel="noreferrer">
            {__('Open WordPress Settings → Connectors')}
            <Icon name="external" size={12} />
          </a>
        )}

        {usesOwnKey && (
          <div className="aikit-settings__rows">
            <div className="aikit-settings__row">
              <SettingText label={__('Provider')} htmlFor={ids.provider} />
              <div className="aikit-settings__control">
                <Select id={ids.provider} value={draft.provider} options={PROVIDERS} onChange={value => update({ provider: value as Provider, model: '' })} />
              </div>
            </div>
            {draft.provider === 'openai_compatible' && (
              <div className="aikit-settings__row">
                <SettingText
                  label={__('Base URL')}
                  htmlFor={ids.baseUrl}
                  note={__('Chats and tool results are sent to this address.')}
                  help={__('The service\'s API address up to /chat/completions, such as https://api.mistral.ai/v1. https only; local and private addresses are refused. Changing it removes the saved key.')}
                />
                <div className="aikit-settings__control">
                  <TextInput id={ids.baseUrl} type="url" value={draft.base_url || ''} placeholder="https://api.example.com/v1" onChange={value => update({ base_url: value })} />
                </div>
              </div>
            )}
            <div className="aikit-settings__row">
              <SettingText label={__('Model')} htmlFor={ids.model} help={draft.provider === 'openai_compatible' ? __('The model name as the service writes it, such as mistral-large-latest. Required.') : __('Leave empty for the first suggestion.')} />
              <div className="aikit-settings__control">
                <TextInput id={ids.model} list={ids.models} value={draft.model} placeholder={suggestions[0] || ''} onChange={model => update({ model })} />
                <datalist id={ids.models}>
                  {suggestions.map(model => <option key={model} value={model} />)}
                </datalist>
              </div>
            </div>
            <div className="aikit-settings__row">
              <SettingText
                label={__('API key')}
                htmlFor={ids.key}
                note={[
                  key.set ? sprintf(__('Saved, ends in %s.'), key.hint) : '',
                  state.salts_in_config ? '' : __('Define the salts in wp-config.php to protect it better.'),
                ].filter(Boolean).join(' ') || undefined}
                help={[
                  key.set ? __('Type a new one to replace it.') : '',
                  __('Stored encrypted. It is never shown again.'),
                  state.salts_in_config ? '' : __('The key is encrypted with the security keys and salts; salts kept in the database protect it less than ones in wp-config.php.'),
                ].filter(Boolean).join(' ')}
              />
              <span className="aikit-settings__key-group">
                {key.set && (
                  <Button variant="ghost" icon={<Icon name="trash" />} title={__('Remove the saved key')} aria-label={__('Remove the saved key')} disabled={saving} onClick={onRemoveKey} />
                )}
                <div className="aikit-settings__control">
                  <TextInput id={ids.key} type="password" value={apiKey} placeholder={key.set ? key.hint : ''} onChange={setApiKey} />
                </div>
              </span>
            </div>
          </div>
        )}
      </div>
    </section>
  )
}

interface NumberRowProps {
  id: string
  label: string
  help: string
  value: number
  min: number
  max: number
  onChange: (value: number) => void
}

function NumberRow({ id, label, help, value, min, max, onChange }: NumberRowProps) {
  const { TextInput } = useSlots()
  return (
    <div className="aikit-settings__row">
      <SettingText label={label} htmlFor={id} help={help} />
      <div className="aikit-settings__number">
        <TextInput id={id} type="number" min={min} max={max} value={String(value)} onChange={next => onChange(Number(next))} />
      </div>
    </div>
  )
}

interface ChoiceProps {
  name: string
  value: string
  current: string
  onChange: (value: string) => void
  label: string
  help: string
}

/** A radio drawn as a card; the chosen one is outlined. */
function Choice({ name, value, current, onChange, label, help }: ChoiceProps) {
  const active = current === value
  return (
    <label className={['aikit-choice', active ? 'aikit-choice--active' : ''].join(' ').trim()}>
      <input type="radio" name={name} value={value} checked={active} onChange={() => onChange(value)} />
      <span className="aikit-choice__text">
        <strong>{label}</strong>
        <small>{help}</small>
      </span>
    </label>
  )
}

/** The assistant's last few tool calls, from the shared activity log. */
function RecentActivity({ onViewAll }: { onViewAll?: () => void }) {
  const i18n = useI18n()
  const { __ } = i18n
  const { Badge, Button, Spinner } = useSlots()
  const activity = useActivity({ channel: 'assistant', per_page: 5 })
  const { status, query, setQuery } = activity

  // The log is shared with the MCP screen; narrow it back to this channel when another query left it elsewhere.
  useEffect(() => {
    if (status !== 'idle' && (query.channel !== 'assistant' || query.per_page !== 5)) setQuery({ channel: 'assistant', per_page: 5 })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const rows: AuditEvent[] = (activity.data?.rows || []).slice(0, 5)
  const pending = status === 'idle' || (status === 'loading' && !activity.data)

  return (
    <section className="aikit-card aikit-settings__card">
      <div className="aikit-card__head">
        <div>
          <h3>
            {__('Recent activity')}
            <HelpTip label={__('Recent activity')} text={__('What the assistant did on this site. Arguments and results are never stored.')} />
          </h3>
          <HelpText text={__('What the assistant did on this site. Arguments and results are never stored.')} />
        </div>
        {onViewAll && <Button variant="ghost" size="sm" onClick={onViewAll}>{__('View all')}</Button>}
      </div>
      {pending && <div className="aikit-empty"><Spinner /></div>}
      {!pending && status === 'error' && <p className="aikit-empty">{activity.error}</p>}
      {!pending && status !== 'error' && (rows.length ? (
        <ul className="aikit-activity-list">
          {rows.map(event => (
            <li key={event.id || `${event.created_at}-${event.tool}`}>
              <code>{event.tool}</code>
              <Badge tone={statusTone(event.status)}>{statusLabel(event.status, __)}</Badge>
              <small className="aikit-activity-list__when">{timeAgo(event.created_at, i18n)}</small>
            </li>
          ))}
        </ul>
      ) : <p className="aikit-empty">{__('No calls yet.')}</p>)}
    </section>
  )
}
