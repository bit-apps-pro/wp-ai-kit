import { ConfigProvider, theme as antdTheme } from 'antd'
import { useEffect, useMemo, useState, type CSSProperties, type ReactNode } from 'react'
import { antdComponents, useAntdTheme } from '../src/antd'
import {
  AssistantChat, AssistantSettingsForm, ConversationHistory, FloatingLauncher, McpSettings, Panel,
} from '../src/components'
import { AiKitProvider } from '../src/core/context'
import type { Transport } from '../src/core/transport'
import type { PendingAction } from '../src/core/types'
import { createMockTransport, sampleBootstrap, type Reply } from '../tests/mockTransport'

type Page = 'dock' | 'floating' | 'drawer' | 'page' | 'card' | 'settings' | 'mcp' | 'bleed'
type Theme = 'plain' | 'bitform' | 'antd'
type Mode = 'light' | 'dark'

const PAGES: { id: Page, label: string }[] = [
  { id: 'dock', label: 'Dock layout' },
  { id: 'floating', label: 'Floating' },
  { id: 'drawer', label: 'Drawer' },
  { id: 'page', label: 'Page' },
  { id: 'card', label: 'Embedded card' },
  { id: 'settings', label: 'AI Assistant settings' },
  { id: 'mcp', label: 'MCP page' },
  { id: 'bleed', label: 'Style bleed check' },
]

const DELETE_ACTION: PendingAction = {
  name: 'delete-item',
  title: 'Delete item #12',
  description: 'Deletes “Spring newsletter” and its 48 records for good.',
  destructive: true,
  input: { id: 12 },
}

/** The scripted model: "delete" asks first, "list" runs a tool, anything else echoes. */
function reply(message: string): Reply {
  const text = message.toLowerCase()
  if (text.includes('delete')) return { text: 'This cannot be undone, so I need your confirmation first.', pending: DELETE_ACTION }
  if (text.includes('list')) {
    return {
      text: 'You have **3 items**:\n\n- Spring newsletter (published)\n- Contact list (draft)\n- Event sign-up (published)',
      tools: [{ name: 'list-items', title: 'List items' }],
    }
  }
  return { text: `You said: ${message}` }
}

// One in-memory site for the whole playground: switching themes keeps the chats.
const mock = createMockTransport({
  reply,
  decided: approve => (approve ? { text: 'Deleted item #12.', tools: [{ name: 'delete-item', title: 'Delete item' }] } : { text: 'Cancelled; nothing changed.' }),
  latency: 500,
})
const day = 86400
const now = Math.floor(Date.now() / 1000)
mock.seed({ title: 'Weekly report summary', messages: [{ role: 'user', text: 'Summarise this week' }, { role: 'assistant', text: 'Twelve new records, two drafts published.' }], updated: now - 3600 })
mock.seed({ title: 'Rename the onboarding item', messages: [{ role: 'user', text: 'Rename it' }, { role: 'assistant', text: 'Renamed.' }], context: { pinned: true }, updated: now - 2 * day })
mock.seed({ title: 'Old cleanup', messages: [{ role: 'user', text: 'Clean up' }], context: { archived: true }, updated: now - 30 * day })
mock.audit({ tool: 'list-items' })
mock.audit({ tool: 'rename-item', status: 'denied', error_code: 'ai_forbidden' })
mock.audit({ tool: 'list-items', channel: 'mcp', client_ref: 'Claude Desktop' })

const bootstrap = sampleBootstrap()

// The mock hands back its live state objects; clone each reply as the wire would, so a
// saved settings state is a new object and the forms see the change.
const transport: Transport = {
  request: (action, payload, options) => mock.transport.request(action, payload, options).then(reply => JSON.parse(JSON.stringify(reply ?? null))),
}
const SUGGESTIONS = ['List my items', 'Delete item 12', 'What can you do?']

/** A few tokens in another host's style; set with a selector that beats `:where(.aikit)`. */
const BIT_FORM_LIKE: Record<string, string> = {
  '--aikit-accent': 'hsl(213, 29%, 33%)',
  '--aikit-accent-hover': 'hsl(213, 29%, 26%)',
  '--aikit-accent-soft': 'hsl(213, 20%, 95%)',
  '--aikit-border': '#e2e8f0',
  '--aikit-border-strong': '#cbd5e1',
  '--aikit-bg-soft': '#f8fafc',
  '--aikit-fg-muted': '#64748b',
  '--aikit-radius': '10px',
  '--aikit-radius-sm': '8px',
  '--aikit-font-size': '14px',
}

