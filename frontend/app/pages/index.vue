<script setup lang="ts">
const { t } = useI18n()
useHead({ title: () => t('nav.search') })

const { user, isAdmin } = useAuth()
const { directory, load: loadDirectory } = useDirectory()
const {
  query, sort, page, filters, result, pending, error,
  activeFilterCount, run, toggleFilter, clearFilters, applySaved
} = useSearch()

// A search is worth saving once there's a query or at least one active filter.
const canSave = computed(() => query.value.trim() !== '' || activeFilterCount.value > 0)

const sortItems = computed(() =>
  SORT_VALUES.map(value => ({ value: value as string, label: t(`search.sort.${value}`) }))
)

// While indexing runs, keep pulling in freshly indexed documents: refresh the
// directory (new folders/images) and re-run the search when a crawl finishes.
const { status: indexing } = useIndexingStatus(() => {
  loadDirectory(true)
  run()
})

onMounted(() => {
  // Images for hits and facets; independent of the search run.
  loadDirectory()
  run()
})

const hasNoShares = computed(() => user.value?.folder_count === 0)

/**
 * A fresh installation lands on an empty result list, which looks the same as a
 * broken one. These are the three states where the page has something to say
 * instead of nothing:
 *
 *   instance  no Nextcloud connected at all
 *   folder    connected, but no folder picked
 *   indexing  folders are there, the index is still empty
 *
 * The first two are an administrator's job; for everyone else the folder that
 * was never shared is what they see, and that has its own note.
 */
const setupStep = computed<'instance' | 'folder' | 'indexing' | null>(() => {
  const dir = directory.value
  if (!dir) return null

  if (dir.instances.length === 0) return 'instance'
  if (dir.folders.length === 0) return 'folder'

  // Folders exist but nothing has come out of them yet. Only worth saying when
  // the user isn't looking at a filtered result that legitimately found none.
  const searchedNothing = query.value.trim() === '' && activeFilterCount.value === 0
  const empty = result.value !== null && result.value.total === 0

  return empty && searchedNothing && (indexing.value?.indexed ?? 0) === 0 ? 'indexing' : null
})

// Without a folder there is nothing to type into a search field, so the field
// goes and the guide takes the whole page.
const setupBlocks = computed(() =>
  isAdmin.value && (setupStep.value === 'instance' || setupStep.value === 'folder')
)

// While the first crawl runs, the search stays where it is and the guide
// replaces the empty result box.
const setupWaiting = computed(() => setupStep.value === 'indexing')

// The facets live in a drawer on mobile instead of above the results.
const filterDrawerOpen = ref(false)
</script>

