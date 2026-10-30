import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import type { DraftAttachment } from '../../src/core/store'
import PromptComposer, { type CommandsProvider, type MentionsProvider, type PromptComposerProps } from '../../src/components/PromptComposer/PromptComposer'
import { AiKitProvider } from '../../src/core/context'
import { createMockTransport, sampleBootstrap } from '../mockTransport'

function renderComposer(props: Partial<PromptComposerProps> = {}) {
  const mock = createMockTransport()
  const onSubmit = props.onSubmit || vi.fn()
  render(
    <AiKitProvider bootstrap={sampleBootstrap()} transport={mock.transport}>
      <PromptComposer onSubmit={onSubmit} {...props} />
    </AiKitProvider>,
  )
  return { onSubmit, input: screen.getByRole('textbox', { name: 'Message' }) as HTMLTextAreaElement }
}

const mentions: MentionsProvider = {
  heading: 'Mention a record',
  search: () => [
    { id: 'r1', label: 'Contact', hint: 'record #1', mention: { type: 'record', id: 1 } },
    { id: 'r2', label: 'Newsletter', hint: 'record #2', mention: { type: 'record', id: 2 } },
  ],
}

describe('PromptComposer', () => {
  it('sends on Enter and adds a new line on Shift+Enter', async () => {
    const user = userEvent.setup()
    const { onSubmit, input } = renderComposer()
    await user.type(input, 'first{Shift>}{Enter}{/Shift}second')
    expect(input.value).toBe('first\nsecond')
    expect(onSubmit).not.toHaveBeenCalled()
    await user.keyboard('{Enter}')
    expect(onSubmit).toHaveBeenCalledWith('first\nsecond', { mentions: [] })
  })

  it('does not send on the Enter that ends an input method composition', async () => {
    const user = userEvent.setup()
    const { onSubmit, input } = renderComposer()
    await user.type(input, 'こんにちは')
    fireEvent.keyDown(input, { key: 'Enter', isComposing: true })
    fireEvent.keyDown(input, { key: 'Enter', keyCode: 229 })
    expect(onSubmit).not.toHaveBeenCalled()
    fireEvent.keyDown(input, { key: 'Enter' })
    expect(onSubmit).toHaveBeenCalledWith('こんにちは', { mentions: [] })
  })

  it('points screen readers at the highlighted row of the @ menu', async () => {
    const user = userEvent.setup()
    const { input } = renderComposer({ mentions })
    await user.type(input, '@')
    const rows = await screen.findAllByRole('option')
    expect(input).toHaveAttribute('aria-activedescendant', rows[0].id)
    await user.keyboard('{ArrowDown}')
    expect(input).toHaveAttribute('aria-activedescendant', rows[1].id)
  })

  it('does not send while busy or empty', async () => {
    const user = userEvent.setup()
    const { onSubmit, input } = renderComposer({ busy: true })
    await user.type(input, 'hello{Enter}')
    expect(onSubmit).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'Send' })).toBeDisabled()
  })

  it('opens the @ menu from the provider and sends the picked mention', async () => {
    const user = userEvent.setup()
    const { onSubmit, input } = renderComposer({ mentions })
    await user.type(input, 'Look at @news')
    const listbox = await screen.findByRole('listbox', { name: 'Mention' })
    expect(listbox).toHaveTextContent('Mention a record')
    await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(1))
    await user.click(screen.getByRole('option', { name: /@Newsletter/ }))
    expect(input.value).toBe('Look at @Newsletter ')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    await user.type(input, 'please{Enter}')
    expect(onSubmit).toHaveBeenCalledWith('Look at @Newsletter please', { mentions: [{ type: 'record', id: 2 }] })
  })

  it('drops a mention whose token was deleted before sending', async () => {
    const user = userEvent.setup()
    function Controlled({ onSubmit }: { onSubmit: PromptComposerProps['onSubmit'] }) {
      const [value, setValue] = useState('')
      return <PromptComposer value={value} onChange={setValue} onSubmit={onSubmit} mentions={mentions} />
    }
    const onSubmit = vi.fn()
    render(
      <AiKitProvider bootstrap={sampleBootstrap()} transport={createMockTransport().transport}>
        <Controlled onSubmit={onSubmit} />
      </AiKitProvider>,
    )
    const input = screen.getByRole('textbox', { name: 'Message' })
    await user.type(input, '@Con')
    await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(1))
    await user.keyboard('{Enter}')
    expect(input).toHaveValue('@Contact ')
    await user.clear(input)
    await user.type(input, 'nothing here{Enter}')
    expect(onSubmit).toHaveBeenCalledWith('nothing here', { mentions: [] })
  })

  it('lists / commands; a prompt fills the input and a run command executes', async () => {
    const user = userEvent.setup()
    const run = vi.fn()
    const commands: CommandsProvider = {
      items: [
        { id: 'summary', label: 'Summarise', prompt: 'Summarise this page.' },
        { id: 'clear', label: 'Clear everything', run },
      ],
    }
    const { input } = renderComposer({ commands })
    await user.type(input, '/')
    expect(await screen.findByRole('listbox', { name: 'Commands' })).toBeInTheDocument()
    expect(screen.getAllByRole('option')).toHaveLength(2)
    await user.type(input, 'sum')
    expect(screen.getAllByRole('option')).toHaveLength(1)
    await user.keyboard('{Enter}')
    expect(input.value).toBe('Summarise this page.')

    await user.clear(input)
    await user.type(input, '/cl')
    await user.click(screen.getByRole('option', { name: /\/clear/ }))
    expect(run).toHaveBeenCalledTimes(1)
    expect(input.value).toBe('')
  })

  it('a / in the middle of the text opens no menu', async () => {
    const user = userEvent.setup()
    const { input } = renderComposer({ commands: { items: [{ id: 'x', label: 'X', prompt: 'x' }] } })
    await user.type(input, 'see https://a/b')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    expect(screen.getByText('/ commands · Shift+Enter new line')).toBeInTheDocument()
  })
})

