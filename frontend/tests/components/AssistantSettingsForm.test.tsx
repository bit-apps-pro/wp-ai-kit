import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import AssistantSettingsForm, { siteUrlFromRestBase, timeAgo } from '../../src/components/AssistantSettingsForm/AssistantSettingsForm'
import { AiKitProvider } from '../../src/core/context'
import { defaultI18n } from '../../src/core/i18n'
import type { AssistantState } from '../../src/core/types'
import type { Transport } from '../../src/core/transport'
import { createMockTransport, sampleBootstrap, type MockOptions } from '../mockTransport'

/** Replies as fresh JSON, as over the wire: the mock hands back its live, mutated state objects. */
function wire(transport: Transport): Transport {
  return { request: (action, payload, options) => transport.request(action, payload, options).then(reply => JSON.parse(JSON.stringify(reply ?? null))) }
}

function setup(options: MockOptions = {}, props: Partial<React.ComponentProps<typeof AssistantSettingsForm>> = {}) {
  const mock = createMockTransport(options)
  const bootstrap = options.bootstrap || sampleBootstrap()
  const utils = render(
    <AiKitProvider bootstrap={bootstrap} transport={wire(mock.transport)}>
      <AssistantSettingsForm {...props} />
    </AiKitProvider>,
  )
  return { mock, ...utils }
}

const saveCalls = (mock: ReturnType<typeof createMockTransport>) => mock.calls.filter(call => call.action === 'settings/assistant/save').map(call => call.payload.settings as Record<string, unknown>)

