import { useState } from 'react'
import { useI18n } from '../../core/context'
import { errorMessage } from '../../core/store'
import type { OAuthConnection } from '../../core/types'
import { AccessBadge } from '../McpSettings/badges'
import { StandaloneSave, useMcpForm } from '../McpSettings/McpFormContext'
import { formatUnix } from '../McpSettings/mcpUtils'
import { HelpText, HelpTip, SettingText } from '../SettingHelp'
import { useSlots } from '../slots/useSlots'

export interface ConnectionsListProps {
  onRevoked?: (id: string) => void
  /** The "Sign in with OAuth" switch and its notices above the list; default on. */
  showOAuthToggle?: boolean
}

type Pending = { all: true } | { id: string, name: string } | null

/** Apps signed in with OAuth: who they act as, what they may do, and Disconnect. */
export default function ConnectionsList({ onRevoked, showOAuthToggle = true }: ConnectionsListProps) {
  const { __ } = useI18n()
  const { Badge, Button, Modal, Switch } = useSlots()
  const form = useMcpForm()
  const { state, draft, update } = form
  const [confirm, setConfirm] = useState<Pending>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (!state || !draft) return null
  const oauth = state.oauth
  const connections = oauth?.connections || []

  const revoke = async (pending: Exclude<Pending, null>) => {
    setBusy(true)
    setError(null)
    try {
      const id = 'all' in pending ? 'all' : pending.id
      await form.revokeConnection(id)
      setConfirm(null)
      onRevoked?.(id)
    } catch (cause) {
      setError(errorMessage(cause, __('Could not disconnect the app.')))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="aikit aikit-connections">
      {showOAuthToggle && (
        <section className="aikit-card">
          <div className="aikit-card__body">
            <div className="aikit-row">
              <SettingText
                label={__('Sign in with OAuth')}
                headingClass="aikit-mcp__h3"
                help={__('Lets AI apps such as Claude, ChatGPT, Gemini, Cursor and VS Code connect by opening an approval page on this site. You choose what each app may do, and you can disconnect it here at any time.')}
              />
              <Switch
                checked={!!draft.oauth && !oauth.forced_off}
                disabled={oauth.forced_off || !oauth.pretty_permalinks}
                aria-label={__('Sign in with OAuth')}
                onChange={checked => update({ oauth: checked })}
              />
            </div>
            {oauth.forced_off && <p className="aikit-notice aikit-notice--info">{__('Turned off for this site by a constant or filter.')}</p>}
            {!oauth.pretty_permalinks && (
              <p className="aikit-notice aikit-notice--warn">
                {__('OAuth needs pretty permalinks so AI apps can find the sign-in documents. Choose any structure other than “Plain” in WordPress Settings → Permalinks.')}
              </p>
            )}
            {!draft.enabled && <p className="aikit-notice aikit-notice--info">{__('OAuth works once the MCP server is switched on.')}</p>}
            <StandaloneSave form={form} />
          </div>
        </section>
      )}

      <section className="aikit-card">
        <div className="aikit-card__head">
          <div>
            <h3>
              {__('Connected apps')}
              <HelpTip label={__('Connected apps')} text={__('Apps signed in with OAuth. Disconnecting one revokes its access at once.')} />
            </h3>
            <HelpText text={__('Apps signed in with OAuth. Disconnecting one revokes its access at once.')} />
          </div>
          {connections.length > 0 && (
            <Button size="sm" className="aikit-mcp__quiet-danger" onClick={() => setConfirm({ all: true })}>{__('Disconnect all')}</Button>
          )}
        </div>
        {error && <p className="aikit-notice aikit-notice--danger aikit-mcp__card-notice">{error}</p>}
        {!oauth.enabled && connections.length === 0 && <p className="aikit-empty">{__('OAuth sign-in is off, so no app is connected this way.')}</p>}
        {oauth.enabled && connections.length === 0 && <p className="aikit-empty">{__('No app has connected yet. Use “Connect your AI app” to add one.')}</p>}
        {connections.length > 0 && (
          <div className="aikit-mcp-table-wrap">
            <table className="aikit-mcp-table">
              <thead>
                <tr>
                  <th>{__('App')}</th>
                  <th>{__('WordPress user')}</th>
                  <th>{__('Access')}</th>
                  <th>{__('Personal data')}</th>
                  <th>{__('Connected')}</th>
                  <th>{__('Last used')}</th>
                  <th><span className="aikit-sr">{__('Actions')}</span></th>
                </tr>
              </thead>
              <tbody>
                {connections.map((connection: OAuthConnection) => (
                  <tr key={connection.id}>
                    <td>
                      <strong>{connection.client_name || __('Unnamed app')}</strong>
                      <small className="aikit-mcp__sub">
                        {connection.client_kind === 'cimd' && connection.client_host
                          ? `${__('Published at')} ${connection.client_host}`
                          : __('Name chosen by the app (not verified)')}
                      </small>
                      <code className="aikit-mcp__code">{connection.client_id}</code>
                    </td>
                    <td>{connection.user || `#${connection.user_id}`}</td>
                    <td><AccessBadge level={connection.scope} /></td>
                    <td><Badge tone={connection.personal_data ? 'warning' : 'neutral'}>{connection.personal_data ? __('Shown') : __('Masked')}</Badge></td>
                    <td>{formatUnix(connection.created)}</td>
                    <td>{connection.last_used ? formatUnix(connection.last_used) : __('Not yet')}</td>
                    <td className="aikit-mcp-table__fit">
                      <Button size="sm" className="aikit-mcp__quiet-danger" onClick={() => setConfirm({ id: connection.id, name: connection.client_name })}>{__('Disconnect')}</Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <Modal
        open={!!confirm}
        onClose={() => setConfirm(null)}
        title={confirm && 'all' in confirm ? __('Disconnect all apps') : __('Disconnect app')}
        footer={(
          <>
            <Button onClick={() => setConfirm(null)} disabled={busy}>{__('Cancel')}</Button>
            <Button variant="danger" loading={busy} onClick={() => { if (confirm) void revoke(confirm) }}>{__('Disconnect')}</Button>
          </>
        )}
      >
        {confirm && 'all' in confirm
          ? __('Disconnect every AI app that signed in with OAuth? Each one has to be approved again to reconnect.')
          : __('Disconnect this app? It has to be approved again to reconnect.')}
      </Modal>
    </div>
  )
}
