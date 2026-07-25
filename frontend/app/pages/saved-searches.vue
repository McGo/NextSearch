<script setup lang="ts">
const { t } = useI18n()
useHead({ title: () => t('nav.savedSearches') })

const toast = useToast()
const { items, load, remove } = useSavedSearches()

const pending = ref(true)
onMounted(async () => {
  try {
    await load(true)
  } finally {
    pending.value = false
  }
})

// Recall rebuilds the search URL; the search page reads q/sort/f from it.
function recall(search: SavedSearch) {
  const query: Record<string, string> = {}
  if (search.query) query.q = search.query
  if (search.sort && search.sort !== 'relevance') query.sort = search.sort
  const encoded = encodeFilters(search.filters)
  if (encoded) query.f = encoded

  navigateTo({ path: '/', query })
}

const removing = ref<string | null>(null)
async function removeSearch(search: SavedSearch) {
  removing.value = search.uuid
  try {
    await remove(search.uuid)
  } catch {
    toast.add({ title: t('saved.removeFailed'), color: 'error' })
  } finally {
    removing.value = null
  }
}

function summary(search: SavedSearch): string {
  const parts: string[] = []
  if (search.query) parts.push(`„${search.query}"`)
  const count = countFilters(search.filters)
  if (count > 0) parts.push(t('saved.filterCount', { count }, count))
  if (parts.length === 0) return t('saved.everything')
  return parts.join(' · ')
}
</script>

<template>
  <UContainer class="py-8 space-y-6">
    <div>
      <h1 class="text-xl font-semibold">
        {{ t('nav.savedSearches') }}
      </h1>
      <p class="text-sm text-muted">
        {{ t('saved.pageSubtitle') }}
      </p>
    </div>

    <div
      v-if="pending"
      class="flex justify-center py-16"
    >
      <UIcon
        name="i-lucide-loader-circle"
        class="animate-spin size-6 text-muted"
      />
    </div>

    <div
      v-else-if="items.length === 0"
      class="rounded-lg border border-dashed border-default p-12 text-center"
    >
      <UIcon
        name="i-lucide-bookmark"
        class="size-8 text-muted"
      />
      <p class="mt-2 text-sm text-muted">
        {{ t('saved.empty') }}
      </p>
    </div>

    <ul
      v-else
      class="space-y-2"
    >
      <li
        v-for="search in items"
        :key="search.uuid"
        class="group flex items-center gap-2 rounded-lg border border-default p-3 hover:bg-elevated/40"
      >
        <button
          type="button"
          class="flex-1 min-w-0 flex items-center gap-3 text-left"
          @click="recall(search)"
        >
          <UIcon
            name="i-lucide-bookmark"
            class="size-4 text-primary shrink-0"
          />
          <span class="min-w-0">
            <span class="block truncate font-medium text-highlighted">{{ search.name }}</span>
            <span class="block truncate text-xs text-muted">{{ summary(search) }}</span>
          </span>
        </button>

        <UButton
          color="neutral"
          variant="ghost"
          size="sm"
          icon="i-lucide-corner-down-left"
          :label="t('saved.recall')"
          @click="recall(search)"
        />
        <UButton
          color="neutral"
          variant="ghost"
          size="sm"
          icon="i-lucide-trash-2"
          :loading="removing === search.uuid"
          :aria-label="t('saved.remove')"
          @click="removeSearch(search)"
        />
      </li>
    </ul>
  </UContainer>
</template>
