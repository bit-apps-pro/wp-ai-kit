import { createContext, useContext, useEffect, useMemo, useRef, type ReactNode } from 'react'
import { createI18n, defaultI18n, type I18n } from './i18n'
import type { PartialSlotComponents, SlotComponents } from './slots'
import { createAiKitStore, type AiKitStore } from './store'
import { createRestTransport, type Transport } from './transport'
import type { AiKitBootstrap } from './types'

export const SUPPORTED_CONTRACT = '1'

export interface AiKitContextValue {
  store: AiKitStore
  bootstrap: AiKitBootstrap
  transport: Transport
  i18n: I18n
  /** The host's slot map; `useSlots()` in components fills the gaps with the defaults. */
  components: PartialSlotComponents
}

const AiKitContext = createContext<AiKitContextValue | null>(null)

export interface AiKitProviderProps {
  bootstrap: AiKitBootstrap
  /** Defaults to the kit's REST routes from `bootstrap.restBase`. */
  transport?: Transport
  /** Reuse a store built elsewhere (two panels of one plugin on one page). */
  store?: AiKitStore
  components?: PartialSlotComponents
  i18n?: { __: (text: string) => string }
  children?: ReactNode
}

function fallbackTransport(): Transport {
  return {
    request: () => Promise.reject(new Error('This plugin has no AI REST routes (PluginContext feature rest_ui) and no transport was given.')),
  }
}

/**
 * One provider per plugin: owns the store (so two Bit plugins on one admin page
 * never share state), the transport, the host's primitives and translation.
 */
export function AiKitProvider({ bootstrap, transport, store, components, i18n, children }: AiKitProviderProps) {
  const transportRef = useRef<Transport | null>(null)
  if (!transportRef.current) {
    transportRef.current = transport || (bootstrap.restBase
      ? createRestTransport({ restBase: bootstrap.restBase, nonce: bootstrap.nonce })
      : fallbackTransport())
  }
  const storeRef = useRef<AiKitStore | null>(store || null)
  if (!storeRef.current) storeRef.current = createAiKitStore(transportRef.current, bootstrap)

  useEffect(() => {
    if (bootstrap.contract && bootstrap.contract.split('.')[0] !== SUPPORTED_CONTRACT) {
      console.warn(`[wp-ai-kit-ui] This UI speaks contract ${SUPPORTED_CONTRACT}; the plugin's AI kit speaks ${bootstrap.contract}. Update one of them.`)
    }
  }, [bootstrap.contract])

  const translate = i18n?.__
  const value = useMemo<AiKitContextValue>(() => ({
    store: storeRef.current as AiKitStore,
    transport: transportRef.current as Transport,
    bootstrap,
    i18n: translate ? createI18n(translate) : defaultI18n,
    components: components || {},
  }), [bootstrap, translate, components])

  return <AiKitContext.Provider value={value}>{children}</AiKitContext.Provider>
}

export function useAiKit(): AiKitContextValue {
  const value = useContext(AiKitContext)
  if (!value) throw new Error('wp-ai-kit-ui: wrap the AI components in <AiKitProvider>.')
  return value
}

export function useI18n(): I18n {
  return useAiKit().i18n
}

/** Optional parts a plugin can leave out (PluginContext `features`). */
export type OptionalFeature = 'audit' | 'history' | 'oauth' | 'apiKeys' | 'abilities' | 'mcpAdapter'

/** Whether the plugin ships an optional part; a kit too old to say counts as yes. */
export function featureOn(bootstrap: Pick<AiKitBootstrap, 'features'>, feature: OptionalFeature): boolean {
  return bootstrap.features?.[feature] !== false
}

/** `featureOn()` for the provider's bootstrap. */
export function useFeature(feature: OptionalFeature): boolean {
  return featureOn(useAiKit().bootstrap, feature)
}

export function useBootstrap(): AiKitBootstrap {
  return useAiKit().bootstrap
}

/** The slot map with every gap filled; `defaults` come from `components/slots`. */
export function useSlotsWith(defaults: SlotComponents): SlotComponents {
  const { components } = useAiKit()
  return useMemo(() => ({ ...defaults, ...components }), [defaults, components])
}
