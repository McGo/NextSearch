export interface SearchHit {
  uuid: string
  name: string
  path: string
  directory: string
  extension: string
  mime_type: string | null
  size: number
  size_bucket: string
  modified_at: number | null
  year: number | null
  author: string | null
  title: string | null
  page_count: number | null
  instance_name: string
  folder_label: string
  folder_id: number
  ocr_used: boolean
  has_preview: boolean
  highlighted_name: string
  snippet: string | null
}

export interface Facet {
  name: string
  values: { value: string, count: number }[]
}

export interface SearchResult {
  hits: SearchHit[]
  total: number
  page: number
  per_page: number
  total_pages: number
  facets: Facet[]
  took_ms: number | null
}

export const FACET_LABELS: Record<string, string> = {
  instance_name: 'Instanz',
  folder_label: 'Ordner',
  extension: 'Dateityp',
  year: 'Jahr',
  size_bucket: 'Größe',
  ocr_used: 'Texterkennung'
}

export const SORT_OPTIONS = [
  { value: 'relevance', label: 'Relevanz' },
  { value: 'newest', label: 'Neueste zuerst' },
  { value: 'oldest', label: 'Älteste zuerst' },
  { value: 'largest', label: 'Größte zuerst' },
  { value: 'name', label: 'Name' }
]

/**
 * Hält Suchbegriff, Filter, Sortierung und Seite — und spiegelt sie in die URL,
 * damit eine Suche teilbar und über den Zurück-Knopf erreichbar bleibt.
 */
export function useSearch() {
  const api = useApi()
  const route = useRoute()
  const router = useRouter()

  const query = ref((route.query.q as string) || '')
  const sort = ref((route.query.sort as string) || 'relevance')
  const page = ref(Number(route.query.page) || 1)
  const filters = ref<FilterState>(decodeFilters(route.query.f as string | undefined))

  const result = ref<SearchResult | null>(null)
  const pending = ref(false)
  const error = ref<string | null>(null)

  function syncUrl() {
    const encoded = encodeFilters(filters.value)

    router.replace({
      query: {
        ...(query.value ? { q: query.value } : {}),
        ...(sort.value !== 'relevance' ? { sort: sort.value } : {}),
        ...(page.value > 1 ? { page: String(page.value) } : {}),
        ...(encoded ? { f: encoded } : {})
      }
    })
  }

  async function run() {
    pending.value = true
    error.value = null

    try {
      result.value = await api.get<SearchResult>('/api/search', {
        q: query.value,
        sort: sort.value,
        page: page.value,
        filters: filters.value
      })
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Die Suche ist fehlgeschlagen.'
      result.value = null
    } finally {
      pending.value = false
    }
  }

  function toggleFilter(facet: string, value: string) {
    const current = filters.value[facet] ?? []
    const next = current.includes(value)
      ? current.filter(v => v !== value)
      : [...current, value]

    filters.value = { ...filters.value, [facet]: next }
    page.value = 1
  }

  function clearFilters() {
    filters.value = {}
    page.value = 1
  }

  const activeFilterCount = computed(() => countFilters(filters.value))

  // Tippen soll nicht jede Taste zum Backend schicken; Filter und Sortierung
  // dagegen wirken sofort.
  let debounce: ReturnType<typeof setTimeout> | undefined

  watch(query, () => {
    page.value = 1
    clearTimeout(debounce)
    debounce = setTimeout(() => {
      syncUrl()
      run()
    }, 250)
  })

  watch([filters, sort, page], () => {
    syncUrl()
    run()
  }, { deep: true })

  return {
    query,
    sort,
    page,
    filters,
    result,
    pending,
    error,
    activeFilterCount,
    run,
    toggleFilter,
    clearFilters
  }
}
