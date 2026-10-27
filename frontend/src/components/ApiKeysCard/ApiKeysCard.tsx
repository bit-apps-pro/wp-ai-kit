import { useState } from 'react'
import { useBootstrap, useI18n } from '../../core/context'
import { errorMessage } from '../../core/store'
import type { AccessLevel, ApiKeyRecord } from '../../core/types'
import { AccessBadge } from '../McpSettings/badges'
import { StandaloneSave, useMcpForm } from '../McpSettings/McpFormContext'
import { ACCESS_ORDER, accessLevels, formatUnix } from '../McpSettings/mcpUtils'
import { SettingText } from '../SettingHelp'
import { useSlots } from '../slots/useSlots'

export interface ApiKeysCardProps {
  /** The plain key, shown once. */
  onCreated?: (key: string) => void
}

const expiryOptions = (__: (text: string) => string): { days: number | null, label: string }[] => [
  { days: 7, label: __('7 days') },
  { days: 30, label: __('30 days') },
  { days: 90, label: __('90 days') },
  { days: 365, label: __('1 year') },
  { days: null, label: __('Never') },
]

/**
 * Long-lived keys for MCP apps and scripts that cannot open the OAuth approval
 * page. A key acts as the user who created it, capped at the access level chosen
 * here and at the site's level; it is shown once and stored as a hash.
 */