describe('AssistantSettingsForm', () => {
  it('renders the saved state', async () => {
    const { mock } = setup({}, { showIntro: true })
    mock.audit({ tool: 'list-items' })

    expect(screen.getByRole('heading', { name: 'AI Assistant' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Documentation' })).toHaveAttribute('href', 'https://example.test/docs')

    await waitFor(() => expect(screen.getByRole('switch', { name: 'AI assistant' })).toBeInTheDocument())
    expect(screen.getByRole('switch', { name: 'AI assistant' })).toHaveAttribute('aria-checked', 'true')
    expect(screen.getByText('On')).toBeInTheDocument()
    expect(screen.getByText('Answers come from: Scripted model')).toBeInTheDocument()
    expect(screen.getByRole('radiogroup', { name: 'AI engine' })).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: /Automatic/ })).toBeChecked()
    expect(screen.getByRole('radio', { name: /Read and write/ })).toBeChecked()
    expect(screen.getByLabelText('Steps per answer')).toHaveValue(6)
    expect(screen.getByLabelText('Messages per user per day')).toHaveValue(300)
    expect(screen.getByLabelText('Keep chat history (days)')).toHaveValue(30)
    // auto + no WordPress AI: the provider's own key is in use, saved key hinted.
    expect(screen.getByText(/Saved, ends in …ab12\./)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Open WordPress Settings/ })).toHaveAttribute('href', 'https://example.test/wp-admin/options-connectors.php')
    expect(screen.queryByText('You have unsaved changes.')).not.toBeInTheDocument()

    await waitFor(() => expect(screen.getByText('list-items')).toBeInTheDocument())
    expect(screen.getByText('OK')).toBeInTheDocument()
    expect(screen.getByText('just now')).toBeInTheDocument()
    expect(mock.calls.find(call => call.action === 'mcp/activity')?.payload).toMatchObject({ channel: 'assistant', per_page: 5 })
  })

  it('hides the sections while the assistant is switched off', async () => {
    setup()
    const toggle = await screen.findByRole('switch', { name: 'AI assistant' })

    fireEvent.click(toggle)

    expect(toggle).toHaveAttribute('aria-checked', 'false')
    expect(screen.queryByRole('radiogroup', { name: 'AI engine' })).not.toBeInTheDocument()
    expect(screen.queryByText('What the assistant may do')).not.toBeInTheDocument()
    expect(screen.queryByText('Privacy and limits')).not.toBeInTheDocument()
    expect(screen.queryByText(/Answers come from/)).not.toBeInTheDocument()
    // Recent activity stays: the log is history, not a setting.
    expect(screen.getByText('Recent activity')).toBeInTheDocument()
    expect(screen.getByText('You have unsaved changes.')).toBeInTheDocument()
  })

  it('marks the draft dirty and saves it', async () => {
    const onSaved = vi.fn()
    const { mock } = setup({}, { onSaved })
    const turns = await screen.findByLabelText('Steps per answer')

    fireEvent.change(turns, { target: { value: '8' } })

    const bar = screen.getByRole('region', { name: 'Unsaved changes' })
    expect(within(bar).getByText('You have unsaved changes.')).toBeInTheDocument()
    fireEvent.click(within(bar).getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(saveCalls(mock)).toHaveLength(1))
    expect(saveCalls(mock)[0]).toMatchObject({ enabled: true, engine: 'auto', max_turns: 8, daily_messages: 300 })
    expect(saveCalls(mock)[0]).not.toHaveProperty('api_key')
    await waitFor(() => expect(screen.queryByRole('region', { name: 'Unsaved changes' })).not.toBeInTheDocument())
    expect(onSaved).toHaveBeenCalledWith(expect.objectContaining({ settings: expect.objectContaining({ max_turns: 8 }) }))
    expect(screen.getByText('Settings saved.')).toBeInTheDocument()
  })

  it('discards the draft', async () => {
    setup()
    const turns = await screen.findByLabelText('Steps per answer')
    fireEvent.change(turns, { target: { value: '3' } })

    fireEvent.click(screen.getByRole('button', { name: 'Discard' }))

    expect(screen.getByLabelText('Steps per answer')).toHaveValue(6)
    expect(screen.queryByText('You have unsaved changes.')).not.toBeInTheDocument()
  })

  it('sends a typed API key with the save and clears it afterwards', async () => {
    const user = userEvent.setup()
    const { mock } = setup()
    const input = await screen.findByLabelText(/^API key/)

    await user.type(input, 'sk-new-key')

    expect(screen.getByText('You have unsaved changes.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(saveCalls(mock)).toHaveLength(1))
    expect(saveCalls(mock)[0].api_key).toBe('sk-new-key')
    expect(saveCalls(mock)[0]).not.toHaveProperty('clear_api_key')
    await waitFor(() => expect(screen.getByLabelText(/^API key/)).toHaveValue(''))
    expect(screen.getByText(/Saved, ends in …-key\./)).toBeInTheDocument()
  })

  it('asks for a base URL when the provider is an OpenAI-compatible service', async () => {
    const user = userEvent.setup()
    const { mock } = setup()
    const provider = await screen.findByLabelText(/^Provider/)
    expect(screen.queryByLabelText(/^Base URL/)).not.toBeInTheDocument()

    await user.selectOptions(provider, 'openai_compatible')
    await user.type(screen.getByLabelText(/^Base URL/), 'https://api.mistral.ai/v1')
    await user.type(screen.getByLabelText(/^Model/), 'mistral-large-latest')
    await user.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(saveCalls(mock)).toHaveLength(1))
    expect(saveCalls(mock)[0]).toMatchObject({ provider: 'openai_compatible', base_url: 'https://api.mistral.ai/v1', model: 'mistral-large-latest' })
  })

  it('removes the saved key', async () => {
    const { mock } = setup()
    const trash = await screen.findByRole('button', { name: 'Remove the saved key' })

    fireEvent.click(trash)

    await waitFor(() => expect(saveCalls(mock)).toHaveLength(1))
    expect(saveCalls(mock)[0].clear_api_key).toBe(true)
    await waitFor(() => expect(screen.getByText('Stored encrypted. It is never shown again.')).toBeInTheDocument())
    expect(screen.queryByRole('button', { name: 'Remove the saved key' })).not.toBeInTheDocument()
  })

  it('switches the provider and clears the model', async () => {
    const { mock } = setup()
    const provider = await screen.findByLabelText('Provider')

    fireEvent.change(provider, { target: { value: 'openai' } })

    expect(screen.getByLabelText(/^Model/)).toHaveAttribute('placeholder', 'gpt-5')
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(saveCalls(mock)).toHaveLength(1))
    expect(saveCalls(mock)[0]).toMatchObject({ provider: 'openai', model: '' })
  })

  it('hides the key rows when WordPress AI answers', async () => {
    const mock = createMockTransport()
    mock.assistantState.wordpress_ai = true
    render(<AiKitProvider bootstrap={sampleBootstrap()} transport={wire(mock.transport)}><AssistantSettingsForm /></AiKitProvider>)

    await screen.findByRole('radiogroup', { name: 'AI engine' })

    expect(screen.queryByLabelText(/^API key/)).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Open WordPress Settings/ })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('radio', { name: /My own API key/ }))
    expect(screen.getByLabelText(/^API key/)).toBeInTheDocument()
  })

  it('shows the forced-off state and keeps the switch disabled', async () => {
    const mock = createMockTransport()
    mock.assistantState.forced_off = true
    render(<AiKitProvider bootstrap={sampleBootstrap()} transport={wire(mock.transport)}><AssistantSettingsForm showIntro /></AiKitProvider>)

    const toggle = await screen.findByRole('switch', { name: 'AI assistant' })

    expect(toggle).toBeDisabled()
    expect(toggle).toHaveAttribute('aria-checked', 'false')
    expect(screen.getByText('Turned off for this site by a constant or filter.')).toBeInTheDocument()
    expect(screen.getByText('Off')).toBeInTheDocument()
    expect(screen.queryByRole('radiogroup', { name: 'AI engine' })).not.toBeInTheDocument()
  })

  it('shows a save error', async () => {
    const { AiKitError } = await import('../../src/core/transport')
    setup({ fail: { 'settings/assistant/save': new AiKitError('Could not write the option.', 'ai_save_failed', 500, null) } })
    fireEvent.change(await screen.findByLabelText('Steps per answer'), { target: { value: '2' } })

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Could not write the option.'))
    expect(screen.getByText('You have unsaved changes.')).toBeInTheDocument()
  })

  it('appends host fields, hides cards on request and calls View all', async () => {
    const onViewAllActivity = vi.fn()
    setup({}, {
      showEngineCard: false,
      showAccessCard: false,
      onViewAllActivity,
      extraFields: ({ draft, state }: { draft: { max_turns: number }, state: AssistantState }) => <p data-testid="extra">{draft.max_turns}/{state.tools.length}</p>,
    })

    await screen.findByText('Privacy and limits')

    expect(screen.queryByRole('radiogroup', { name: 'AI engine' })).not.toBeInTheDocument()
    expect(screen.queryByText('What the assistant may do')).not.toBeInTheDocument()
    expect(screen.getByTestId('extra')).toHaveTextContent('6/3')
    fireEvent.click(screen.getByRole('button', { name: 'View all' }))
    expect(onViewAllActivity).toHaveBeenCalled()
  })

  it('shows a load error with a retry', async () => {
    const { AiKitError } = await import('../../src/core/transport')
    const { mock } = setup({ fail: { 'settings/assistant/get': new AiKitError('Forbidden', 'ai_forbidden', 403, null) } })

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Forbidden'))

    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
    await waitFor(() => expect(mock.calls.filter(call => call.action === 'settings/assistant/get')).toHaveLength(2))
  })
})

