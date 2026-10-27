import { createI18n, defaultI18n, sprintf } from '../../src/core/i18n'

describe('sprintf', () => {
  it('substitutes %s and %d in order', () => {
    expect(sprintf('%s has %d items', 'Inbox', 3)).toBe('Inbox has 3 items')
  })

  it('substitutes positional arguments', () => {
    expect(sprintf('%2$s before %1$s', 'one', 'two')).toBe('two before one')
  })

  it('mixes positional and sequential placeholders', () => {
    expect(sprintf('%1$s %s %d', 'a', 'b', 7)).toBe('a a b')
  })

  it('drops a placeholder without a value', () => {
    expect(sprintf('%s and %s', 'only')).toBe('only and ')
  })

  it('leaves other percent signs alone', () => {
    expect(sprintf('100% of %d', 5)).toBe('100% of 5')
  })
})

describe('createI18n', () => {
  it('formats a translated string without translating it twice', () => {
    const translate = vi.fn((text: string) => (text === '%d min ago' ? 'il y a %d min' : text))
    const i18n = createI18n(translate)
    expect(i18n.sprintf(i18n.__('%d min ago'), 4)).toBe('il y a 4 min')
    expect(translate).toHaveBeenCalledTimes(1)
  })

  it('defaults to the identity', () => {
    expect(defaultI18n.__('Hello')).toBe('Hello')
    expect(defaultI18n.sprintf('%s!', 'Hi')).toBe('Hi!')
  })
})
