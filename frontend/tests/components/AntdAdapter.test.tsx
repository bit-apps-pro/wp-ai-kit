import { render, renderHook, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeAll, describe, expect, it } from 'vitest'
import { antdComponents, useAntdTheme } from '../../src/antd'
import ConversationHistory from '../../src/components/ConversationHistory/ConversationHistory'
import ToolActivity from '../../src/components/AssistantChat/ToolActivity'
import { AiKitProvider } from '../../src/core/context'
import { createMockTransport, sampleBootstrap } from '../mockTransport'

beforeAll(() => {
  // antd's responsive helpers read matchMedia, which jsdom lacks.
  if (!window.matchMedia) {
    window.matchMedia = (query: string) => ({ matches: false, media: query, onchange: null, addListener: () => undefined, removeListener: () => undefined, addEventListener: () => undefined, removeEventListener: () => undefined, dispatchEvent: () => false })
  }
})

describe('antd adapter', () => {
  it('opens a row menu from the keyboard and runs its item', async () => {
    const user = userEvent.setup()
    const mock = createMockTransport()
    mock.seed({ id: 'c1', title: 'Pricing chat' })
    render(
      <AiKitProvider bootstrap={sampleBootstrap()} transport={mock.transport} components={antdComponents}>
        <ConversationHistory />
      </AiKitProvider>,
    )
    await screen.findByText('Pricing chat')

    const trigger = screen.getAllByRole('button', { name: 'Actions for “Pricing chat”' })[0]
    trigger.focus()
    await user.keyboard('{Enter}')
    expect(trigger).toHaveAttribute('aria-expanded', 'true')
    await user.click(await screen.findByText('Pin'))

    await new Promise(resolve => setTimeout(resolve, 0))
    expect(mock.conversations.get('c1')?.context).toMatchObject({ pinned: true })
  })

  it('passes aria-expanded through its Button', async () => {
    const user = userEvent.setup()
    render(
      <AiKitProvider bootstrap={sampleBootstrap()} transport={createMockTransport().transport} components={antdComponents}>
        <ToolActivity tools={[{ name: 'list-forms', title: 'List forms', error: false }]} />
      </AiKitProvider>,
    )
    const details = screen.getByRole('button', { name: 'Details' })
    expect(details).toHaveAttribute('aria-expanded', 'false')
    await user.click(details)
    expect(screen.getByRole('button', { name: 'Hide details' })).toHaveAttribute('aria-expanded', 'true')
  })

  it('maps the antd theme to the kit tokens', () => {
    const { result } = renderHook(() => useAntdTheme())
    const style = result.current as Record<string, string>
    for (const token of ['--aikit-accent', '--aikit-radius', '--aikit-shadow', '--aikit-control-height', '--aikit-font-mono']) {
      expect(style[token], token).toBeTruthy()
    }
  })
})