/** In dark mode only the accent and shape carry over; the neutrals stay the dark ones. */
const BIT_FORM_LIKE_SHAPE: Record<string, string> = {
  '--aikit-accent': 'hsl(213, 45%, 62%)',
  '--aikit-accent-hover': 'hsl(213, 45%, 70%)',
  '--aikit-accent-fg': '#0b1220',
  '--aikit-radius': BIT_FORM_LIKE['--aikit-radius'],
  '--aikit-radius-sm': BIT_FORM_LIKE['--aikit-radius-sm'],
  '--aikit-font-size': BIT_FORM_LIKE['--aikit-font-size'],
}

function varsCss(selector: string, vars: Record<string, string | number | undefined>): string {
  const body = Object.entries(vars).filter(([, value]) => value !== undefined && value !== '').map(([key, value]) => `  ${key}: ${value};`).join('\n')
  return `${selector} {\n${body}\n}`
}

// The defaults sit on :root, so the theme on the wrapper reaches every component (and the
// dark values on `.aikit` beat them for the components themselves).
const SCOPE = '.pg-root, .pg-root .aikit'

export default function App() {
  const [page, setPage] = useState<Page>('dock')
  const [themeName, setThemeName] = useState<Theme>('plain')
  const [mode, setMode] = useState<Mode>('light')

  useEffect(() => {
    document.body.classList.toggle('pg-dark', mode === 'dark')
  }, [mode])

  const shell = <Shell page={page} setPage={setPage} themeName={themeName} setThemeName={setThemeName} mode={mode} setMode={setMode} />

  if (themeName === 'antd') {
    return (
      <ConfigProvider theme={{ algorithm: mode === 'dark' ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm }}>
        <AntdThemed mode={mode}>{shell}</AntdThemed>
      </ConfigProvider>
    )
  }

  return (
    <Themed mode={mode} css={themeName === 'bitform' ? varsCss(SCOPE, mode === 'dark' ? BIT_FORM_LIKE_SHAPE : BIT_FORM_LIKE) : ''} components={undefined}>
      {shell}
    </Themed>
  )
}

/** Reads the antd tokens (inside the ConfigProvider) and maps the slots to antd. */
function AntdThemed({ mode, children }: { mode: Mode, children: ReactNode }) {
  const vars = useAntdTheme() as Record<string, string>
  return <Themed mode={mode} css={varsCss(SCOPE, vars)} style={vars as CSSProperties} components={antdComponents}>{children}</Themed>
}

interface ThemedProps {
  mode: Mode
  css: string
  style?: CSSProperties
  components: typeof antdComponents | undefined
  children: ReactNode
}

function Themed({ mode, css, style, components, children }: ThemedProps) {
  return (
    <AiKitProvider bootstrap={bootstrap} transport={transport} components={components}>
      <style>{css}</style>
      <div className="pg-root" data-theme={mode} style={style}>{children}</div>
    </AiKitProvider>
  )
}

interface ShellProps {
  page: Page
  setPage: (page: Page) => void
  themeName: Theme
  setThemeName: (theme: Theme) => void
  mode: Mode
  setMode: (mode: Mode) => void
}

function Shell({ page, setPage, themeName, setThemeName, mode, setMode }: ShellProps) {
  return (
    <div className="pg">
      <nav className="pg-nav" aria-label="Playground">
        <h1>wp-ai-kit-ui</h1>
        {PAGES.map(item => (
          <button key={item.id} type="button" aria-current={page === item.id ? 'page' : undefined} onClick={() => setPage(item.id)}>{item.label}</button>
        ))}
        <div className="pg-section">Theme</div>
        <label>
          <select value={themeName} onChange={e => setThemeName(e.target.value as Theme)}>
            <option value="plain">Plain defaults</option>
            <option value="bitform">Bit-Form-like</option>
            <option value="antd">antd</option>
          </select>
        </label>
        <label>
          <input type="checkbox" checked={mode === 'dark'} onChange={e => setMode(e.target.checked ? 'dark' : 'light')} />
          Dark
        </label>
      </nav>
      <main className="pg-main">
        <PageView page={page} mode={mode} />
      </main>
    </div>
  )
}

