import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { HelpModeProvider, SettingText } from '../../src/components/SettingHelp'
import { AiKitProvider } from '../../src/core/context'
import { createMockTransport, sampleBootstrap } from '../mockTransport'

function renderRow(mode?: 'tooltip' | 'inline') {
  const mock = createMockTransport()
  return render(
    <AiKitProvider bootstrap={sampleBootstrap()} transport={mock.transport}>
      <HelpModeProvider mode={mode}>
        <SettingText label="Model" htmlFor="model" help="Leave empty for the first suggestion." note="Saved." />
        <input id="model" />
      </HelpModeProvider>
    </AiKitProvider>,
  )
}

describe('SettingText', () => {
  it('puts the help behind an info button by default and keeps the note visible', () => {
    renderRow()
    const button = screen.getByRole('button', { name: 'About: Model' })
    expect(button).toHaveAccessibleDescription('Leave empty for the first suggestion.')
    expect(screen.getByText('Saved.')).toBeVisible()
    expect(screen.getByLabelText('Model')).toBe(document.getElementById('model'))

    const bubble = screen.getByRole('tooltip', { hidden: true })
    expect(bubble).not.toHaveClass('aikit-help-tip__bubble--open')
    fireEvent.focus(button)
    expect(bubble).toHaveClass('aikit-help-tip__bubble--open')
    fireEvent.keyDown(button, { key: 'Escape' })
    expect(bubble).not.toHaveClass('aikit-help-tip__bubble--open')
    fireEvent.click(button)
    expect(bubble).toHaveClass('aikit-help-tip__bubble--open')
  })

  it('prints the help under the label in inline mode, without the button', () => {
    renderRow('inline')
    expect(screen.queryByRole('button', { name: 'About: Model' })).toBeNull()
    expect(screen.getByText('Leave empty for the first suggestion.')).toBeInTheDocument()
    expect(screen.getByText('Saved.')).toBeInTheDocument()
  })
})
