<script setup lang="ts">
useHead({ title: 'Suche' })

const { user } = useAuth()
const { load: loadDirectory } = useDirectory()
const {
  query, sort, page, filters, result, pending, error,
  activeFilterCount, run, toggleFilter, clearFilters
} = useSearch()

onMounted(() => {
  // Bilder für Treffer und Facetten; unabhängig vom Suchlauf.
  loadDirectory()
  run()
})

const hasNoShares = computed(() => user.value?.folder_count === 0)

// Auf Mobil sitzen die Facetten in einer Schublade statt über den Treffern.
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
      title="Für Sie ist noch kein Ordner freigegeben"
      description="Bis ein Administrator Ihnen einen Ordner zuweist, bleibt die Suche leer."
    />

    <div class="flex gap-2 sm:gap-3">
      <UInput
        v-model="query"
        icon="i-lucide-search"
        placeholder="Volltextsuche über alle indizierten Dokumente"
        size="lg"
        autofocus
        class="flex-1 min-w-0"
      />

      <USelect
        v-model="sort"
        :items="SORT_OPTIONS"
        value-key="value"
        size="lg"
        class="w-32 sm:w-44 lg:w-48 shrink-0"
      />
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-[16rem_1fr] gap-8">
      <!-- Facettenleiste: auf Desktop fest an der Seite, auf Mobil in der
           Schublade weiter unten. -->
      <FacetPanel
        class="hidden lg:block"
        :facets="result?.facets ?? []"
        :selected="filters"
        :active-count="activeFilterCount"
        @toggle="toggleFilter"
        @clear="clearFilters"
      />

      <section class="space-y-4">
        <!-- Mobile Steuerzeile: Filter-Knopf und Trefferzahl direkt über den
             Ergebnissen, damit man nicht erst an den Filtern vorbeiscrollt. -->
        <div class="flex items-center justify-between gap-3 lg:hidden">
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-sliders-horizontal"
            :disabled="!result || result.facets.length === 0"
            @click="filterDrawerOpen = true"
          >
            Filter
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
            <template v-else>{{ result.total.toLocaleString('de-DE') }} Treffer</template>
          </span>
        </div>

        <!-- Auf Desktop trägt diese Zeile die Trefferzahl. -->
        <div class="hidden lg:flex items-center justify-between text-sm text-muted">
          <span v-if="result">
            {{ result.total.toLocaleString('de-DE') }} Treffer
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

    <!-- Mobile Filter-Schublade. Dieselbe Facettenleiste wie am Desktop, die
         Filter greifen sofort — hinter der Schublade aktualisiert sich die
         Trefferliste bereits. -->
    <USlideover
      v-model:open="filterDrawerOpen"
      title="Filter"
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
          label="Fertig"
          @click="filterDrawerOpen = false"
        />
      </template>
    </USlideover>
  </UContainer>
</template>