describe('helpers', () => {
  it('derives the site URL from the REST base', () => {
    expect(siteUrlFromRestBase('https://example.test/wp-json/bit-demo/v1/ai')).toBe('https://example.test')
    expect(siteUrlFromRestBase('https://example.test/blog/wp-json/bit-demo/v1/ai')).toBe('https://example.test/blog')
    expect(siteUrlFromRestBase('https://example.test/?rest_route=/bit-demo/v1/ai')).toBe('https://example.test')
    expect(siteUrlFromRestBase('https://example.test/blog/index.php?rest_route=/bit-demo/v1/ai')).toBe('https://example.test/blog')
    expect(siteUrlFromRestBase(null)).toBe('')
  })

  it('formats relative times from UTC timestamps', () => {
    const now = Date.parse('2026-10-24T12:00:00Z')
    expect(timeAgo('2026-10-24 11:59:40', defaultI18n, now)).toBe('just now')
    expect(timeAgo('2026-10-24 11:45:00', defaultI18n, now)).toBe('15 min ago')
    expect(timeAgo('2026-10-24 09:00:00', defaultI18n, now)).toBe('3 h ago')
    expect(timeAgo('2026-10-20 09:00:00', defaultI18n, now)).toBe(new Date('2026-10-20T09:00:00Z').toLocaleDateString())
    expect(timeAgo('', defaultI18n, now)).toBe('')
    expect(timeAgo('not a date', defaultI18n, now)).toBe('')
  })
})
