export interface SavedSearch {
  uuid: string
  name: string
  query: string
  filters: FilterState
  sort: string
  created_at: string | null
}

interface SavedSearchList {
  data: SavedSearch[]
}

/**
 * The signed-in user's saved searches. Shared across the app via useState so
 * the header count and the search page see the same list.
 */
export function useSavedSearches() {
  const api = useApi()
  const items = useState<SavedSearch[]>('saved-searches', () => [])
  const loaded = useState<boolean>('saved-searches-loaded', () => false)

  async function load(force = false) {
    if (loaded.value && !force) return

    const response = await api.get<SavedSearchList>('/api/saved-searches')
    items.value = response.data
    loaded.value = true
  }

  async function create(input: { name: string, query: string, filters: FilterState, sort: string }) {
    const created = await api.post<SavedSearch>('/api/saved-searches', input)
    items.value = [created, ...items.value]
    return created
  }

  async function remove(uuid: string) {
    await api.del(`/api/saved-searches/${uuid}`)
    items.value = items.value.filter(s => s.uuid !== uuid)
  }

  return { items, load, create, remove }
}
