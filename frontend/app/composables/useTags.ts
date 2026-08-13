export type TagScope = 'global' | 'personal'
export type TagAssignPolicy = 'everyone' | 'admin'

/** Colours a tag may take. Mirrors Tag::COLORS in the backend. */
export const TAG_COLORS = ['neutral', 'primary', 'secondary', 'success', 'info', 'warning', 'error'] as const

/**
 * The union rather than plain string: the interface passes it straight to
 * Nuxt UI's `color` prop, which only takes these.
 */
export type TagColor = typeof TAG_COLORS[number]

export interface Tag {
  uuid: string
  slug: string
  /** Null for system tags — those are translated from the slug. */
  name: string | null
  color: TagColor
  description: string | null
  scope: TagScope
  assign_policy: TagAssignPolicy
  is_system: boolean
  /** True for a tag its owner created for themselves. */
  is_private: boolean
  enabled: boolean
  position: number
  auto_rule: string | null
  auto_duration_minutes: number | null
}

/** A tag as it hangs on one document. */
export interface DocumentTag {
  uuid: string
  slug: string
  name: string | null
  color: TagColor
  scope: TagScope
  is_system: boolean
  source: 'manual' | 'auto'
  expires_at: string | null
}

export interface TagInput {
  name: string
  color?: TagColor
  description?: string | null
  owner?: 'me' | 'instance'
  scope?: TagScope
  assign_policy?: TagAssignPolicy
  position?: number
  enabled?: boolean
  auto_rule?: string | null
  auto_duration_minutes?: number | null
}

/**
 * The tag vocabulary this user may work with — the instance tags plus their
 * own. Held in useState so the picker, the facets and the admin page all read
 * from the same list.
 */
export function useTags() {
  const api = useApi()
  const { t } = useI18n()

  const items = useState<Tag[]>('tags', () => [])
  const loaded = useState<boolean>('tags-loaded', () => false)

  async function load(force = false) {
    if (loaded.value && !force) return

    const response = await api.get<{ data: Tag[] }>('/api/tags')
    items.value = response.data
    loaded.value = true
  }

  /** Administrators also see what is switched off. */
  async function loadAll() {
    const response = await api.get<{ data: Tag[] }>('/api/tags', { include_disabled: 1 })
    items.value = response.data
    loaded.value = true
    return response.data
  }

  async function create(input: TagInput) {
    const created = await api.post<Tag>('/api/tags', input)
    items.value = [...items.value, created]
    return created
  }

  async function update(uuid: string, input: Partial<TagInput>) {
    const updated = await api.patch<Tag>(`/api/tags/${uuid}`, input)
    items.value = items.value.map(tag => (tag.uuid === uuid ? updated : tag))
    return updated
  }

  async function remove(uuid: string) {
    await api.del(`/api/tags/${uuid}`)
    items.value = items.value.filter(tag => tag.uuid !== uuid)
  }

  // --- Assignments on a document --------------------------------------------

  async function tagsOf(documentUuid: string) {
    const response = await api.get<{ data: DocumentTag[] }>(`/api/documents/${documentUuid}/tags`)
    return response.data
  }

  async function attach(documentUuid: string, tags: string[]) {
    const response = await api.post<{ data: DocumentTag[] }>(`/api/documents/${documentUuid}/tags`, { tags })
    return response.data
  }

  async function detach(documentUuid: string, tag: string) {
    const response = await api.del<{ data: DocumentTag[] }>(`/api/documents/${documentUuid}/tags/${tag}`)
    return response.data
  }

  /**
   * What a tag is called. System tags carry no name of their own until an
   * administrator gives them one — until then the label follows the interface
   * language.
   */
  function label(tag: { name: string | null, slug: string, is_system: boolean }): string {
    if (tag.name) return tag.name
    if (tag.is_system) return t(`tags.system.${tag.slug}`)
    return tag.slug
  }

  return { items, load, loadAll, create, update, remove, tagsOf, attach, detach, label }
}
