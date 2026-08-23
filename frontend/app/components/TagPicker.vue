<script setup lang="ts">
import type { DocumentTag, Tag } from '~/composables/useTags'

/**
 * Tags on one document: what hangs there, what can be added, and a shortcut to
 * create a private tag on the spot rather than detouring through the settings.
 */
const props = defineProps<{
  documentUuid: string
  modelValue: DocumentTag[]
}>()

const emit = defineEmits<{ 'update:modelValue': [DocumentTag[]] }>()

const { t } = useI18n()
const toast = useToast()
const { items, load, create, attach, detach, label } = useTags()

const open = ref(false)
const busy = ref<string | null>(null)
const search = ref('')
const creating = ref(false)

onMounted(() => {
  load().catch(() => { /* the picker just stays empty */ })
})

const assignedSlugs = computed(() => new Set(props.modelValue.map(tag => tag.uuid)))

/** Tags not yet on the document, narrowed by whatever is typed. */
const available = computed(() => {
  const needle = search.value.trim().toLowerCase()

  return items.value.filter((tag) => {
    if (assignedSlugs.value.has(tag.uuid)) return false
    if (needle === '') return true
    return label(tag).toLowerCase().includes(needle) || tag.slug.includes(needle)
  })
})

/**
 * Offer to create a private tag when nothing matches what was typed — and the
 * name isn't already taken by a tag that is simply assigned.
 */
const createable = computed(() => {
  const name = search.value.trim()
  if (name === '' || creating.value) return null

  const taken = items.value.some(tag => label(tag).toLowerCase() === name.toLowerCase())
  return taken ? null : name
})

async function add(tag: Tag) {
  busy.value = tag.uuid
  try {
    emit('update:modelValue', await attach(props.documentUuid, [tag.uuid]))
    search.value = ''
  } catch (e) {
    toast.add({ title: e instanceof ApiError ? e.message : t('tags.assignFailed'), color: 'error' })
  } finally {
    busy.value = null
  }
}

async function drop(tag: DocumentTag) {
  busy.value = tag.uuid
  try {
    emit('update:modelValue', await detach(props.documentUuid, tag.uuid))
  } catch (e) {
    toast.add({ title: e instanceof ApiError ? e.message : t('tags.removeFailed'), color: 'error' })
  } finally {
    busy.value = null
  }
}

async function createAndAdd() {
  const name = createable.value
  if (!name) return

  creating.value = true
  try {
    const tag = await create({ name })
    emit('update:modelValue', await attach(props.documentUuid, [tag.uuid]))
    search.value = ''
  } catch (e) {
    toast.add({ title: e instanceof ApiError ? e.message : t('tags.createFailed'), color: 'error' })
  } finally {
    creating.value = false
  }
}
</script>

<template>
  <div
    data-shot="tags"
    class="flex flex-wrap items-center gap-2"
  >
    <TagChip
      v-for="tag in modelValue"
      :key="tag.uuid"
      :tag="tag"
      removable
      @remove="drop(tag)"
    />

    <UPopover v-model:open="open">
      <UButton
        size="xs"
        color="neutral"
        variant="outline"
        icon="i-lucide-tag"
        :label="t('tags.add')"
      />

      <template #content>
        <div class="w-72 max-w-[90vw] p-2 space-y-2">
          <UInput
            v-model="search"
            size="sm"
            autofocus
            icon="i-lucide-search"
            :placeholder="t('tags.searchPlaceholder')"
            class="w-full"
            @keydown.enter.prevent="createAndAdd"
          />

          <ul
            v-if="available.length > 0"
            class="max-h-64 overflow-y-auto"
          >
            <li
              v-for="tag in available"
              :key="tag.uuid"
            >
              <button
                type="button"
                class="w-full flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-elevated/50 disabled:opacity-50"
                :disabled="busy !== null"
                @click="add(tag)"
              >
                <TagChip :tag="tag" />
                <span
                  v-if="tag.description"
                  class="truncate text-xs text-muted"
                >{{ tag.description }}</span>
              </button>
            </li>
          </ul>

          <p
            v-else-if="!createable"
            class="px-2 py-1.5 text-sm text-muted"
          >
            {{ t('tags.noneLeft') }}
          </p>

          <UButton
            v-if="createable"
            block
            size="sm"
            color="neutral"
            variant="soft"
            icon="i-lucide-plus"
            :loading="creating"
            :label="t('tags.createOwn', { name: createable })"
            @click="createAndAdd"
          />
        </div>
      </template>
    </UPopover>
  </div>
</template>
