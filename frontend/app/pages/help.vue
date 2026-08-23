<script setup lang="ts">
/**
 * The help page.
 *
 * Everything with a number or a name in it comes from /api/help, so the page
 * describes this installation rather than NextSearch in general — which folders
 * are connected, which formats are read, what the tags here mean. The prose
 * around it sits in the translation files.
 *
 * What a reader gets depends on their account: the administration section shows
 * up for administrators, and every count is already cut to the folders the
 * reader may see.
 */
interface HelpFolder {
  label: string
  instance: string | null
  interval_minutes: number
  enabled: boolean
}

interface HelpTag {
  slug: string
  name: string | null
  color: string
  description: string | null
  scope: 'global' | 'personal'
  private: boolean
  system: boolean
  assignable: boolean
  auto_rule: string | null
  auto_duration_minutes: number | null
}

interface Help {
  installation: {
    version: string
    instances: number
    folders: number
    documents: number
    last_indexed_at: string | null
    pending: number
  }
  search: {
    extensions: string[]
    max_file_size_mb: number
    ocr: { enabled: boolean, languages: string }
    preview: { enabled: boolean, office: boolean }
    per_page: number
    facets: string[]
    sorts: string[]
  }
  folders: HelpFolder[]
  tags: HelpTag[]
  access: {
    is_admin: boolean
    api: { rate_limit: number }
    mcp: { enabled: boolean, url: string | null, oauth: boolean, tools: string[] }
  }
  admin?: {
    instances: { name: string, host: string, folders: number }[]
    default_interval_minutes: number
    users: number
    admins: number
    documents_total: number
    documents_failed: number
    documents_skipped: number
  }
}

const { t, n } = useI18n()
const api = useApi()
const config = useRuntimeConfig()
const { user } = useAuth()
const { dateTime } = useFormat()

/** A file in the repository — the API and MCP docs are not in the app. */
const doc = (path: string) => `${config.public.repoUrl}/blob/main/${path}`

useHead({ title: () => t('help.title') })

const help = ref<Help | null>(null)
const pending = ref(true)
const error = ref<string | null>(null)

onMounted(async () => {
  try {
    help.value = await api.get<Help>('/api/help')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.requestFailed')
  } finally {
    pending.value = false
  }
})

// Sections in reading order. The administration one only exists for those who
// can act on it.
const sections = computed(() => {
  const all = [
    { id: 'overview', label: t('help.overview.title') },
    { id: 'search', label: t('help.search.title') },
    { id: 'filter', label: t('help.filter.title') },
    { id: 'documents', label: t('help.documents.title') },
    { id: 'tags', label: t('help.tags.title') },
    { id: 'saved', label: t('help.saved.title') },
    { id: 'access', label: t('help.access.title') }
  ]

  return help.value?.access.is_admin
    ? [...all, { id: 'admin', label: t('help.admin.title') }]
    : all
})

/** System tags carry no name of their own; the slug stands in. */
function tagLabel(tag: HelpTag): string {
  return tag.name ?? t(`tags.system.${tag.slug}`, tag.slug)
}

function tagNote(tag: HelpTag): string {
  const notes: string[] = []

  if (tag.private) notes.push(t('help.tags.noteOwn'))
  else if (tag.scope === 'personal') notes.push(t('help.tags.notePersonal'))
  else notes.push(t('help.tags.noteGlobal'))

  if (tag.auto_rule) {
    notes.push(tag.auto_duration_minutes
      ? t('help.tags.noteAutoDays', { days: Math.round(tag.auto_duration_minutes / 1440) })
      : t('help.tags.noteAuto'))
  }

  if (!tag.assignable) notes.push(t('help.tags.noteReadOnly'))

  return notes.join(' · ')
}

const facetLabels = computed(() =>
  (help.value?.search.facets ?? []).map(facet => t(`facet.${facet}`))
)

const sortLabels = computed(() =>
  (help.value?.search.sorts ?? []).map(sort => t(`search.sort.${sort}`))
)
</script>

