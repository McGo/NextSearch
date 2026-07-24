<script setup lang="ts">
useHead({ title: 'Suche' })

const { user } = useAuth()
const {
  query, sort, page, filters, result, pending, error,
  activeFilterCount, run, toggleFilter, clearFilters
} = useSearch()

onMounted(run)

const hasNoShares = computed(() => user.value?.folder_count === 0)
</script>

<template>
  <UContainer class="py-8">
    <UAlert
      v-if="hasNoShares"
      class="mb-6"
      color="warning"
      variant="subtle"
      icon="i-lucide-folder-lock"
      title="Für Sie ist noch kein Ordner freigegeben"
      description="Bis ein Administrator Ihnen einen Ordner zuweist, bleibt die Suche leer."
    />

    <div class="flex gap-3">
      <UInput
        v-model="query"
        icon="i-lucide-search"
        placeholder="Volltextsuche über alle indizierten Dokumente"
        size="lg"
        autofocus
        class="flex-1"
      />

      <USelect
        v-model="sort"
        :items="SORT_OPTIONS"
        value-key="value"
        size="lg"
        class="w-48"
      />
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-[16rem_1fr] gap-8">
      <FacetPanel
        :facets="result?.facets ?? []"
        :selected="filters"
        :active-count="activeFilterCount"
        @toggle="toggleFilter"
        @clear="clearFilters"
      />

      <section class="space-y-4">
        <div class="flex items-center justify-between text-sm text-muted">
          <span v-if="result">
            {{ result.total.toLocaleString('de-DE') }}
            {{ result.total === 1 ? 'Treffer' : 'Treffer' }}
            <template v-if="result.took_ms !== null"> in {{ result.took_ms }} ms</template>
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
              Zu dieser Suche gibt es nichts. Weniger Filter oder ein anderer Begriff helfen meist.
            </template>
            <template v-else>
              Noch nichts indiziert oder noch nichts gesucht.
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
  </UContainer>
</template>
