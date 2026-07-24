<script setup lang="ts">
useHead({ title: 'Überwachte Ordner' })

interface Folder {
  uuid: string
  label: string
  remote_path: string
  enabled: boolean
  interval_minutes: number
  exclude_patterns: string[]
  last_crawled_at: string | null
  image_url: string | null
  documents_count: number
  instance: { uuid: string, name: string }
}

interface InstanceOption {
  uuid: string
  name: string
  health_state: string
}

const api = useApi()
const toast = useToast()
const route = useRoute()
const { dateTime } = useFormat()

const folders = ref<Folder[]>([])
const instances = ref<InstanceOption[]>([])
const pending = ref(true)
const modalOpen = ref(false)
const saving = ref(false)
const formError = ref<string | null>(null)

const form = reactive({
  instance: (route.query.instance as string) || '',
  label: '',
  remote_path: '',
  interval_minutes: 15,
  exclude_patterns: ''
})

async function load() {
  pending.value = true
  try {
    const [folderResponse, instanceResponse] = await Promise.all([
      api.get<{ folders: Folder[] }>('/api/admin/folders'),
      api.get<{ instances: InstanceOption[] }>('/api/admin/instances')
    ])

    folders.value = folderResponse.folders
    instances.value = instanceResponse.instances
  } finally {
    pending.value = false
  }
}

function openCreate() {
  Object.assign(form, {
    instance: (route.query.instance as string) || instances.value[0]?.uuid || '',
    label: '',
    remote_path: '',
    interval_minutes: 15,
    exclude_patterns: ''
  })
  formError.value = null
  modalOpen.value = true
}

// Ohne eigene Bezeichnung nimmt der Ordnername den Platz ein.
watch(() => form.remote_path, (value) => {
  if (!form.label && value) {
    form.label = value.split('/').pop() || value
  }
})

async function save() {
  saving.value = true
  formError.value = null

  try {
    await api.post('/api/admin/folders', {
      instance: form.instance,
      label: form.label,
      remote_path: form.remote_path,
      interval_minutes: form.interval_minutes,
      exclude_patterns: form.exclude_patterns
        .split('\n')
        .map(pattern => pattern.trim())
        .filter(Boolean)
    })

    modalOpen.value = false
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError
      ? (Object.values(e.errors)[0]?.[0] || e.message)
      : 'Speichern fehlgeschlagen.'
  } finally {
    saving.value = false
  }
}

async function reindex(folder: Folder, full: boolean) {
  const response = await api.post<{ message: string }>(
    `/api/admin/folders/${folder.uuid}/reindex`,
    { full }
  )

  toast.add({ title: response.message, color: 'info' })
}

async function toggle(folder: Folder) {
  await api.put(`/api/admin/folders/${folder.uuid}`, { enabled: !folder.enabled })
  await load()
}

async function remove(folder: Folder) {
  if (!confirm(`Ordner "${folder.label}" nicht mehr überwachen? Die ${folder.documents_count} Einträge verschwinden aus dem Suchindex. Die Dateien in der Nextcloud bleiben unangetastet.`)) {
    return
  }

  await api.del(`/api/admin/folders/${folder.uuid}`)
  await load()
}

onMounted(load)
</script>