<template>
  <UContainer class="py-6 sm:py-8">
    <!-- Nothing connected yet: the guide instead of a search field that has
         nothing to search. -->
    <SetupGuide
      v-if="setupBlocks && setupStep"
      :step="setupStep"
      :is-admin="true"
    />

    <template v-else>
      <UAlert
        v-if="hasNoShares"
        class="mb-6"
        color="warning"
        variant="subtle"
        icon="i-lucide-folder-lock"
        :title="t('search.noSharesTitle')"
        :description="t('search.noSharesDesc')"
      />

      <UAlert
        v-if="indexing?.running"
        class="mb-6"
        color="info"
        variant="subtle"
        icon="i-lucide-loader-circle"
        :ui="{ icon: 'animate-spin' }"
        :title="t('indexing.title')"
        :description="t('indexing.desc', { pending: indexing.pending, indexed: indexing.indexed })"
      />

      <div class="flex gap-2 sm:gap-3">
        <UInput
          v-model="query"
          icon="i-lucide-search"
          :placeholder="t('search.placeholder')"
          size="lg"
          autofocus
          class="flex-1 min-w-0"
        />

        <USelect
          v-model="sort"
          :items="sortItems"
          value-key="value"
          size="lg"
          class="w-32 sm:w-44 lg:w-48 shrink-0"
        />
      </div>

      <!-- Save the current search, or recall a saved one. -->
      <div class="mt-3">
        <SavedSearches
          :query="query"
          :filters="filters"
          :sort="sort"
          :can-save="canSave"
          @apply="applySaved"
        />
      </div>

      <div class="mt-6 grid grid-cols-1 lg:grid-cols-[16rem_1fr] gap-8">
        <!-- Facet panel: fixed to the side on desktop, in the drawer on mobile. -->
        <FacetPanel
          class="hidden lg:block"
          :facets="result?.facets ?? []"
          :selected="filters"
          :active-count="activeFilterCount"
          @toggle="toggleFilter"
          @clear="clearFilters"
        />

        <section class="space-y-4">
          <!-- Mobile control row: filter button and hit count right above the
               results, so you don't scroll past the filters first. -->
          <div class="flex items-center justify-between gap-3 lg:hidden">
            <UButton
              color="neutral"
              variant="outline"
              icon="i-lucide-sliders-horizontal"
              :disabled="!result || result.facets.length === 0"
              @click="filterDrawerOpen = true"
            >
              {{ t('search.filter') }}
              <UBadge
                v-if="activeFilterCount > 0"
                size="sm"
                color="primary"
                variant="solid"
                :label="String(activeFilterCount)"
              />
            </UButton>

            <span
              v-if="result"
              class="text-sm text-muted whitespace-nowrap"
            >
              <UIcon
                v-if="pending"
                name="i-lucide-loader-circle"
                class="animate-spin size-4 align-middle"
              />
              <template v-else>{{ t('search.results', { count: result.total }, result.total) }}</template>
            </span>
          </div>

          <!-- On desktop this row carries the hit count. -->
          <div class="hidden lg:flex items-center justify-between text-sm text-muted">
            <span v-if="result">
              {{ t('search.results', { count: result.total }, result.total) }}
              <template v-if="result.took_ms !== null"> {{ t('search.took', { ms: result.took_ms }) }}</template>
            </span>
            <UIcon
              v-if="pending"
              name="i-lucide-loader-circle"
              class="animate-spin size-4"
            />
          </div>

          <UAlert
            v-if="error"
            color="error"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            :description="error"
          />

          <div
            v-if="result && result.hits.length > 0"
            class="space-y-3"
          >
            <ResultCard
              v-for="hit in result.hits"
              :key="hit.uuid"
              :hit="hit"
            />
          </div>

          <!-- Folders are connected but the index is still empty: say so,
               rather than let it read as "nothing found". -->
          <SetupGuide
            v-else-if="setupWaiting && !pending"
            step="indexing"
            :is-admin="isAdmin"
            :indexed="indexing?.indexed"
            :pending="indexing?.pending"
          />

          <div
            v-else-if="result && !pending && !hasNoShares"
            class="rounded-lg border border-dashed border-default p-12 text-center"
          >
            <UIcon
              name="i-lucide-file-question"
              class="size-8 text-muted"
            />
            <p class="mt-2 text-sm text-muted">
              <template v-if="query || activeFilterCount">
                {{ t('search.emptyWithQuery') }}
              </template>
              <template v-else>
                {{ t('search.emptyNoQuery') }}
              </template>
            </p>
          </div>

          <UPagination
            v-if="result && result.total_pages > 1"
            v-model:page="page"
            :total="result.total"
            :items-per-page="result.per_page"
            class="justify-center pt-4"
          />
        </section>
      </div>

      <!-- Mobile filter drawer. The same facet panel as on desktop; filters apply
           at once — the result list already updates behind the drawer. -->
      <USlideover
        v-model:open="filterDrawerOpen"
        :title="t('search.filter')"
        side="left"
        :ui="{ content: 'lg:hidden' }"
      >
        <template #body>
          <FacetPanel
            :facets="result?.facets ?? []"
            :selected="filters"
            :active-count="activeFilterCount"
            @toggle="toggleFilter"
            @clear="clearFilters"
          />
        </template>

        <template #footer>
          <UButton
            block
            color="neutral"
            :label="t('common.done')"
            @click="filterDrawerOpen = false"
          />
        </template>
      </USlideover>
    </template>
  </UContainer>
</template>
