import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import McpSettings from '../../src/components/McpSettings/McpSettings'
import { AiKitProvider } from '../../src/core/context'
import type { Transport } from '../../src/core/transport'
import { createMockTransport, sampleBootstrap, type MockOptions } from '../mockTransport'

/** Replies as fresh JSON, as over the wire: the mock hands back its live, mutated state objects. */
function wire(transport: Transport): Transport {
  return { request: (action, payload, options) => transport.request(action, payload, options).then(reply => JSON.parse(JSON.stringify(reply ?? null))) }
}

function setup(options: MockOptions = {}, props: Partial<React.ComponentProps<typeof McpSettings>> = {}, seed?: (mock: ReturnType<typeof createMockTransport>) => void) {
  const mock = createMockTransport(options)
  seed?.(mock)
  const utils = render(
    <AiKitProvider bootstrap={options.bootstrap || sampleBootstrap()} transport={wire(mock.transport)}>
      <div className="aikit">
        <McpSettings server={{ name: 'Demo Server', slug: 'demo' }} profileUrl="https://example.test/wp-admin/profile.php#application-passwords-section" {...props} />
      </div>
    </AiKitProvider>,
  )
  return { mock, ...utils }
}

const calls = (mock: ReturnType<typeof createMockTransport>, action: string) => mock.calls.filter(call => call.action === action)