function Prose({ title, children }: { title: string, children?: ReactNode }) {
  return (
    <div className="pg-prose">
      <h2>{title}</h2>
      {children || <p>The host screen. The assistant sits beside it and talks about what is on it, so nothing here is covered by a backdrop.</p>}
    </div>
  )
}

/** Each chat surface gets `data-theme` so `.aikit[data-theme="dark"]` applies to its own root too. */
function useDarkMarker(mode: Mode) {
  useEffect(() => {
    document.querySelectorAll('.pg-root .aikit').forEach((el) => { el.setAttribute('data-theme', mode) })
  })
}

function PageView({ page, mode }: { page: Page, mode: Mode }) {
  useDarkMarker(mode)
  switch (page) {
    case 'dock':
      return <DockDemo />
    case 'floating':
      return <FloatingDemo />
    case 'drawer':
      return <DrawerDemo />
    case 'page':
      return (
        <div className="pg-content">
          <div className="pg-page-chat" style={{ display: 'grid', gridTemplateColumns: '280px minmax(0, 1fr)', gap: 16 }}>
            <ConversationHistory anchor="inline" />
            <AssistantChat title="AI Assistant" suggestions={SUGGESTIONS} />
          </div>
        </div>
      )
    case 'card':
      return (
        <div className="pg-content">
          <Prose title="Embedded card"><p>The chat inside a fixed-size card, as a dashboard widget would host it.</p></Prose>
          <div className="pg-card">
            <AssistantChat title="Ask about this screen" context={{ screen: 'dashboard' }} suggestions={SUGGESTIONS.slice(0, 2)} />
          </div>
        </div>
      )
    case 'settings':
      return (
        <div className="pg-content">
          <AssistantSettingsForm showIntro onViewAllActivity={() => window.alert('Host navigates to the activity log.')} />
        </div>
      )
    case 'mcp':
      return (
        <div className="pg-content">
          <McpSettings server={{ name: 'Bit Demo', slug: 'bit-demo' }} showIntro />
        </div>
      )
    case 'bleed':
      return <BleedDemo />
    default:
      return null
  }
}

function DockDemo() {
  const [open, setOpen] = useState(true)
  return (
    <div className={open ? 'pg-with-dock' : ''}>
      <div className="pg-content">
        <Prose title="Dock layout" />
        {!open && <button type="button" onClick={() => setOpen(true)}>Open the assistant</button>}
      </div>
      {open && (
        <aside className="pg-dock" aria-label="AI assistant">
          <AssistantChat title="AI Assistant" onClose={() => setOpen(false)} suggestions={SUGGESTIONS} context={() => ({ screen: 'dock-demo' })} onEvent={event => console.info('[aikit event]', event)} />
        </aside>
      )}
    </div>
  )
}

function FloatingDemo() {
  const [open, setOpen] = useState(false)
  return (
    <div className="pg-content">
      <Prose title="Floating"><p>A launcher in the corner opens a compact panel.</p></Prose>
      {!open && <FloatingLauncher onClick={() => setOpen(true)} label="Ask AI" />}
      <Panel open={open} onClose={() => setOpen(false)} size="compact">
        <AssistantChat title="AI Assistant" onClose={() => setOpen(false)} suggestions={SUGGESTIONS} />
      </Panel>
    </div>
  )
}

function DrawerDemo() {
  const [open, setOpen] = useState(true)
  const [previewing, setPreviewing] = useState(false)
  return (
    <div className="pg-content">
      <Prose title="Drawer"><p>A full-height column down the right edge, over the screen but without a backdrop.</p></Prose>
      <p>
        <button type="button" onClick={() => setOpen(o => !o)}>{open ? 'Close' : 'Open'} the drawer</button>
        {' '}
        <label><input type="checkbox" checked={previewing} onChange={e => setPreviewing(e.target.checked)} /> Pretend an unsaved preview blocks switching chats</label>
      </p>
      <Panel open={open} onClose={() => setOpen(false)} size="full">
        <AssistantChat
          title="AI Assistant"
          onClose={() => setOpen(false)}
          suggestions={SUGGESTIONS}
          switchBlockedReason={previewing ? 'Apply or discard the preview first.' : undefined}
          renderPreviewBar={({ busy }) => (previewing ? <div className="aikit-notice aikit-notice--info">{busy ? 'Updating the preview…' : 'Preview ready: Apply · Discard'}</div> : null)}
        />
      </Panel>
    </div>
  )
}

