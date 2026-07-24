export type FilterState = Record<string, string[]>

/**
 * The filter state lives in the URL so a search is shareable and the back
 * button works. JSON in base64 is ugly but robust — the alternative would be
 * nested query parameters with a parser of their own.
 */
export function encodeFilters(filters: FilterState): string | undefined {
  const cleaned = Object.fromEntries(
    Object.entries(filters).filter(([, values]) => values.length > 0)
  )

  if (Object.keys(cleaned).length === 0) return undefined

  // btoa only handles Latin-1; non-ASCII in folder names is the norm.
  return btoa(unescape(encodeURIComponent(JSON.stringify(cleaned))))
}

/** Broken or stale URL values yield no filter rather than an error. */
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
