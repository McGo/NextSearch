import { describe, expect, it } from 'vitest'
import { useFormat } from '../app/composables/useFormat'

const { bytes, date, icon, facetValue } = useFormat()

describe('Formatierung', () => {
  it('rundet Dateigrößen lesbar', () => {
    expect(bytes(512)).toBe('512 B')
    expect(bytes(1024)).toBe('1,0 KB')
    // Ab zehn Einheiten entfällt die Nachkommastelle — sie sagt dort nichts mehr.
    expect(bytes(48213)).toBe('47 KB')
    expect(bytes(5 * 1024 * 1024)).toBe('5,0 MB')
    expect(bytes(1024 * 1024 * 1024)).toBe('1,0 GB')
  })

  it('deutet Zeitstempel als Sekunden und ISO-Zeichenketten', () => {
    expect(date(Date.UTC(2019, 2, 14) / 1000)).toBe('14.03.2019')
    expect(date('2019-03-14T10:00:00Z')).toBe('14.03.2019')
    expect(date(null)).toBe('—')
  })

  it('findet ein Symbol zum Dateityp und fällt sonst zurück', () => {
    expect(icon('pdf')).toBe('i-lucide-file-text')
    expect(icon('eml')).toBe('i-lucide-mail')
    expect(icon('exotisch')).toBe('i-lucide-file')
    expect(icon(null)).toBe('i-lucide-file')
  })

  it('macht aus dem OCR-Wahrheitswert eine lesbare Facette', () => {
    expect(facetValue('ocr_used', 'true')).toBe('per OCR erkannt')
    expect(facetValue('ocr_used', 'false')).toBe('aus Textlayer')
    expect(facetValue('extension', 'pdf')).toBe('pdf')
  })
})
