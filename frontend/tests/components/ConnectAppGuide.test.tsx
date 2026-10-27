import { fireEvent, render, screen } from '@testing-library/react'
import ConnectAppGuide from '../../src/components/ConnectAppGuide/ConnectAppGuide'
import { clientById, clients } from '../../src/components/ConnectAppGuide/clients'
import { McpIcon } from '../../src/components/icons/ai-clients'
import { AiKitProvider } from '../../src/core/context'
import { defaultI18n } from '../../src/core/i18n'
import { createMockTransport, sampleBootstrap } from '../mockTransport'

const server = { name: 'Demo Server', slug: 'demo', endpoint: 'https://example.test/wp-json/bit-demo/v1/mcp' }

function setup(props: Partial<React.ComponentProps<typeof ConnectAppGuide>> = {}) {
  const mock = createMockTransport()
  const utils = render(
    <AiKitProvider bootstrap={sampleBootstrap()} transport={mock.transport}>
      <ConnectAppGuide server={server} profileUrl="https://example.test/wp-admin/profile.php#application-passwords-section" {...props} />
    </AiKitProvider>,
  )
  return { mock, ...utils }
}

const snippet = (container: HTMLElement) => container.querySelector('pre')?.textContent || ''

describe('ConnectAppGuide', () => {
  it('shows a tile per app and filters them', async () => {
    setup()
    expect(await screen.findByRole('button', { name: /Claude\.ai/ })).toBeInTheDocument()
    for (const name of ['Claude Desktop', 'ChatGPT', 'Grok', 'Gemini CLI', 'Cursor', 'GitHub Copilot (VS Code)', 'Windsurf', 'Claude Code', 'OpenAI Codex CLI', 'Other MCP app']) {
      expect(screen.getByRole('button', { name: new RegExp(name.replace(/[()]/g, '\\$&')) })).toBeInTheDocument()
    }
    fireEvent.change(screen.getByRole('searchbox', { name: 'Find an app' }), { target: { value: 'curs' } })
    expect(screen.getByRole('button', { name: /Cursor/ })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /ChatGPT/ })).not.toBeInTheDocument()
  })

  it('Claude.ai opens the connector dialog with the server name and encoded endpoint', async () => {
    setup()
    fireEvent.click(await screen.findByRole('button', { name: /Claude\.ai/ }))
    const link = screen.getByRole('link', { name: 'Connect to Claude' })
    expect(link.getAttribute('href')).toContain(`connectorUrl=${encodeURIComponent(server.endpoint)}`)
    expect(link.getAttribute('href')).toContain(`connectorName=${encodeURIComponent('Demo Server')}`)
    expect(link).toHaveAttribute('target', '_blank')
    expect(screen.getByText('Add site').closest('li')).toHaveAttribute('aria-current', 'step')
  })

  it('Cursor shows its config snippet with the endpoint under the server slug', async () => {
    const { container } = setup()
    fireEvent.click(await screen.findByRole('button', { name: /Cursor/ }))
    const link = screen.getByRole('link', { name: 'Add to Cursor' })
    expect(link.getAttribute('href')).toMatch(/^cursor:\/\/anysphere\.cursor-deeplink\/mcp\/install\?name=demo&config=/)
    expect(link).not.toHaveAttribute('target')
    expect(JSON.parse(snippet(container))).toEqual({ mcpServers: { demo: { url: server.endpoint } } })
  })

  it("auth 'basic' shows the Application Password flow", async () => {
    const { container } = setup({ auth: 'basic' })
    expect(await screen.findByRole('button', { name: /Cursor Paste a config/ })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Claude Code/ }))
    expect(screen.getByText('This app signs in with an Application Password.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Create one in Users → Profile' })).toHaveAttribute('href', 'https://example.test/wp-admin/profile.php#application-passwords-section')
    expect(snippet(container)).toContain(`claude mcp add --transport http demo ${server.endpoint}`)
    expect(snippet(container)).toContain('Authorization: Basic')

    fireEvent.click(screen.getByRole('button', { name: 'Back' }))
    expect(screen.getAllByText('Needs OAuth sign-in').length).toBeGreaterThan(0)
    fireEvent.click(screen.getByRole('button', { name: /Claude\.ai/ }))
    expect(screen.getByText('Claude.ai signs in with OAuth, which is switched off on this site.')).toBeInTheDocument()
  })

  it('asks to switch the server on first', async () => {
    setup({ serverOn: false })
    fireEvent.click(await screen.findByRole('button', { name: /Cursor/ }))
    expect(screen.getByText(/Switch the MCP server on and save first/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'I added it' })).toBeDisabled()
  })

  it('waits for the first call, then confirms it', async () => {
    const onConnected = vi.fn()
    const { mock } = setup({ onConnected })
    mock.audit({ tool: 'list-forms', client_ref: 'MCP · Cursor', channel: 'mcp' })
    fireEvent.click(await screen.findByRole('button', { name: /Cursor/ }))
    fireEvent.click(screen.getByRole('button', { name: 'I added it' }))
    expect(await screen.findByText('It works: MCP · Cursor called list-forms.')).toBeInTheDocument()
    expect(onConnected).toHaveBeenCalledWith('cursor', 'list-forms')
    expect(mock.calls.some(call => call.action === 'mcp/activity' && typeof call.payload.from === 'string')).toBe(true)
  })

  it('merges host clients by id and keeps every snippet free of fixed names', () => {
    const list = clients(defaultI18n.__)
    expect(list.map(client => client.id)).toEqual(['claude-ai', 'claude-desktop', 'chatgpt', 'grok', 'gemini', 'gemini-cli', 'cursor', 'vscode', 'windsurf', 'claude-code', 'codex', 'other'])
    for (const client of list) {
      for (const flow of [client.oauth, client.basic]) {
        const text = [flow?.snippet?.(server), flow?.deepLink?.(server)].filter(Boolean).join('\n')
        expect(text).not.toMatch(/bitform|Bit Form/i)
      }
    }
    expect(clientById(list, 'windsurf')?.oauth.snippet?.(server)).toContain('"serverUrl"')
  })

  it('shows a host app added through `clients`', async () => {
    setup({ clients: [{ id: 'acme', name: 'Acme Agent', Icon: McpIcon, effort: 'Paste a URL', oauth: { steps: ['Paste the URL into Acme.'] } }] })
    fireEvent.click(await screen.findByRole('button', { name: /Acme Agent/ }))
    expect(screen.getByText('Paste the URL into Acme.')).toBeInTheDocument()
  })
})
