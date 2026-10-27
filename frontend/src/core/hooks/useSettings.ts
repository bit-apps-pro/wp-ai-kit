import { useCallback, useEffect, useMemo, useState } from 'react'
import { useAiKit } from '../context'
import { errorMessage } from '../store'
import type { ActivityQuery, AiSettings, AiSettingsInput, AssistantState, McpSettings, McpState } from '../types'
import { useStoreState } from './useStoreState'

/**
 * A settings form over a resource: the saved state, a draft the form edits,
 * whether it differs, and save/discard. Shared by the assistant and MCP screens.
 */
function useDraftForm<TState, TSettings extends object, TInput>(
  resource: { status: string, data: TState | null, error: string | null },
  pick: (state: TState) => TSettings,
  load: () => Promise<void>,
  save: (input: TInput) => Promise<TState>,
  fallback: string,
) {
  const [draft, setDraft] = useState<TSettings | null>(null)
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const saved = resource.data ? pick(resource.data) : null

  useEffect(() => {
    if (resource.status === 'idle') void load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [resource.status])

  // A fresh server state resets the draft (first load, or after a save).
  useEffect(() => { setDraft(saved ? { ...saved } : null) }, [resource.data]) // eslint-disable-line react-hooks/exhaustive-deps

  const dirty = useMemo(() => !!saved && !!draft && JSON.stringify(saved) !== JSON.stringify(draft), [saved, draft])

  const update = useCallback((patch: Partial<TSettings>) => setDraft(prev => (prev ? { ...prev, ...patch } : prev)), [])

  const submit = useCallback(async (extra: Partial<TInput> = {}): Promise<TState | null> => {
    if (!draft) return null
    setSaving(true)
    setSaveError(null)
    try {
      return await save({ ...draft, ...extra } as unknown as TInput)
    } catch (error) {
      setSaveError(errorMessage(error, fallback))
      return null
    } finally {
      setSaving(false)
    }
  }, [draft, save, fallback])

  return {
    state: resource.data,
    loading: resource.status === 'loading' || resource.status === 'idle',
    error: resource.error,
    draft,
    update,
    dirty,
    discard: useCallback(() => setDraft(saved ? { ...saved } : null), [saved]),
    save: submit,
    saving,
    saveError,
    reload: load,
  }
}

/** The AI Assistant settings screen. */
export function useAiSettings() {
  const { store } = useAiKit()
  const resource = useStoreState(state => state.assistantSettings)
  return useDraftForm<AssistantState, AiSettings, AiSettingsInput>(
    resource,
    state => state.settings,
    useCallback(() => store.loadAssistantSettings(), [store]),
    useCallback((input: AiSettingsInput) => store.saveAssistantSettings(input), [store]),
    'Could not save the assistant settings.',
  )
}

/** The MCP server settings screen. */
export function useMcpSettings() {
  const { store } = useAiKit()
  const resource = useStoreState(state => state.mcp)
  const form = useDraftForm<McpState, McpSettings, Partial<McpSettings>>(
    resource,
    state => state.settings,
    useCallback(() => store.loadMcp(), [store]),
    useCallback((input: Partial<McpSettings>) => store.saveMcp(input), [store]),
    'Could not save the MCP settings.',
  )
  return {
    ...form,
    createApiKey: useCallback((input: { name: string, access: string, expires_days?: number | null, unattended?: boolean }) => store.createApiKey(input), [store]),
    revokeApiKey: useCallback((id: string) => store.revokeApiKey(id), [store]),
    revokeConnection: useCallback((id: string | 'all') => store.revokeConnection(id), [store]),
  }
}

/** The MCP screen's state without the form (the Overview's connect guide, say). */
export function useMcpState() {
  const { store } = useAiKit()
  const resource = useStoreState(state => state.mcp)
  useEffect(() => {
    if (resource.status === 'idle') void store.loadMcp()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [resource.status])
  return { ...resource, reload: useCallback(() => store.loadMcp(), [store]) }
}

/** The activity log: one page with filters. */
export function useActivity(initial: ActivityQuery = {}) {
  const { store, bootstrap } = useAiKit()
  const activity = useStoreState(state => state.activity)
  useEffect(() => {
    if (activity.status === 'idle') void store.loadActivity({ page: 1, per_page: 25, ...initial })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activity.status])
  return {
    ...activity,
    loading: activity.status === 'loading',
    canClear: bootstrap.canManage,
    setQuery: useCallback((query: ActivityQuery) => store.loadActivity({ ...query, page: query.page ?? 1 }), [store]),
    refresh: useCallback(() => store.loadActivity(), [store]),
    clear: useCallback(() => store.clearActivity(), [store]),
  }
}

/** The requirement checks (Diagnostics), from the MCP state. */
export function useDiagnostics() {
  const { data, status } = useMcpState()
  const requirements = data?.requirements || []
  return {
    requirements,
    loading: status === 'loading' || status === 'idle',
    failing: requirements.filter(check => check.status === 'fail'),
    warnings: requirements.filter(check => check.status === 'warn'),
  }
}
