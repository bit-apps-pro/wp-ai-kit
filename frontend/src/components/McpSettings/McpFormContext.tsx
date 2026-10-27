import { createContext, useContext, type ReactNode } from 'react'
import { useI18n } from '../../core/context'
import { useMcpSettings } from '../../core/hooks/useSettings'
import { useSlots } from '../slots/useSlots'

export type McpForm = ReturnType<typeof useMcpSettings>

const McpFormContext = createContext<McpForm | null>(null)

/**
 * `useMcpSettings()` keeps its draft in the calling component, so the screen
 * shares one instance with its cards through this provider. A card rendered on
 * its own gets its own form and a small save row.
 */
export function McpFormProvider({ form, children }: { form: McpForm, children: ReactNode }) {
  return <McpFormContext.Provider value={form}>{children}</McpFormContext.Provider>
}

export function useMcpForm(): McpForm & { standalone: boolean } {
  const shared = useContext(McpFormContext)
  // Hooks run unconditionally; the own instance is simply unused under a provider.
  const own = useMcpSettings()
  return shared ? { ...shared, standalone: false } : { ...own, standalone: true }
}

/** The Discard / Save row a card shows when it is used outside `McpSettings`. */
export function StandaloneSave({ form }: { form: McpForm & { standalone: boolean } }) {
  const { __ } = useI18n()
  const { Button } = useSlots()
  if (!form.standalone || !form.dirty) return null
  return (
    <div className="aikit-mcp__inline-save">
      {form.saveError && <span className="aikit-notice aikit-notice--danger">{form.saveError}</span>}
      <Button size="sm" onClick={form.discard} disabled={form.saving}>{__('Discard')}</Button>
      <Button size="sm" variant="primary" loading={form.saving} onClick={() => { void form.save() }}>{__('Save changes')}</Button>
    </div>
  )
}
