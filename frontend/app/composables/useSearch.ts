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

// Sort values in display order. Labels come from the locale files
// (search.sort.<value>), built where the select is rendered.
export const SORT_VALUES = ['relevance', 'newest', 'oldest', 'largest', 'name'] as const

/**
 * Holds the query, filters, sort and page — and mirrors them into the URL so a
 * search stays shareable and reachable via the back button.
 */
export function useSearch() {
  const api = useApi()
  const route = useRoute()
  const router = useRouter()
  const { t } = useI18n()

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
      // Only send what is set. Laravel turns empty params into null, and the
      // facets travel as a JSON string because nested objects don't serialize
      // reliably into a query string.
      const params: Record<string, string | number> = {
        sort: sort.value,
        page: page.value
      }
      if (query.value) params.q = query.value
      if (countFilters(filters.value) > 0) params.filters = JSON.stringify(filters.value)

      result.value = await api.get<SearchResult>('/api/search', params)
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : t('search.failed')
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

  // Typing shouldn't fire a request per keystroke; filters and sort act at once.
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
