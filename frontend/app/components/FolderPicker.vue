<script setup lang="ts">
/**
 * Browses the folders of an instance over WebDAV. Each level is fetched on its
 * own — Nextcloud does not answer a recursive request.
 */

interface RemoteDirectory {
  name: string
  path: string
  modified_at: string | null
}

const props = defineProps<{ instanceUuid: string }>()
const model = defineModel<string>({ default: '' })

const api = useApi()
const { t } = useI18n()

const path = ref('')
const directories = ref<RemoteDirectory[]>([])
const parent = ref<string | null>(null)
const pending = ref(false)
const error = ref<string | null>(null)

const breadcrumbs = computed(() => {
  const segments = path.value ? path.value.split('/') : []
  return [
    { label: t('admin.picker.root'), path: '' },
    ...segments.map((segment, i) => ({
      label: segment,
      path: segments.slice(0, i + 1).join('/')
    }))
  ]
})

async function open(target: string) {
  pending.value = true
  error.value = null

  try {
    const response = await api.get<{
      path: string
      parent: string | null
      directories: RemoteDirectory[]
    }>(`/api/admin/instances/${props.instanceUuid}/browse`, { path: target })

    path.value = response.path
    parent.value = response.parent
    directories.value = response.directories
    model.value = response.path
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('admin.picker.failed')
  } finally {
    pending.value = false
  }
}

watch(() => props.instanceUuid, () => open(''), { immediate: true })
</script>

<template>
  <div class="rounded-lg border border-default">
    <div class="flex items-center gap-1 border-b border-default px-3 py-2 text-sm overflow-x-auto">
      <template
        v-for="(crumb, i) in breadcrumbs"
        :key="crumb.path"
      >
        <UIcon
          v-if="i > 0"
          name="i-lucide-chevron-right"
          class="size-3 shrink-0 text-muted"
        />
        <button
          type="button"
          class="whitespace-nowrap rounded px-1.5 py-0.5 hover:bg-elevated"
          :class="i === breadcrumbs.length - 1 ? 'font-medium' : 'text-muted'"
          @click="open(crumb.path)"
        >
          {{ crumb.label }}
        </button>
      </template>

      <UIcon
        v-if="pending"
        name="i-lucide-loader-circle"
        class="animate-spin size-4 ml-auto text-muted"
      />
    </div>

    <div class="max-h-72 overflow-y-auto">
      <p
        v-if="error"
        class="px-3 py-4 text-sm text-error"
      >
        {{ error }}
      </p>

      <p
        v-else-if="!pending && directories.length === 0"
        class="px-3 py-4 text-sm text-muted"
      >
        {{ t('admin.picker.empty') }}
      </p>

      <ul
        v-else
        class="divide-y divide-default"
      >
        <li
          v-for="directory in directories"
          :key="directory.path"
        >
          <button
            type="button"
            class="w-full flex items-center gap-2 px-3 py-2 text-left text-sm hover:bg-elevated"
            @click="open(directory.path)"
          >
            <UIcon
              name="i-lucide-folder"
              class="size-4 shrink-0 text-muted"
            />
            <span class="truncate">{{ directory.name }}</span>
            <UIcon
              name="i-lucide-chevron-right"
              class="size-4 ml-auto shrink-0 text-muted"
            />
          </button>
        </li>
      </ul>
    </div>

    <div class="border-t border-default px-3 py-2 text-xs text-muted">
      {{ t('admin.picker.selected') }}: <span class="font-mono">{{ path || '/' }}</span>
    </div>
  </div>
</template>
