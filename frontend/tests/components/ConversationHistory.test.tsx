import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import ConversationHistory, { type ConversationHistoryProps } from '../../src/components/ConversationHistory/ConversationHistory'
import { AiKitProvider } from '../../src/core/context'
import { createMockTransport, sampleBootstrap } from '../mockTransport'

// The <dialog> polyfill for jsdom lives in tests/setup.ts.

const now = Math.floor(Date.now() / 1000)

function setup(props: Partial<ConversationHistoryProps> = {}) {
  const mock = createMockTransport()
  mock.seed({ id: 'old', title: 'Old chat', updated: now - 3 * 86400 })
  mock.seed({ id: 'new', title: 'New chat about pricing', updated: now - 300 })
  mock.seed({ id: 'pinned', title: 'Pinned chat', updated: now - 10 * 86400, context: { pinned: true } })
  render(
    <AiKitProvider bootstrap={sampleBootstrap()} transport={mock.transport}>
      <ConversationHistory {...props} />
    </AiKitProvider>,
  )
  return mock
}

const titles = (list: HTMLElement) => within(list).getAllByRole('listitem').map(li => li.querySelector('.aikit-history__title')?.textContent)

async function liveList() {
  await screen.findByText('Old chat')
  return screen.getAllByRole('list')[0]
}

async function openMenu(user: ReturnType<typeof userEvent.setup>, title: string) {
  await user.click(screen.getByRole('button', { name: `Actions for “${title}”` }))
}

