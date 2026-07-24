<script setup lang="ts">
const props = defineProps<{
  query: string
  filters: FilterState
  sort: string
  canSave: boolean
}>()

const emit = defineEmits<{ apply: [SavedSearch] }>()

const { t } = useI18n()
const toast = useToast()
const { items, load, create, remove } = useSavedSearches()

onMounted(() => {
  load().catch(() => { /* the list just stays empty */ })
})

const saveOpen = ref(false)
const saveName = ref('')
const saving = ref(false)
const saveError = ref<string | null>(null)

function openSave() {
  // Prefill the name with the query — a search for "invoice" becomes a
  // sensible default label without extra typing.
  saveName.value = props.query.trim()
  saveError.value = null
  saveOpen.value = true
}

async function confirmSave() {
  if (saveName.value.trim() === '') {
    saveError.value = t('saved.nameRequired')
    return
  }

  saving.value = true
  saveError.value = null

  try {
    await create({
      name: saveName.value.trim(),
      query: props.query,
      filters: props.filters,
      sort: props.sort
    })
    saveOpen.value = false
    toast.add({ title: t('saved.saved'), color: 'success' })
  } catch (e) {
    saveError.value = e instanceof ApiError ? e.message : t('saved.saveFailed')
  } finally {
    saving.value = false
  }
}

const removing = ref<string | null>(null)

async function removeSearch(saved: SavedSearch) {
  removing.value = saved.uuid
  try {
    await remove(saved.uuid)
  } catch {
    toast.add({ title: t('saved.removeFailed'), color: 'error' })
  } finally {
    removing.value = null
  }
}

function apply(saved: SavedSearch) {
  emit('apply', saved)
}

// A short line describing what a saved search holds, so two searches with the
// same words but different filters are still tellable apart.
function summary(saved: SavedSearch): string {
  const parts: string[] = []
  if (saved.query) parts.push(`„${saved.query}"`)

  const count = countFilters(saved.filters)
  if (count > 0) parts.push(t('saved.filterCount', { count }, count))

  if (parts.length === 0) return t('saved.everything')
  return parts.join(' · ')
}
</script>

<template>
  <div class="flex items-center gap-2">
    <UButton
      v-if="canSave"
      size="sm"
      color="neutral"
      variant="ghost"
      icon="i-lucide-bookmark-plus"
      :label="t('saved.save')"
      @click="openSave"
    />

    <UPopover v-if="items.length > 0">
      <UButton
        size="sm"
        color="neutral"
        variant="ghost"
        icon="i-lucide-bookmark"
        trailing-icon="i-lucide-chevron-down"
      >
        {{ t('saved.title') }}
        <UBadge
          size="sm"
          color="neutral"
          variant="subtle"
          :label="String(items.length)"
        />
      </UButton>

      <template #content>
        <div class="w-80 max-w-[90vw] p-1">
          <ul class="max-h-80 overflow-y-auto">
            <li
              v-for="saved in items"
              :key="saved.uuid"
              class="group flex items-center gap-1 rounded-md hover:bg-elevated/50"
            >
              <button
                type="button"
                class="flex-1 min-w-0 px-2 py-2 text-left"
                @click="apply(saved)"
              >
                <span class="block truncate text-sm font-medium text-highlighted">{{ saved.name }}</span>
                <span class="block truncate text-xs text-muted">{{ summary(saved) }}</span>
              </button>

              <UButton
                color="neutral"
                variant="ghost"
                size="xs"
                icon="i-lucide-trash-2"
                :loading="removing === saved.uuid"
                :aria-label="t('saved.remove')"
                class="shrink-0 text-muted opacity-60 hover:opacity-100 focus:opacity-100"
                @click.stop="removeSearch(saved)"
              />
            </li>
          </ul>
        </div>
      </template>
    </UPopover>

    <UModal
      v-model:open="saveOpen"
      :title="t('saved.saveTitle')"
    >
      <template #body>
        <form
          class="space-y-4"
          @submit.prevent="confirmSave"
        >
          <UFormField
            :label="t('saved.nameLabel')"
            name="name"
            :error="saveError ?? undefined"
          >
            <UInput
              v-model="saveName"
              autofocus
              maxlength="120"
              :placeholder="t('saved.namePlaceholder')"
              class="w-full"
            />
          </UFormField>

          <div class="flex justify-end gap-2 pt-2">
            <UButton
              color="neutral"
              variant="ghost"
              :label="t('common.cancel')"
              @click="saveOpen = false"
            />
            <UButton
              type="submit"
              :loading="saving"
              :label="t('saved.save')"
            />
          </div>
        </form>
      </template>
    </UModal>
  </div>
</template>
