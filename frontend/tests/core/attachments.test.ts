import { createAiKitStore, type AiKitStore } from '../../src/core/store'
import type { AttachmentRules } from '../../src/core/types'
import { AiKitError } from '../../src/core/transport'
import { createMockTransport, sampleBootstrap, type MockOptions, type MockTransport } from '../mockTransport'

const RULES: AttachmentRules = {
  accept: '.png,.pdf,.txt',
  kinds: ['image', 'pdf', 'text'],
  maxFiles: 2,
  maxTotalBytes: 3000,
  maxBytes: { image: 2000, pdf: 2000, text: 1000 },
  ttlHours: 24,
}

const file = (name: string, size = 100): File => new File(['x'.repeat(size)], name)

function setup(options: MockOptions = {}, rules: AttachmentRules | null = RULES): { mock: MockTransport, store: AiKitStore } {
  const mock = createMockTransport(options)
  const bootstrap = sampleBootstrap({ attachments: rules, features: { ...sampleBootstrap().features, attachments: true } })
  return { mock, store: createAiKitStore(mock.transport, bootstrap) }
}

const settle = () => new Promise(resolve => setTimeout(resolve, 0))

describe('AiKitStore: attachments', () => {
  it('uploads a picked file and marks it ready', async () => {
    const { store, mock } = setup()
    store.attach([file('notes.txt')])

    expect(store.getState().assistant.attachments[0]).toMatchObject({ name: 'notes.txt', kind: 'text', status: 'uploading' })
    await settle()

    expect(store.getState().assistant.attachments[0]).toMatchObject({ status: 'ready', progress: 1, id: 'att1' })
    expect(mock.uploads.has('att1')).toBe(true)
  })

  it('refuses files the rules do not allow, without uploading them', async () => {
    const { store, mock } = setup()
    store.attach([file('script.exe'), file('big.txt', 1500), file('a.png'), file('b.png'), file('c.png')])
    await settle()

    const errors = store.getState().assistant.attachments.map(item => item.error || '')
    expect(errors[0]).toMatch(/type cannot be attached/)
    expect(errors[1]).toMatch(/too large/)
    expect(errors[2]).toBe('')
    expect(errors[3]).toBe('')
    expect(errors[4]).toMatch(/at most 2 files/)
    expect(mock.uploads.size).toBe(2)
  })

  it('refuses every file when the host turned attachments off', () => {
    const { store } = setup({}, null)
    store.attach([file('notes.txt')])
    expect(store.getState().assistant.attachments[0]).toMatchObject({ status: 'error', error: 'Files cannot be attached here.' })
  })

  it('shows a failed upload as an error on its chip', async () => {
    const { store } = setup({ uploadError: new AiKitError('Disk full.', 'attachment_store', 500, null) })
    store.attach([file('notes.txt')])
    await settle()
    expect(store.getState().assistant.attachments[0]).toMatchObject({ status: 'error', error: 'Disk full.' })
  })

  it('holds the message while a file uploads, then sends the ids and clears the tray', async () => {
    const { store, mock } = setup({ latency: 5 })
    store.attach([file('notes.txt'), file('script.exe')])

    expect(await store.send('read this')).toBeNull()
    await new Promise(resolve => setTimeout(resolve, 10))

    const view = await store.send('')
    const chat = mock.calls.find(call => call.action === 'assistant/chat')
    expect(chat?.payload.attachments).toEqual(['att1'])
    expect(view?.messages[0].attachments).toEqual([{ id: 'att1', name: 'notes.txt', kind: 'text', size: 100 }])
    expect(store.getState().assistant.attachments).toEqual([])
  })

  it('deletes an uploaded file on the site when it is removed', async () => {
    const { store, mock } = setup()
    store.attach([file('notes.txt')])
    await settle()

    store.detach(store.getState().assistant.attachments[0].localId)
    await settle()

    expect(store.getState().assistant.attachments).toEqual([])
    expect(mock.calls.some(call => call.action === 'assistant/attachments/delete' && call.payload.id === 'att1')).toBe(true)
    expect(mock.uploads.size).toBe(0)
  })
})