const WP_CSS = [
  'https://cdn.jsdelivr.net/gh/WordPress/WordPress@6.8/wp-admin/css/common.min.css',
  'https://cdn.jsdelivr.net/gh/WordPress/WordPress@6.8/wp-admin/css/forms.min.css',
  'https://cdn.jsdelivr.net/gh/WordPress/WordPress@6.8/wp-includes/css/buttons.min.css',
]
const TAILWIND = 'https://cdn.tailwindcss.com/3.4.16'

/** Toggle a global stylesheet / script on the document, as a host page would load it. */
function useGlobalAsset(on: boolean, kind: 'css' | 'tailwind') {
  useEffect(() => {
    if (!on) return undefined
    const nodes: HTMLElement[] = []
    if (kind === 'css') {
      WP_CSS.forEach((href) => {
        const link = document.createElement('link')
        link.rel = 'stylesheet'
        link.href = href
        link.dataset.pg = 'wp'
        document.head.appendChild(link)
        nodes.push(link)
      })
    } else {
      // Tailwind's Play CDN reads `tailwind.config` before its first scan: preflight off, as a host using it beside WP admin would.
      const config = document.createElement('script')
      config.textContent = 'window.tailwind = window.tailwind || {}; window.tailwind.config = { corePlugins: { preflight: false } };'
      const script = document.createElement('script')
      script.src = TAILWIND
      script.onload = () => {
        const w = window as unknown as { tailwind?: { config?: unknown } }
        if (w.tailwind) w.tailwind.config = { corePlugins: { preflight: false } }
      }
      document.head.append(config, script)
      nodes.push(config, script)
    }
    // Tailwind's runtime keeps its injected <style>; reload the page to drop it completely.
    return () => nodes.forEach(node => node.remove())
  }, [on, kind])
}

function BleedDemo() {
  const [wp, setWp] = useState(true)
  const [tw, setTw] = useState(false)
  useGlobalAsset(wp, 'css')
  useGlobalAsset(tw, 'tailwind')
  const rows = useMemo(() => [['Spring newsletter', 'Published', '48'], ['Contact list', 'Draft', '0']], [])

  return (
    <div className="pg-content wp-core-ui">
      <div className="pg-toolbar">
        <label><input type="checkbox" checked={wp} onChange={e => setWp(e.target.checked)} /> WordPress admin CSS (common, forms, buttons)</label>
        <label><input type="checkbox" checked={tw} onChange={e => setTw(e.target.checked)} /> Tailwind, preflight off</label>
        <span>Pick “antd” in the theme menu to add the ConfigProvider.</span>
      </div>
      <div className="pg-bleed">
        <div className="pg-static">
          <h1>Heading 1 outside .aikit</h1>
          <h2>Heading 2</h2>
          <h3>Heading 3</h3>
          <h4>Heading 4</h4>
          <p>A paragraph with <a href="#bleed">a link</a> and <code>code</code>. These elements are plain HTML on the host page.</p>
          <p>
            <button type="button">Plain button</button>
            {' '}
            <button type="button" className="button button-primary">WP primary</button>
          </p>
          <p><input type="text" placeholder="Plain text input" /> <input type="checkbox" defaultChecked /> <input type="radio" defaultChecked /></p>
          <table>
            <thead><tr><th>Name</th><th>Status</th><th>Records</th></tr></thead>
            <tbody>{rows.map(row => <tr key={row[0]}>{row.map(cell => <td key={cell}>{cell}</td>)}</tr>)}</tbody>
          </table>
        </div>
        <div>
          <AssistantSettingsForm showRecentActivity={false} />
          <div className="pg-card" style={{ height: 380, marginTop: 16 }}>
            <AssistantChat title="Chat beside the host's styles" suggestions={SUGGESTIONS.slice(0, 2)} />
          </div>
        </div>
      </div>
    </div>
  )
}
