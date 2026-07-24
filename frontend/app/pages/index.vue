<script setup lang="ts">
const { t } = useI18n()
useHead({ title: () => t('nav.search') })

const { user } = useAuth()
const { load: loadDirectory } = useDirectory()
const {
  query, sort, page, filters, result, pending, error,
  activeFilterCount, run, toggleFilter, clearFilters
} = useSearch()

const sortItems = computed(() =>
  SORT_VALUES.map(value => ({ value: value as string, label: t(`search.sort.${value}`) }))
)

onMounted(() => {
  // Images for hits and facets; independent of the search run.
  loadDirectory()
  run()
})

const hasNoShares = computed(() => user.value?.folder_count === 0)

// The facets live in a drawer on mobile instead of above the results.
const filterDrawerOpen = ref(false)
</script>

<template>
  <UContainer class="py-6 sm:py-8">
    <UAlert
      v-if="hasNoShares"
      class="mb-6"
      color="warning"
      variant="subtle"
      icon="i-lucide-folder-lock"
      :title="t('search.noSharesTitle')"
      :description="t('search.noSharesDesc')"
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
  </UContainer>
</template>