describe('ConversationHistory', () => {
  it('lists pinned first, then newest first, with relative times and host meta', async () => {
    setup({ renderMeta: item => (item.id === 'new' ? 'Pricing page' : null) })
    const list = await liveList()
    expect(titles(list)).toEqual(['Pinned chat', 'New chat about pricing', 'Old chat'])
    expect(screen.getByText(/5 minutes ago/)).toBeInTheDocument()
    expect(screen.getByText(/Pricing page/)).toBeInTheDocument()
  })

  it('shows the last list at once when the popover opens again and refreshes it behind', async () => {
    const mock = createMockTransport({ latency: 30 })
    mock.seed({ id: 'old', title: 'Old chat', updated: now - 3 * 86400 })
    const view = (open: boolean) => (
      <AiKitProvider bootstrap={sampleBootstrap()} transport={mock.transport}>
        <ConversationHistory anchor="popover" open={open} onClose={() => undefined} />
      </AiKitProvider>
    )
    const { rerender, container } = render(view(true))
    await screen.findByText('Old chat')

    rerender(view(false))
    mock.seed({ id: 'new', title: 'Newer chat', updated: now - 60 })
    rerender(view(true))

    expect(screen.getByText('Old chat')).toBeInTheDocument()
    expect(container.querySelector('.aikit-history__loading')).toBeNull()
    expect(await screen.findByText('Newer chat')).toBeInTheDocument()
    expect(mock.calls.filter(call => call.action === 'assistant/history' && !call.payload.archived)).toHaveLength(2)
  })

  it('renames a chat from its menu', async () => {
    const user = userEvent.setup()
    const mock = setup()
    await liveList()
    await openMenu(user, 'Old chat')
    await user.click(screen.getByRole('menuitem', { name: 'Rename' }))
    const field = screen.getByRole('textbox', { name: 'Chat title' })
    await user.clear(field)
    await user.type(field, 'Renamed chat{Enter}')
    expect(await screen.findByText('Renamed chat')).toBeInTheDocument()
    expect(mock.conversations.get('old')?.title).toBe('Renamed chat')
  })

  it('Escape cancels a rename', async () => {
    const user = userEvent.setup()
    const mock = setup()
    await liveList()
    await openMenu(user, 'Old chat')
    await user.click(screen.getByRole('menuitem', { name: 'Rename' }))
    await user.type(screen.getByRole('textbox', { name: 'Chat title' }), ' extra{Escape}')
    expect(screen.queryByRole('textbox', { name: 'Chat title' })).not.toBeInTheDocument()
    expect(mock.conversations.get('old')?.title).toBe('Old chat')
  })

  it('archiving moves a chat to the Archived section, which shows only while it has chats', async () => {
    const user = userEvent.setup()
    setup()
    await liveList()
    expect(screen.queryByRole('button', { name: /^Archived/ })).not.toBeInTheDocument()

    await openMenu(user, 'Old chat')
    await user.click(screen.getByRole('menuitem', { name: 'Archive' }))
    const toggle = await screen.findByRole('button', { name: 'Archived (1)' })
    // One scroll: Archived sits in it after the live chats, its heading stuck to the bottom while closed.
    const head = toggle.closest('.aikit-history__archived') as HTMLElement
    expect(head.parentElement).toHaveClass('aikit-history__body')
    expect(head).not.toHaveClass('aikit-history__archived--open')
    await user.click(toggle)
    expect(head).toHaveClass('aikit-history__archived--open')
    const [live, archived] = screen.getAllByRole('list')
    await waitFor(() => expect(titles(live)).toEqual(['Pinned chat', 'New chat about pricing']))
    await waitFor(() => expect(titles(archived)).toEqual(['Old chat']))
    expect(live.parentElement).toBe(head.parentElement)
    expect(archived.parentElement).toBe(head.parentElement)
    expect(head.parentElement?.nextElementSibling).toHaveClass('aikit-history__footer')

    await openMenu(user, 'Old chat')
    await user.click(screen.getByRole('menuitem', { name: 'Unarchive' }))
    await waitFor(() => expect(titles(screen.getAllByRole('list')[0])).toContain('Old chat'))
    await waitFor(() => expect(screen.queryByRole('button', { name: /^Archived/ })).not.toBeInTheDocument())
  })

  it('pins a chat to the top', async () => {
    const user = userEvent.setup()
    setup()
    await liveList()
    await openMenu(user, 'Old chat')
    await user.click(screen.getByRole('menuitem', { name: 'Pin' }))
    await openMenu(user, 'Old chat')
    expect(screen.getByRole('menuitem', { name: 'Unpin' })).toBeInTheDocument()
  })

  it('deletes one chat after confirming, and all chats from the footer', async () => {
    const user = userEvent.setup()
    const mock = setup()
    await liveList()
    await openMenu(user, 'Old chat')
    await user.click(screen.getByRole('menuitem', { name: 'Delete' }))
    expect(screen.getByText('“Old chat” is deleted for good. This cannot be undone.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(screen.queryByText('Old chat')).not.toBeInTheDocument())
    expect(mock.conversations.has('old')).toBe(false)

    await user.click(screen.getByRole('button', { name: 'Delete all chats' }))
    await user.click(screen.getByRole('button', { name: 'Delete all' }))
    expect(await screen.findByText('No earlier chats yet.')).toBeInTheDocument()
    expect(mock.conversations.size).toBe(0)
  })

  it('opens a chat, marks it current and calls onOpen', async () => {
    const user = userEvent.setup()
    const onOpen = vi.fn()
    setup({ onOpen })
    await liveList()
    await user.click(screen.getByRole('button', { name: /^Old chat/ }))
    await waitFor(() => expect(onOpen).toHaveBeenCalledWith('old'))
    expect(screen.getByText('Old chat').closest('li')).toHaveAttribute('aria-current', 'true')
  })

  it('searches the list', async () => {
    const user = userEvent.setup()
    setup()
    await liveList()
    await user.type(screen.getByRole('searchbox', { name: 'Search chats' }), 'pricing')
    await waitFor(() => expect(screen.queryByText('Old chat')).not.toBeInTheDocument(), { timeout: 2000 })
    expect(screen.getByText('New chat about pricing')).toBeInTheDocument()
  })
})