describe('PromptComposer: attachments', () => {
  const draft = (patch: Partial<DraftAttachment>): DraftAttachment => ({ localId: 'l1', name: 'notes.txt', size: 2048, kind: 'text', status: 'ready', progress: 1, id: 'att1', ...patch })

  it('has no attach button unless the host passes attachments', () => {
    renderComposer()
    expect(screen.queryByRole('button', { name: 'Attach files' })).toBeNull()
  })

  it('hands picked files to the host and shows each chip with its state', async () => {
    const user = userEvent.setup()
    const onAdd = vi.fn()
    const onRemove = vi.fn()
    const items = [draft({}), draft({ localId: 'l2', name: 'scan.pdf', kind: 'pdf', status: 'error', error: 'The file is too large.', id: undefined })]
    const { container } = render(
      <AiKitProvider bootstrap={sampleBootstrap()} transport={createMockTransport().transport}>
        <PromptComposer onSubmit={vi.fn()} attachments={{ items, accept: '.txt,.pdf', onAdd, onRemove, note: 'Deleted after 24 hours.' }} />
      </AiKitProvider>,
    )

    expect(screen.getByText('2 KB')).toBeTruthy()
    expect(screen.getByText('The file is too large.')).toBeTruthy()
    expect(screen.getByText('Deleted after 24 hours.')).toBeTruthy()

    const input = container.querySelector('input[type="file"]') as HTMLInputElement
    expect(input.accept).toBe('.txt,.pdf')
    await user.upload(input, new File(['a'], 'more.txt'))
    expect(onAdd).toHaveBeenCalledWith([expect.objectContaining({ name: 'more.txt' })])

    await user.click(screen.getByRole('button', { name: 'Remove scan.pdf' }))
    expect(onRemove).toHaveBeenCalledWith('l2')
  })

  it('sends with only files, but not while one uploads', async () => {
    const user = userEvent.setup()
    const onSubmit = vi.fn()
    const attachments = { accept: '.txt', onAdd: vi.fn(), onRemove: vi.fn() }
    const { rerender } = render(
      <AiKitProvider bootstrap={sampleBootstrap()} transport={createMockTransport().transport}>
        <PromptComposer onSubmit={onSubmit} attachments={{ ...attachments, items: [draft({ status: 'uploading', progress: 0.4, id: undefined })] }} />
      </AiKitProvider>,
    )
    expect((screen.getByRole('button', { name: 'Send' }) as HTMLButtonElement).disabled).toBe(true)

    rerender(
      <AiKitProvider bootstrap={sampleBootstrap()} transport={createMockTransport().transport}>
        <PromptComposer onSubmit={onSubmit} attachments={{ ...attachments, items: [draft({})] }} />
      </AiKitProvider>,
    )
    await user.click(screen.getByRole('button', { name: 'Send' }))
    expect(onSubmit).toHaveBeenCalledWith('', { mentions: [] })
  })
})
