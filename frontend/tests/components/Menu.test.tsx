import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defaultComponents } from '../../src/components/slots/defaultComponents'

const { Menu } = defaultComponents

function rect(top: number, left: number, width: number, height: number): DOMRect {
  return { top, left, width, height, right: left + width, bottom: top + height, x: left, y: top, toJSON: () => ({}) } as DOMRect
}

/** The trigger sits at `triggerTop`; the list measures 160×120 and fixed starts at the viewport's corner. */
function layout(triggerTop: number) {
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
    if (this.getAttribute('role') === 'menu') return rect(0, 0, 160, 120)
    return rect(triggerTop, 280, 28, 28)
  })
}

async function openMenu() {
  render(<Menu aria-label="Actions" trigger={<span>more</span>} items={[{ key: 'rename', label: 'Rename', onClick: () => {} }]} />)
  await userEvent.setup().click(screen.getByRole('button', { name: 'more' }))
  return screen.getByRole('menu')
}

describe('default Menu', () => {
  afterEach(() => { vi.restoreAllMocks() })

  it('opens under its trigger, right edges aligned, outside any scrolling parent', async () => {
    layout(100)
    const list = await openMenu()
    expect(list.style.top).toBe('132px')
    expect(list.style.left).toBe('148px')
    expect(list.style.visibility).toBe('visible')
  })

  it('opens above its trigger when there is no room below', async () => {
    layout(window.innerHeight - 40)
    const list = await openMenu()
    expect(list.style.top).toBe(`${window.innerHeight - 40 - 4 - 120}px`)
  })
})
