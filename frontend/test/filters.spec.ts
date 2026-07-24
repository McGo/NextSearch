import { describe, expect, it } from 'vitest'
import { countFilters, decodeFilters, encodeFilters } from '../app/utils/filters'

describe('Filterzustand in der URL', () => {
  it('überlebt den Weg durch die URL unverändert', () => {
    const filters = {
      extension: ['pdf', 'docx'],
      folder_label: ['Bürgermeisteramt', 'Größenwahn']
    }

    expect(decodeFilters(encodeFilters(filters))).toEqual(filters)
  })

  it('lässt leere Facetten weg', () => {
    expect(encodeFilters({ extension: [] })).toBeUndefined()
    expect(encodeFilters({})).toBeUndefined()

    const encoded = encodeFilters({ extension: ['pdf'], year: [] })
    expect(decodeFilters(encoded)).toEqual({ extension: ['pdf'] })
  })

  it('kommt mit Unfug in der URL zurecht', () => {
    expect(decodeFilters(undefined)).toEqual({})
    expect(decodeFilters('')).toEqual({})
    expect(decodeFilters('kein-base64!!')).toEqual({})
    expect(decodeFilters(btoa('[1,2,3]'))).toEqual({})
    expect(decodeFilters(btoa('"nur ein string"'))).toEqual({})
  })

  it('verwirft Facetten, deren Werte keine Zeichenketten sind', () => {
    expect(decodeFilters(btoa(JSON.stringify({ extension: ['pdf'], jahr: [2019] }))))
      .toEqual({ extension: ['pdf'] })
  })

  it('zählt über alle Facetten hinweg', () => {
    expect(countFilters({ extension: ['pdf', 'docx'], year: ['2019'] })).toBe(3)
    expect(countFilters({})).toBe(0)
  })
})