<template>
  <UContainer class="py-8">
    <div class="mb-6">
      <h1 class="text-xl font-semibold">
        {{ t('help.title') }}
      </h1>
      <p class="text-sm text-muted">
        {{ t('help.subtitle') }}
      </p>
    </div>

    <div
      v-if="pending"
      class="flex items-center gap-2 text-sm text-muted"
    >
      <UIcon
        name="i-lucide-loader-circle"
        class="size-4 animate-spin"
      />
      {{ t('help.loading') }}
    </div>

    <UAlert
      v-else-if="error || !help"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :description="error ?? t('common.requestFailed')"
    />

    <div
      v-else
      class="grid grid-cols-1 lg:grid-cols-[14rem_1fr] gap-8"
    >
      <!-- Anchors, so the page stays usable at its length. -->
      <nav class="hidden lg:block">
        <ul class="sticky top-20 space-y-1 text-sm">
          <li
            v-for="section in sections"
            :key="section.id"
          >
            <a
              :href="`#${section.id}`"
              class="block rounded-md px-2 py-1 text-muted hover:bg-elevated hover:text-default"
            >{{ section.label }}</a>
          </li>
        </ul>
      </nav>

      <div class="max-w-3xl space-y-10">
        <!-- What is in this installation -->
        <section
          id="overview"
          class="scroll-mt-20 space-y-3"
        >
          <h2 class="text-lg font-medium">
            {{ t('help.overview.title') }}
          </h2>

          <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div
              v-for="stat in [
                { label: t('help.overview.instances'), value: n(help.installation.instances) },
                { label: t('help.overview.folders'), value: n(help.installation.folders) },
                { label: t('help.overview.documents'), value: n(help.installation.documents) },
                { label: t('help.overview.version'), value: help.installation.version }
              ]"
              :key="stat.label"
              class="rounded-lg border border-default p-3"
            >
              <p class="text-lg font-medium tabular-nums">
                {{ stat.value }}
              </p>
              <p class="text-xs text-muted">
                {{ stat.label }}
              </p>
            </div>
          </div>

          <p class="text-sm">
            {{ t('help.overview.body') }}
          </p>

          <p class="text-sm">
            {{ t('help.overview.changelogHint') }}
            <NuxtLink
              to="/changelog"
              class="text-primary hover:underline"
            >{{ t('nav.changelog') }}</NuxtLink>
          </p>

          <p
            v-if="help.installation.last_indexed_at"
            class="text-sm text-muted"
          >
            {{ t('help.overview.lastIndexed', { at: dateTime(help.installation.last_indexed_at) }) }}
            <template v-if="help.installation.pending > 0">
              {{ t('help.overview.pending', { count: n(help.installation.pending) }) }}
            </template>
          </p>

          <div
            v-if="help.folders.length > 0"
            class="overflow-x-auto"
          >
            <table class="w-full text-sm">
              <thead class="text-left text-xs text-muted">
                <tr class="border-b border-default">
                  <th class="py-1.5 pr-3 font-medium">
                    {{ t('help.overview.folder') }}
                  </th>
                  <th class="py-1.5 pr-3 font-medium">
                    {{ t('help.overview.instance') }}
                  </th>
                  <th class="py-1.5 font-medium">
                    {{ t('help.overview.interval') }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="folder in help.folders"
                  :key="folder.label + folder.instance"
                  class="border-b border-default/60 last:border-0"
                >
                  <td class="py-1.5 pr-3">
                    {{ folder.label }}
                    <UBadge
                      v-if="!folder.enabled"
                      size="sm"
                      color="neutral"
                      variant="subtle"
                      :label="t('help.overview.paused')"
                    />
                  </td>
                  <td class="py-1.5 pr-3 text-muted">
                    {{ folder.instance }}
                  </td>
                  <td class="py-1.5 text-muted tabular-nums">
                    {{ t('help.overview.everyMinutes', { minutes: folder.interval_minutes }) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <UAlert
            v-else
            color="warning"
            variant="subtle"
            icon="i-lucide-folder-lock"
            :description="help.access.is_admin ? t('help.overview.noFoldersAdmin') : t('help.overview.noFolders')"
          />
        </section>

        <!-- Searching -->
        <section
          id="search"
          class="scroll-mt-20 space-y-3"
        >
          <h2 class="text-lg font-medium">
            {{ t('help.search.title') }}
          </h2>
          <p class="text-sm">
            {{ t('help.search.body') }}
          </p>

          <HelpFigure
            name="search"
            :caption="t('help.search.figure')"
          />

          <ul class="space-y-1.5 text-sm">
            <li>{{ t('help.search.formats', { count: help.search.extensions.length }) }} <span class="text-muted">{{ help.search.extensions.join(', ') }}</span></li>
            <li v-if="help.search.ocr.enabled">
              {{ t('help.search.ocrOn', { languages: help.search.ocr.languages }) }}
            </li>
            <li v-else>
              {{ t('help.search.ocrOff') }}
            </li>
            <li>{{ t('help.search.maxSize', { mb: help.search.max_file_size_mb }) }}</li>
            <li>{{ t('help.search.sorts', { sorts: sortLabels.join(', ') }) }}</li>
            <li>{{ t('help.search.perPage', { count: help.search.per_page }) }}</li>
          </ul>
        </section>

        <!-- Filtering -->
        <section
          id="filter"
          class="scroll-mt-20 space-y-3"
        >
          <h2 class="text-lg font-medium">
            {{ t('help.filter.title') }}
          </h2>
          <p class="text-sm">
            {{ t('help.filter.body', { facets: facetLabels.join(', ') }) }}
          </p>
          <p class="text-sm">
            {{ t('help.filter.combining') }}
          </p>

          <HelpFigure
            name="filter"
            :caption="t('help.filter.figure')"
          />
        </section>

        <!-- Opening documents -->
        <section
          id="documents"
          class="scroll-mt-20 space-y-3"
        >
          <h2 class="text-lg font-medium">
            {{ t('help.documents.title') }}
          </h2>
          <p class="text-sm">
            {{ t('help.documents.body') }}
          </p>
          <p
            v-if="!help.search.preview.enabled"
            class="text-sm text-muted"
          >
            {{ t('help.documents.previewOff') }}
          </p>

          <HelpFigure
            name="document"
            :caption="t('help.documents.figure')"
          />
        </section>

        <!-- Tags -->
        <section
          id="tags"
          class="scroll-mt-20 space-y-3"
        >
          <h2 class="text-lg font-medium">
            {{ t('help.tags.title') }}
          </h2>
          <p class="text-sm">
            {{ t('help.tags.body') }}
          </p>

          <HelpFigure
            name="tags"
            :caption="t('help.tags.figure')"
          />

          <div
            v-if="help.tags.length > 0"
            class="rounded-lg border border-default divide-y divide-default"
          >
            <div
              v-for="tag in help.tags"
              :key="tag.slug"
              class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-3 py-2 text-sm"
            >
              <span class="font-medium">{{ tagLabel(tag) }}</span>
              <code class="text-xs text-muted">{{ tag.slug }}</code>
              <span class="w-full text-xs text-muted sm:w-auto sm:flex-1">{{ tagNote(tag) }}</span>
              <span
                v-if="tag.description"
                class="w-full text-xs text-muted"
              >{{ tag.description }}</span>
            </div>
          </div>

          <p
            v-else
            class="text-sm text-muted"
          >
            {{ t('help.tags.empty') }}
          </p>

          <p class="text-sm">
            {{ t('help.tags.own') }}
          </p>
        </section>

        <!-- Saved searches -->
        <section
          id="saved"
          class="scroll-mt-20 space-y-3"
        >
          <h2 class="text-lg font-medium">
            {{ t('help.saved.title') }}
          </h2>
          <p class="text-sm">
            {{ t('help.saved.body') }}
          </p>
        </section>

        <!-- The reader's own access -->
        <section
          id="access"
          class="scroll-mt-20 space-y-3"
        >
          <h2 class="text-lg font-medium">
            {{ t('help.access.title') }}
          </h2>
          <p class="text-sm">
            {{ t('help.access.body') }}
          </p>
          <p class="text-sm">
            {{ user?.two_factor_enabled ? t('help.access.twoFactorOn') : t('help.access.twoFactorOff') }}
          </p>

          <h3 class="pt-2 text-sm font-medium">
            {{ t('help.access.apiTitle') }}
          </h3>
          <p class="text-sm">
            {{ t('help.access.apiBody', { limit: help.access.api.rate_limit }) }}
          </p>
          <p class="text-sm">
            {{ t('help.access.apiDocs') }}
            <a
              :href="doc('docs/api.md')"
              target="_blank"
              rel="noopener"
              class="text-primary hover:underline"
            >{{ t('help.access.apiDocsLink') }}</a>{{ t('help.access.apiSpec') }}
            <a
              :href="doc('docs/api/openapi.yaml')"
              target="_blank"
              rel="noopener"
              class="text-primary hover:underline"
            >{{ t('help.access.apiSpecLink') }}</a>.
          </p>

          <template v-if="help.access.mcp.enabled">
            <h3 class="pt-2 text-sm font-medium">
              {{ t('help.access.mcpTitle') }}
            </h3>
            <p class="text-sm">
              {{ t('help.access.mcpBody', { tools: help.access.mcp.tools.join(', ') }) }}
            </p>
            <code class="block rounded bg-elevated px-2 py-1.5 font-mono text-xs select-all">{{ help.access.mcp.url }}</code>
            <p class="text-sm">
              {{ help.access.mcp.oauth ? t('help.access.mcpOauth') : t('help.access.mcpKeyOnly') }}
            </p>

            <p class="text-sm">
              {{ t('help.access.mcpDocs') }}
              <a
                :href="doc('docs/mcp.md')"
                target="_blank"
                rel="noopener"
                class="text-primary hover:underline"
              >{{ t('help.access.mcpDocsLink') }}</a>.
            </p>

            <HelpFigure
              name="mcp"
              :caption="t('help.access.figure')"
            />
          </template>

          <UButton
            to="/account"
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-user-cog"
            :label="t('help.access.toAccount')"
          />
        </section>

        <!-- Administration -->
        <section
          v-if="help.admin"
          id="admin"
          class="scroll-mt-20 space-y-3"
        >
          <h2 class="text-lg font-medium">
            {{ t('help.admin.title') }}
          </h2>
          <p class="text-sm">
            {{ t('help.admin.body', { minutes: help.admin.default_interval_minutes }) }}
          </p>

          <div
            v-if="help.admin.instances.length > 0"
            class="overflow-x-auto"
          >
            <table class="w-full text-sm">
              <thead class="text-left text-xs text-muted">
                <tr class="border-b border-default">
                  <th class="py-1.5 pr-3 font-medium">
                    {{ t('help.admin.instance') }}
                  </th>
                  <th class="py-1.5 pr-3 font-medium">
                    {{ t('help.admin.host') }}
                  </th>
                  <th class="py-1.5 font-medium">
                    {{ t('help.admin.folderCount') }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="instance in help.admin.instances"
                  :key="instance.name"
                  class="border-b border-default/60 last:border-0"
                >
                  <td class="py-1.5 pr-3">
                    {{ instance.name }}
                  </td>
                  <td class="py-1.5 pr-3 font-mono text-xs text-muted">
                    {{ instance.host }}
                  </td>
                  <td class="py-1.5 tabular-nums text-muted">
                    {{ instance.folders }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <p class="text-sm">
            {{ t('help.admin.people', { users: help.admin.users, admins: help.admin.admins }) }}
          </p>

          <p class="text-sm">
            {{ t('help.admin.states', {
              total: n(help.admin.documents_total),
              failed: n(help.admin.documents_failed),
              skipped: n(help.admin.documents_skipped)
            }) }}
          </p>

          <p class="text-sm">
            {{ t('help.admin.permissions') }}
          </p>

          <div class="flex flex-wrap gap-2 pt-1">
            <UButton
              v-for="link in [
                { to: '/admin/instances', label: t('nav.instances'), icon: 'i-lucide-cloud' },
                { to: '/admin/folders', label: t('nav.folders'), icon: 'i-lucide-folder-tree' },
                { to: '/admin/tags', label: t('nav.tags'), icon: 'i-lucide-tag' },
                { to: '/admin/users', label: t('userMenu.userManagement'), icon: 'i-lucide-users' },
                { to: '/admin/status', label: t('nav.status'), icon: 'i-lucide-activity' }
              ]"
              :key="link.to"
              :to="link.to"
              color="neutral"
              variant="outline"
              size="sm"
              :icon="link.icon"
              :label="link.label"
            />
          </div>
        </section>

        <p class="border-t border-default pt-4 text-xs text-muted">
          {{ t('help.footnote') }}
        </p>
      </div>
    </div>
  </UContainer>
</template>
