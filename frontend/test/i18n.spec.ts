import { describe, expect, it } from 'vitest'
import de from '../i18n/locales/de.json'
import en from '../i18n/locales/en.json'

/**
 * The two language files have to carry the same keys. A missing one does not
 * fail the build — vue-i18n falls back to the key itself and the interface
 * shows something like "help.access.apiDocsLink" to a user. This catches it
 * where it is cheap.
 */
function keysOf(value: unknown, prefix = ''): string[] {
  if (value === null || typeof value !== 'object') return [prefix]

  return Object.entries(value as Record<string, unknown>)
    .flatMap(([key, child]) => keysOf(child, prefix === '' ? key : `${prefix}.${key}`))
}

describe('translations', () => {
  const german = keysOf(de).sort()
  const english = keysOf(en).sort()

  it('has an English string for every German one', () => {
    expect(german.filter(key => !english.includes(key))).toEqual([])
  })

  it('has a German string for every English one', () => {
    expect(english.filter(key => !german.includes(key))).toEqual([])
  })

  it('leaves nothing empty', () => {
    for (const [locale, file] of [['de', de], ['en', en]] as const) {
      const empty = keysOf(file).filter((key) => {
        const value = key.split('.').reduce<unknown>(
          (node, part) => (node as Record<string, unknown>)?.[part], file
        )

        return typeof value === 'string' && value.trim() === ''
      })

      expect(empty, locale).toEqual([])
    }
  })
})