export default function ApiKeysCard({ onCreated }: ApiKeysCardProps) {
  const { __, sprintf } = useI18n()
  const { Badge, Button, Checkbox, CopyField, Modal, Select, Switch, TextInput } = useSlots()
  const form = useMcpForm()
  const { state, draft, update } = form
  // Keys that never expire or never pause for confirmation override the site's safety settings: managers only.
  const canManage = useBootstrap().canManage
  const [name, setName] = useState('')
  const [access, setAccess] = useState<AccessLevel>('read')
  const [days, setDays] = useState<string>('90')
  const [unattended, setUnattended] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [newKey, setNewKey] = useState<string | null>(null)
  const [revoking, setRevoking] = useState<ApiKeyRecord | null>(null)

  if (!state || !draft) return null
  const keys = state.api_keys?.keys || []
  const forcedOff = !!state.api_keys?.forced_off
  const ceiling = draft.access_level
  const levels = accessLevels(__).filter(level => ACCESS_ORDER[level.value] <= ACCESS_ORDER[ceiling])
  const statusLabel: Record<ApiKeyRecord['status'], string> = { active: __('Active'), expired: __('Expired'), revoked: __('Revoked') }

  const create = async () => {
    setBusy(true)
    setError(null)
    try {
      const created = await form.createApiKey({ name: name.trim(), access, expires_days: days === 'never' && canManage ? null : Number(days === 'never' ? 90 : days), unattended: canManage && unattended })
      setNewKey(created.key)
      setName('')
      onCreated?.(created.key)
    } catch (cause) {
      setError(errorMessage(cause, __('Could not create the key.')))
    } finally {
      setBusy(false)
    }
  }

  const revoke = async (key: ApiKeyRecord) => {
    setBusy(true)
    setError(null)
    try {
      await form.revokeApiKey(key.id)
      setRevoking(null)
    } catch (cause) {
      setError(errorMessage(cause, __('Could not revoke the key.')))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="aikit aikit-card aikit-keys">
      <div className="aikit-card__body">
        <div className="aikit-row">
          <SettingText
            label={__('API keys')}
            headingClass="aikit-mcp__h3"
            help={__('For scripts and AI apps that cannot open an approval page. A key acts as the user who created it, with the access chosen below. Send it as “Authorization: Bearer <key>”.')}
          />
          <Switch checked={!!draft.api_keys && !forcedOff} disabled={forcedOff} aria-label={__('API keys')} onChange={checked => update({ api_keys: checked })} />
        </div>
        {forcedOff && <p className="aikit-notice aikit-notice--info">{__('Turned off for this site by a constant or filter.')}</p>}
        {draft.api_keys && !state.api_keys?.enabled && !forcedOff && <p className="aikit-notice aikit-notice--info">{__('Save your changes to switch API keys on.')}</p>}
        <StandaloneSave form={form} />

        {state.api_keys?.enabled && (
          <div className="aikit-keys__manage">
            <div className="aikit-keys__form">
              <TextInput value={name} onChange={value => setName(value.slice(0, 100))} placeholder={__('Key name, e.g. CI deploy')} aria-label={__('Key name')} className="aikit-keys__name" />
              <Select value={access} onChange={value => setAccess(value as AccessLevel)} aria-label={__('Access')} options={levels.map(level => ({ value: level.value, label: level.label }))} />
              <Select value={days} onChange={setDays} aria-label={__('Expires after')} options={expiryOptions(__).filter(option => canManage || option.days !== null).map(option => ({ value: option.days === null ? 'never' : String(option.days), label: sprintf(__('Expires: %s'), option.label) }))} />
              {canManage && <Checkbox checked={unattended} onChange={setUnattended} label={<small>{__('Unattended (never pauses for confirmation)')}</small>} />}
              <Button variant="primary" size="sm" loading={busy} disabled={name.trim() === ''} onClick={() => { void create() }}>{__('Create key')}</Button>
            </div>
            {error && <p className="aikit-notice aikit-notice--danger">{error}</p>}

            {newKey && (
              <div className="aikit-notice aikit-notice--warn aikit-keys__reveal">
                <strong>{__('Copy this key now. It will not be shown again.')}</strong>
                <CopyField value={newKey} label={__('New API key')} />
                <Button variant="ghost" size="sm" onClick={() => setNewKey(null)}>{__('I copied it')}</Button>
              </div>
            )}

            {keys.length === 0 ? (
              <p className="aikit-empty">{__('No API keys yet.')}</p>
            ) : (
              <div className="aikit-mcp-table-wrap">
                <table className="aikit-mcp-table">
                  <thead>
                    <tr>
                      <th>{__('Name')}</th>
                      <th>{__('User')}</th>
                      <th>{__('Access')}</th>
                      <th>{__('Created')}</th>
                      <th>{__('Expires')}</th>
                      <th>{__('Last used')}</th>
                      <th>{__('Status')}</th>
                      <th><span className="aikit-sr">{__('Actions')}</span></th>
                    </tr>
                  </thead>
                  <tbody>
                    {keys.map(key => (
                      <tr key={key.id}>
                        <td>
                          <strong>{key.name}</strong>
                          <code className="aikit-mcp__code">{`${key.display}…`}</code>
                          {key.unattended && <Badge tone="warning">{__('Unattended')}</Badge>}
                        </td>
                        <td>{key.user || `#${key.user_id}`}</td>
                        <td><AccessBadge level={key.scopes[0] || 'read'} /></td>
                        <td>{formatUnix(key.created_at)}</td>
                        <td>{key.expires_at ? formatUnix(key.expires_at) : __('Never')}</td>
                        <td>{key.last_used_at ? formatUnix(key.last_used_at) : __('Not yet')}</td>
                        <td><Badge tone={key.status === 'active' ? 'success' : 'neutral'}>{statusLabel[key.status]}</Badge></td>
                        <td className="aikit-mcp-table__fit">
                          {key.status === 'active' && <Button size="sm" className="aikit-mcp__quiet-danger" onClick={() => setRevoking(key)}>{__('Revoke')}</Button>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
      </div>

      <Modal
        open={!!revoking}
        onClose={() => setRevoking(null)}
        title={__('Revoke key')}
        footer={(
          <>
            <Button onClick={() => setRevoking(null)} disabled={busy}>{__('Cancel')}</Button>
            <Button variant="danger" loading={busy} onClick={() => { if (revoking) void revoke(revoking) }}>{__('Revoke')}</Button>
          </>
        )}
      >
        {revoking ? sprintf(__('Revoke “%s”? Anything using it stops working at once.'), revoking.name) : ''}
      </Modal>
    </section>
  )
}
