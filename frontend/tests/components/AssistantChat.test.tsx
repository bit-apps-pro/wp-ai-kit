import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import AssistantChat from '../../src/components/AssistantChat/AssistantChat'
import { aiKitBus } from '../../src/core/bus'
import { AiKitProvider } from '../../src/core/context'
import { createMockTransport, type MockOptions, sampleBootstrap } from '../mockTransport'

function renderChat(options: MockOptions = {}, props: Parameters<typeof AssistantChat>[0] = {}) {
  const mock = createMockTransport(options)
  render(
    <AiKitProvider bootstrap={sampleBootstrap()} transport={mock.transport}>
      <AssistantChat {...props} />
    </AiKitProvider>,
  )
  return mock
}

const input = () => screen.getByRole('textbox', { name: 'Message' }) as HTMLTextAreaElement

describe('AssistantChat', () => {
  it('shows the title, engine and the empty state, then a sent message and its reply', async () => {
    const user = userEvent.setup()
    const mock = renderChat({ reply: message => ({ text: `**Echo** of ${message}`, tools: [{ name: 'list-forms', title: 'List forms' }] }) })
    expect(screen.getByText('Bit Demo assistant')).toBeInTheDocument()
    expect(screen.getByText('Scripted model')).toBeInTheDocument()
    expect(await screen.findByText(/Ask about this site/)).toBeInTheDocument()

    await user.type(input(), 'hello there{Enter}')
    expect(screen.getByText('hello there')).toBeInTheDocument()
    expect(await screen.findByText('Echo')).toBeInTheDocument()
    expect(screen.getByText('List forms')).toHaveAttribute('title', 'list-forms')
    expect(input().value).toBe('')
    expect(mock.calls.some(call => call.action === 'assistant/chat' && call.payload.message === 'hello there')).toBe(true)
  })

  it('sends the page context and suggestions from the empty state', async () => {
    const user = userEvent.setup()
    const mock = renderChat({}, { context: () => ({ screen: 'dashboard' }), suggestions: ['What changed today?'] })
    await user.click(await screen.findByRole('button', { name: 'What changed today?' }))
    expect(await screen.findByText('You said: What changed today?')).toBeInTheDocument()
    const chat = mock.calls.find(call => call.action === 'assistant/chat')
    expect(chat?.payload.context).toEqual({ screen: 'dashboard' })
  })

  it('shows a scripted pending action and clears it once allowed', async () => {
    const user = userEvent.setup()
    const onEvent = vi.fn()
    renderChat({
      reply: () => ({ text: 'I can rename it.', pending: { name: 'rename-form', title: 'Rename a form', description: 'Renames it.', destructive: false, input: { id: 3, title: 'New' } } }),
      decided: approve => ({ text: approve ? 'Renamed.' : 'Left as is.' }),
    }, { onEvent })
    await user.type(input(), 'rename form 3{Enter}')
    const card = await screen.findByRole('alertdialog', { name: 'Confirm action' })
    expect(within(card).getByText(/Rename a form/)).toBeInTheDocument()
    expect(within(card).getByText('title')).toBeInTheDocument()
    expect(within(card).getByText('This changes your site. Nothing runs until you allow it.')).toBeInTheDocument()
    expect(input()).toBeDisabled()

    await user.click(within(card).getByRole('button', { name: 'Allow' }))
    expect(await screen.findByText('Renamed.')).toBeInTheDocument()
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    expect(onEvent).toHaveBeenCalledWith(expect.objectContaining({ type: 'pending', name: 'rename-form' }))
    expect(onEvent).toHaveBeenCalledWith({ type: 'approved', name: 'rename-form' })
  })

  it('restores the draft and shows the error with a settings link when a turn is refused', async () => {
    const user = userEvent.setup()
    const onError = vi.fn()
    const mock = renderChat({}, { onError })
    await screen.findByText(/Ask about this site/)
    mock.assistantState.engine = null
    mock.assistantState.engine_reason = 'No AI engine is set up.'
    await user.type(input(), 'will fail{Enter}')
    expect(await screen.findByRole('alert')).toHaveTextContent('No AI engine is set up.')
    expect(input().value).toBe('will fail')
    expect(screen.getByRole('link', { name: 'Assistant settings' })).toHaveAttribute('href', sampleBootstrap().settingsUrl as string)
    expect(screen.getByText('Not connected')).toBeInTheDocument()
    expect(onError).toHaveBeenCalledWith('No AI engine is set up.')
  })

  it('says why the chat is not connected, with the settings link, and disables the suggestions', async () => {
    const mock = renderChat({ latency: 5 }, { suggestions: ['Try me'] })
    mock.assistantState.engine = null
    mock.assistantState.engine_reason = 'Add an API key first.'
    expect(await screen.findByText(/Add an API key first\./)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Assistant settings' })).toHaveAttribute('href', sampleBootstrap().settingsUrl as string)
    expect(screen.getByRole('button', { name: 'Try me' })).toBeDisabled()
  })

  it('onSend returning false keeps the message unsent', async () => {
    const user = userEvent.setup()
    const mock = renderChat({}, { onSend: () => false })
    await screen.findByText(/Ask about this site/)
    await user.type(input(), 'stop{Enter}')
    expect(mock.calls.some(call => call.action === 'assistant/chat')).toBe(false)
  })

  it('sends a message the window bus asks for, in a new chat', async () => {
    const mock = createMockTransport()
    mock.seed({ id: 'old', title: 'Earlier', messages: [{ role: 'user', text: 'old question' }, { role: 'assistant', text: 'old answer' }] })
    mock.activeId = 'old'
    const onEvent = vi.fn()
    render(
      <AiKitProvider bootstrap={sampleBootstrap()} transport={mock.transport}>
        <AssistantChat onEvent={onEvent} />
      </AiKitProvider>,
    )
    expect(await screen.findByText('old answer')).toBeInTheDocument()
    act(() => aiKitBus('bit-demo').open({ message: 'Build a survey', newChat: true }))
    expect(await screen.findByText('You said: Build a survey')).toBeInTheDocument()
    expect(screen.queryByText('old answer')).not.toBeInTheDocument()
    expect(mock.calls.map(call => call.action)).toContain('assistant/reset')
    expect(onEvent).toHaveBeenCalledWith({ type: 'open', detail: { message: 'Build a survey', newChat: true } })
  })

  it('puts a bus message in the composer without sending when autoSend is false', async () => {
    const mock = renderChat()
    await screen.findByText(/Ask about this site/)
    act(() => aiKitBus('bit-demo').open({ message: 'Draft only', autoSend: false }))
    await waitFor(() => expect(input().value).toBe('Draft only'))
    expect(mock.calls.some(call => call.action === 'assistant/chat')).toBe(false)
  })

  it('opens the history popover and blocks switching when asked', async () => {
    const user = userEvent.setup()
    const mock = createMockTransport()
    mock.seed({ id: 'a', title: 'First chat', messages: [{ role: 'user', text: 'x' }] })
    render(
      <AiKitProvider bootstrap={sampleBootstrap()} transport={mock.transport}>
        <AssistantChat switchBlockedReason="Apply or discard the preview first." />
      </AiKitProvider>,
    )
    const toggle = screen.getByRole('button', { name: 'Chat history' })
    await user.click(toggle)
    expect(toggle).toHaveAttribute('aria-pressed', 'true')
    const popover = await screen.findByRole('dialog', { name: 'Chat history' })
    expect(within(popover).getByText('Apply or discard the preview first.')).toBeInTheDocument()
    expect(await within(popover).findByText('First chat')).toBeInTheDocument()
    expect(within(popover).getByRole('button', { name: /^First chat/ })).toBeDisabled()
    await user.keyboard('{Escape}')
    expect(screen.queryByRole('dialog', { name: 'Chat history' })).not.toBeInTheDocument()
  })

  it('has no history button or /history command when the plugin leaves history out', async () => {
    const mock = createMockTransport()
    render(
      <AiKitProvider bootstrap={sampleBootstrap({ features: { ...sampleBootstrap().features, history: false } })} transport={mock.transport}>
        <AssistantChat />
      </AiKitProvider>,
    )
    await screen.findByText(/Ask about this site/)
    expect(screen.queryByRole('button', { name: 'Chat history' })).toBeNull()
    expect(screen.getByRole('button', { name: 'New chat' })).toBeInTheDocument()
  })
})
