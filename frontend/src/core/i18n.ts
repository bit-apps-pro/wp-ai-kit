/**
 * Translation is the host's: it passes `{ __ }` to the provider (Bit Form:
 * `s => __(s, 'bit-form')`). The package only has English source strings;
 * `i18n/strings.en.json` lists them for the host's POT pipeline.
 */
export interface I18n {
  __: (text: string) => string
  /**
   * `%s` / `%d` and positional `%1$s` substitution, as WordPress's sprintf. It does
   * not translate: pass `__('…')` as the format, which is the form the string
   * extractor finds (`sprintf(__('%d chats'), n)`).
   */
  sprintf: (text: string, ...values: (string | number)[]) => string
}

export function sprintf(text: string, ...values: (string | number)[]): string {
  let next = 0
  return text.replace(/%(?:(\d+)\$)?[sd]/g, (_match, position?: string) => {
    const index = position ? Number(position) - 1 : next++
    const value = values[index]
    return value === undefined ? '' : String(value)
  })
}

export function createI18n(translate?: (text: string) => string): I18n {
  const __ = translate || ((text: string) => text)
  return { __, sprintf }
}

export const defaultI18n: I18n = createI18n()