<template>
  <UContainer class="py-8 space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold">
          Überwachte Ordner
        </h1>
        <p class="text-sm text-muted">
          Jeder Ordner wird samt Unterverzeichnissen durchlaufen.
        </p>
      </div>

      <UButton
        icon="i-lucide-plus"
        label="Ordner hinzufügen"
        :disabled="instances.length === 0"
        @click="openCreate"
      />
    </div>

    <UAlert
      v-if="!pending && instances.length === 0"
      color="warning"
      variant="subtle"
      icon="i-lucide-cloud-off"
      title="Noch keine Instanz hinterlegt"
      description="Legen Sie zuerst eine Nextcloud-Instanz an."
      :actions="[{ label: 'Zu den Instanzen', to: '/admin/instances', color: 'neutral', variant: 'outline' }]"
    />

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
      v-else-if="folders.length > 0"
      class="space-y-3"
    >
      <UCard
        v-for="folder in folders"
        :key="folder.uuid"
      >
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <h2 class="font-medium">
                {{ folder.label }}
              </h2>
              <UBadge
                v-if="!folder.enabled"
                size="sm"
                color="neutral"
                variant="subtle"
                label="pausiert"
              />
            </div>

            <p class="text-sm text-muted break-all">
              {{ folder.instance.name }} · <span class="font-mono">{{ folder.remote_path }}</span>
            </p>
            <p class="text-xs text-muted mt-1">
              {{ folder.documents_count.toLocaleString('de-DE') }} Dokumente ·
              alle {{ folder.interval_minutes }} Minuten ·
              zuletzt {{ folder.last_crawled_at ? dateTime(folder.last_crawled_at) : 'nie' }}
            </p>
            <p
              v-if="folder.exclude_patterns.length"
              class="text-xs text-muted"
            >
              Ausgeschlossen: {{ folder.exclude_patterns.join(', ') }}
            </p>

            <DirectoryImageUpload
              class="mt-3"
              :label="folder.label"
              :current-url="folder.image_url"
              :upload-path="`/api/admin/folders/${folder.uuid}/image`"
              :remove-path="`/api/admin/folders/${folder.uuid}/image`"
              @changed="load"
            />
          </div>

          <div class="flex gap-2">
            <UButton
              size="sm"
              color="neutral"
              variant="outline"
              icon="i-lucide-refresh-cw"
              label="Durchlauf"
              @click="reindex(folder, false)"
            />
            <UButton
              size="sm"
              color="neutral"
              variant="ghost"
              icon="i-lucide-rotate-ccw"
              label="Vollständig"
              @click="reindex(folder, true)"
            />
            <UButton
              size="sm"
              color="neutral"
              variant="ghost"
              :icon="folder.enabled ? 'i-lucide-pause' : 'i-lucide-play'"
              @click="toggle(folder)"
            />
            <UButton
              size="sm"
              color="error"
              variant="ghost"
              icon="i-lucide-trash-2"
              @click="remove(folder)"
            />
          </div>
        </div>
      </UCard>
    </div>

    <div
      v-else-if="instances.length > 0"
      class="rounded-lg border border-dashed border-default p-12 text-center"
    >
      <UIcon
        name="i-lucide-folder-search"
        class="size-8 text-muted"
      />
      <p class="mt-2 text-sm text-muted">
        Noch kein Ordner ausgewählt.
      </p>
    </div>

    <UModal
      v-model:open="modalOpen"
      title="Ordner hinzufügen"
    >
      <template #body>
        <form
          class="space-y-4"
          @submit.prevent="save"
        >
          <UFormField label="Instanz">
            <USelect
              v-model="form.instance"
              :items="instances.map(i => ({ label: i.name, value: i.uuid }))"
              value-key="value"
              class="w-full"
            />
          </UFormField>

          <UFormField label="Ordner auswählen">
            <FolderPicker
              v-if="form.instance"
              v-model="form.remote_path"
              :instance-uuid="form.instance"
            />
          </UFormField>

          <UFormField
            label="Bezeichnung"
            hint="Taucht als Filter in der Suche auf"
          >
            <UInput
              v-model="form.label"
              required
              class="w-full"
            />
          </UFormField>

          <UFormField label="Intervall in Minuten">
            <UInput
              v-model.number="form.interval_minutes"
              type="number"
              min="1"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Ausschlussmuster"
            hint="Ein Muster pro Zeile, z. B. *.tmp oder Archiv/*"
          >
            <UTextarea
              v-model="form.exclude_patterns"
              :rows="3"
              class="w-full"
            />
          </UFormField>

          <UAlert
            v-if="formError"
            color="error"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            :description="formError"
          />

          <div class="flex justify-end gap-2 pt-2">
            <UButton
              color="neutral"
              variant="ghost"
              label="Abbrechen"
              @click="modalOpen = false"
            />
            <UButton
              type="submit"
              :loading="saving"
              :disabled="!form.remote_path"
              label="Übernehmen"
            />
          </div>
        </form>
      </template>
    </UModal>
  </UContainer>
</template>
