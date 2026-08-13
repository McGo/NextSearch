import { describe, expect, it } from 'vitest'
import { useTags } from '../app/composables/useTags'

const { label } = useTags()

describe('Tag-Beschriftung', () => {
  it('nimmt den vergebenen Namen, wenn es einen gibt', () => {
    expect(label({ name: 'Vertrag', slug: 'vertrag', is_system: false })).toBe('Vertrag')
  })

  it('übersetzt einen Systemtag ohne eigenen Namen', () => {
    // "Neu" steht nirgends in der Datenbank — es kommt aus der Sprachdatei und
    // folgt damit der Oberflächensprache.
    expect(label({ name: null, slug: 'new', is_system: true })).toBe('Neu')
  })

  it('lässt einen vom Admin umbenannten Systemtag stehen', () => {
    expect(label({ name: 'Frisch', slug: 'new', is_system: true })).toBe('Frisch')
  })

  it('fällt bei einem namenlosen Nicht-Systemtag auf die Kennung zurück', () => {
    // Sollte nicht vorkommen — aber ein leerer Chip wäre schlimmer.
    expect(label({ name: null, slug: 'irgendwas', is_system: false })).toBe('irgendwas')
  })
})
