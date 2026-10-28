import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import AssistantChat from '../../src/components/AssistantChat/AssistantChat'
import { aiKitBus } from '../../src/core/bus'
import { AiKitProvider } from '../../src/core/context'
import { createMockTransport, type MockOptions, sampleBootstrap } from '../mockTransport'

/** The transcript, not the screen-reader status that repeats the last reply. */
const inBody = { selector: '.aikit-chat__body *' }

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
    expect(screen.getByText('hello there', { selector: '.aikit-chat__text' })).toBeInTheDocument()
    expect(await screen.findByText('Echo')).toBeInTheDocument()
    // A named chat shows its name; the assistant's name moves next to the engine.
    expect(screen.getByText('hello there', { selector: '.aikit-chat__title-text' })).toBeInTheDocument()
    expect(screen.getByText('Bit Demo assistant · Scripted model')).toBeInTheDocument()
    expect(screen.getByText('Used List forms')).toBeInTheDocument()
    expect(input().value).toBe('')
    expect(mock.calls.some(call => call.action === 'assistant/chat' && call.payload.message === 'hello there')).toBe(true)
  })

  it('marks a sent file the site no longer keeps as expired', async () => {
    const mock = createMockTransport()
    const row = mock.seed({ title: 'Receipts', messages: [
      { role: 'user', text: 'Read these', attachments: [{ id: 'a1', name: 'kept.pdf', kind: 'pdf', size: 3 }, { id: 'a2', name: 'old.png', kind: 'image', size: 3, expired: true }] },
      { role: 'assistant', text: 'Done.' },
    ] })
    mock.activeId = row.id
    render(
      <AiKitProvider bootstrap={sampleBootstrap()} transport={mock.transport}>
        <AssistantChat />
      </AiKitProvider>,
    )

    const files = within(await screen.findByRole('list', { name: 'Attached files' }))
    const old = files.getByText('old.png').closest('li') as HTMLElement
    expect(old).toHaveClass('aikit-chat__file--expired')
    expect(within(old).getByText('expired')).toBeInTheDocument()
    expect(files.getByText('kept.pdf').closest('li')).not.toHaveClass('aikit-chat__file--expired')
  })

  it('folds tool-only steps into the reply and tells failures from calls that did not run', async () => {
    const user = userEvent.setup()
    const mock = createMockTransport()
    const search = (extra: Record<string, boolean> = {}) => ({ name: 'search-entries', title: 'Search entries', error: false, ...extra })
    mock.seed({ id: 'c1', title: 'Busy forms', messages: [
      { role: 'user', text: 'Which forms got the most entries?' },
      { role: 'assistant', text: '', tools: [{ name: 'list-forms', title: 'List forms', error: false }] },
      { role: 'assistant', text: '', tools: [search(), search(), search({ error: true }), search({ error: true, skipped: true })] },
      { role: 'assistant', text: 'Form 93 got the most.', tools: [] },
    ] })
    mock.activeId = 'c1'
    const { container } = render(
      <AiKitProvider bootstrap={sampleBootstrap()} transport={mock.transport}>
        <AssistantChat />
      </AiKitProvider>,
    )

    expect(await screen.findByText('Form 93 got the most.', inBody)).toBeInTheDocument()
    expect(container.querySelectorAll('.aikit-chat__bubble--assistant')).toHaveLength(1)
    expect(screen.getByText('Used List forms, Search entries ×4')).toBeInTheDocument()
    expect(screen.getByText('· 1 failed')).toBeInTheDocument()
    expect(screen.getByText('· 1 not run')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Details' }))
    expect(screen.getByText('2 run · 1 failed · 1 not run')).toBeInTheDocument()
  })

  it('shows a streamed reply while it is written', async () => {
    const user = userEvent.setup()
    const mock = createMockTransport({ stream: true, latency: 20, reply: () => ({ text: 'Two forms here.', tools: [{ name: 'list-forms', title: 'List forms' }] }) })
    const { container } = render(
      <AiKitProvider bootstrap={sampleBootstrap({ features: { ...sampleBootstrap().features, stream: true } })} transport={mock.transport}>
        <AssistantChat />
      </AiKitProvider>,
    )
    await screen.findByText(/Ask about this site/)

    await user.type(input(), 'How many?{Enter}')

    expect(await screen.findByText('Using List forms…')).toBeInTheDocument()
    await waitFor(() => expect(container.querySelector('.aikit-chat__bubble--live')?.textContent).toContain('Two'))
    expect(await screen.findByText('Two forms here.')).toBeInTheDocument()
    await waitFor(() => expect(container.querySelector('.aikit-chat__bubble--live')).toBeNull())
    expect(screen.getByText('Used List forms')).toBeInTheDocument()
  })

  it('sends the page context and suggestions from the empty state', async () => {
    const user = userEvent.setup()
    const mock = renderChat({}, { context: () => ({ screen: 'dashboard' }), suggestions: ['What changed today?'] })
    await user.click(await screen.findByRole('button', { name: 'What changed today?' }))
    expect(await screen.findByText('You said: What changed today?', inBody)).toBeInTheDocument()
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
    expect(await screen.findByText('Renamed.', inBody)).toBeInTheDocument()
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
    expect(await screen.findByText('old answer', inBody)).toBeInTheDocument()
    act(() => aiKitBus('bit-demo').open({ message: 'Build a survey', newChat: true }))
    expect(await screen.findByText('You said: Build a survey', inBody)).toBeInTheDocument()
    expect(screen.queryByText('old answer', inBody)).not.toBeInTheDocument()
    expect(mock.calls.map(call => call.action)).toContain('assistant/reset')
    expect(onEvent).toHaveBeenCalledWith({ type: 'open', detail: { message: 'Build a survey', newChat: true } })
  })

  it('holds a new-chat request while switching is blocked, then runs it in a new chat', async () => {
    const mock = createMockTransport()
    mock.seed({ id: 'old', title: 'Earlier', messages: [{ role: 'user', text: 'old question' }, { role: 'assistant', text: 'old answer' }] })
    mock.activeId = 'old'
    const view = (blocked?: string) => (
      <AiKitProvider bootstrap={sampleBootstrap()} transport={mock.transport}>
        <AssistantChat switchBlockedReason={blocked} />
      </AiKitProvider>
    )
    const { rerender } = render(view('Apply or discard the preview first.'))
    expect(await screen.findByText('old answer', inBody)).toBeInTheDocument()

    act(() => aiKitBus('bit-demo').open({ message: 'Build a survey', newChat: true }))
    expect(await screen.findByText(/Your request starts a new chat then/)).toBeInTheDocument()
    expect(mock.calls.some(call => call.action === 'assistant/chat' || call.action === 'assistant/reset')).toBe(false)

    rerender(view(undefined))
    expect(await screen.findByText('You said: Build a survey', inBody)).toBeInTheDocument()
    expect(screen.queryByText('old answer', inBody)).not.toBeInTheDocument()
    expect(mock.conversations.get('old')?.messages).toHaveLength(2)
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