describe('McpSettings', () => {
  it('shows the intro, the status and the endpoint on the overview', async () => {
    setup()
    expect(screen.getByRole('heading', { name: 'MCP Server' })).toBeInTheDocument()
    expect(await screen.findByText('MCP server on · 1 app connected')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Documentation' })).toHaveAttribute('href', 'https://example.test/docs')
    expect(screen.getByText(/work with Demo Server through the Model Context Protocol/)).toBeInTheDocument()
    expect(screen.getAllByText('https://example.test/wp-json/bit-demo/v1/mcp').length).toBeGreaterThan(0)
    expect(screen.getByRole('heading', { name: 'Connect your AI app' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Diagnostics' })).toBeInTheDocument()
    expect(screen.getByText('1 of 3 checks need a look.')).toBeInTheDocument()
  })

  it('lists the tools; switching one marks the form dirty and Save sends it', async () => {
    const onSaved = vi.fn()
    const { mock } = setup({}, { defaultTab: 'tools', onSaved })
    expect(await screen.findByText('List forms')).toBeInTheDocument()
    expect(screen.getByText('Rename a form')).toBeInTheDocument()
    expect(screen.getByText('Pro')).toBeInTheDocument()
    expect(screen.getByText('2 of 3 tools enabled')).toBeInTheDocument()
    expect(screen.queryByText('You have unsaved changes.')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('switch', { name: 'Allow Rename a form' }))
    expect(screen.getByText('You have unsaved changes.')).toBeInTheDocument()
    expect(screen.getByText('1 of 3 tools enabled')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(calls(mock, 'settings/mcp/save')).toHaveLength(1))
    expect((calls(mock, 'settings/mcp/save')[0].payload.settings as Record<string, unknown>).disabled_tools).toEqual(['rename-form'])
    await waitFor(() => expect(screen.queryByText('You have unsaved changes.')).not.toBeInTheDocument())
    expect(onSaved).toHaveBeenCalledTimes(1)
  })

  it('caps the tools at the access level', async () => {
    setup({}, { defaultTab: 'tools' })
    await screen.findByText('List forms')
    fireEvent.click(screen.getByRole('radio', { name: /Read only/ }))
    expect(screen.getByRole('switch', { name: 'Allow Rename a form' })).toBeDisabled()
    expect(screen.getByText('1 of 3 tools enabled')).toBeInTheDocument()
  })

  it('lists the seeded connection and Disconnect revokes it', async () => {
    const onTabChange = vi.fn()
    const { mock } = setup({}, { onTabChange })
    await screen.findByText('MCP server on · 1 app connected')
    fireEvent.click(screen.getByRole('tab', { name: 'Connections' }))
    expect(onTabChange).toHaveBeenCalledWith('connections')

    const row = screen.getByText('Claude').closest('tr') as HTMLElement
    expect(within(row).getByText('Published at claude.ai')).toBeInTheDocument()
    expect(within(row).getByText('Read and write')).toBeInTheDocument()
    fireEvent.click(within(row).getByRole('button', { name: 'Disconnect' }))

    const dialog = await screen.findByText('Disconnect this app? It has to be approved again to reconnect.')
    const modal = dialog.closest('dialog') as HTMLElement
    fireEvent.click(within(modal).getByRole('button', { name: 'Disconnect', hidden: true }))
    await waitFor(() => expect(calls(mock, 'mcp/connections/revoke')).toHaveLength(1))
    expect(calls(mock, 'mcp/connections/revoke')[0].payload).toEqual({ id: 'c1' })
    await waitFor(() => expect(screen.queryByText('Published at claude.ai')).not.toBeInTheDocument())
    expect(screen.getByText('No app has connected yet. Use “Connect your AI app” to add one.')).toBeInTheDocument()
  })

  it('creates an API key and shows it once', async () => {
    const { mock } = setup({}, { defaultTab: 'connections' })
    await screen.findByRole('heading', { name: 'API keys' })
    fireEvent.change(screen.getByRole('textbox', { name: 'Key name' }), { target: { value: 'CI deploy' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create key' }))
    await waitFor(() => expect(calls(mock, 'mcp/api-keys/create')).toHaveLength(1))
    expect(await screen.findByText('Copy this key now. It will not be shown again.')).toBeInTheDocument()
    expect(screen.getByText('bitdemo_key_k1_secretvalue')).toBeInTheDocument()
    expect(screen.getByText('CI deploy')).toBeInTheDocument()
  })

  it('lists the activity, narrows it by status and clears it', async () => {
    const { mock } = setup({}, { defaultTab: 'activity' }, (m) => {
      m.audit({ tool: 'list-forms', status: 'ok' })
      m.audit({ tool: 'rename-form', status: 'denied', error_code: 'ai_forbidden' })
      m.audit({ tool: 'delete-form', status: 'error' })
    })

    expect(await screen.findByText('ai_forbidden')).toBeInTheDocument()
    const table = screen.getByRole('table')
    expect(within(table).getAllByRole('row')).toHaveLength(4)

    fireEvent.click(screen.getByRole('button', { name: /Filters/ }))
    fireEvent.change(screen.getByRole('combobox', { name: 'Result' }), { target: { value: 'denied' } })
    await waitFor(() => expect(within(screen.getByRole('table')).getAllByRole('row')).toHaveLength(2))
    expect(calls(mock, 'mcp/activity').slice(-1)[0]?.payload).toMatchObject({ status: 'denied', page: 1 })
    expect(screen.getByText('Result: Denied')).toBeInTheDocument()
    expect(screen.getByText('1 call matches')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Clear log' }))
    const modal = (await screen.findByText(/Delete every entry in the activity log/)).closest('dialog') as HTMLElement
    fireEvent.click(within(modal).getByRole('button', { name: 'Delete all', hidden: true }))
    await waitFor(() => expect(calls(mock, 'mcp/activity/clear')).toHaveLength(1))
    await waitFor(() => expect(screen.queryByRole('table')).not.toBeInTheDocument())
  })

  it('hides Clear log from users who may not manage', async () => {
    setup({ bootstrap: sampleBootstrap({ canManage: false }) }, { defaultTab: 'activity' }, m => m.audit({ tool: 'list-forms' }))
    await screen.findByText('list-forms')
    expect(screen.queryByRole('button', { name: 'Clear log' })).not.toBeInTheDocument()
  })

  it('shows only the tabs asked for and follows a controlled tab', async () => {
    const { rerender, mock } = setup({}, { tabs: ['tools', 'settings'], tab: 'settings' })
    await screen.findByText('Tool calls per minute')
    expect(screen.queryByRole('tab', { name: 'Overview' })).not.toBeInTheDocument()
    rerender(
      <AiKitProvider bootstrap={sampleBootstrap()} transport={wire(mock.transport)}>
        <McpSettings server={{ name: 'Demo Server', slug: 'demo' }} tabs={['tools', 'settings']} tab="tools" />
      </AiKitProvider>,
    )
    expect(await screen.findByText('List forms')).toBeInTheDocument()
  })

  it('hides the parts the plugin left out: activity, OAuth, API keys, the bridges', async () => {
    const features = { ...sampleBootstrap().features, audit: false, oauth: false, apiKeys: false, abilities: false, mcpAdapter: false }
    setup({ bootstrap: sampleBootstrap({ features }) })
    await screen.findByText('MCP server on · 1 app connected')

    const tabs = screen.getAllByRole('tab').map(tab => tab.textContent)
    expect(tabs).toEqual(['Overview', 'Connections', 'Tools', 'Settings'])
    expect(screen.queryByRole('heading', { name: /Recent activity/ })).toBeNull()
    expect(screen.queryByRole('button', { name: /Claude\.ai/ })).toBeNull()

    fireEvent.click(screen.getByRole('tab', { name: 'Connections' }))
    expect(screen.queryByRole('heading', { name: /Sign in with OAuth/ })).toBeNull()
    expect(screen.queryByRole('heading', { name: /API keys/ })).toBeNull()
    expect(screen.getByRole('heading', { name: /Application Passwords/ })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('tab', { name: 'Settings' }))
    expect(await screen.findByText('Personal data')).toBeInTheDocument()
    expect(screen.queryByText('Activity log')).toBeNull()
    expect(screen.queryByText('Advanced')).toBeNull()
  })
})
