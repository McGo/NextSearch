export type FilterState = Record<string, string[]>

/**
 * Der Filterzustand steht in der URL, damit eine Suche teilbar ist und der
 * Zurück-Knopf funktioniert. JSON in base64 ist unhübsch, aber robust — die
 * Alternative wären verschachtelte Query-Parameter mit eigenem Parser.
 */
export function encodeFilters(filters: FilterState): string | undefined {
  const cleaned = Object.fromEntries(
    Object.entries(filters).filter(([, values]) => values.length > 0)
  )

  if (Object.keys(cleaned).length === 0) return undefined

  // btoa kann nur Latin-1; Umlaute in Ordnernamen sind der Normalfall.
  return btoa(unescape(encodeURIComponent(JSON.stringify(cleaned))))
}

/** Kaputte oder veraltete Werte in der URL führen zu keinem Filter, nicht zu einem Fehler. */
export function decodeFilters(raw?: string | null): FilterState {
  if (!raw) return {}

  try {
    const parsed = JSON.parse(decodeURIComponent(escape(atob(raw))))

    if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) return {}

    return Object.fromEntries(
      Object.entries(parsed as Record<string, unknown>)
        .filter(([, values]) => Array.isArray(values) && values.every(v => typeof v === 'string'))
    ) as FilterState
  } catch {
    return {}
  }
}

export function countFilters(filters: FilterState): number {
  return Object.values(filters).reduce((sum, values) => sum + values.length, 0)
}
