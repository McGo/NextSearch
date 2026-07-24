<script setup lang="ts">
useHead({ title: 'Nextcloud-Instanzen' })

interface Instance {
  uuid: string
  name: string
  base_url: string
  username: string
  verify_tls: boolean
  enabled: boolean
  health_state: 'ok' | 'failed' | 'unknown'
  health_message: string | null
  health_checked_at: string | null
  folders_count: number
  documents_count: number
}

const api = useApi()
const toast = useToast()
const { dateTime } = useFormat()

const instances = ref<Instance[]>([])
const pending = ref(true)
const modalOpen = ref(false)
const editing = ref<Instance | null>(null)
const saving = ref(false)
const formError = ref<string | null>(null)

const form = reactive({
  name: '',
  base_url: '',
  username: '',
  app_password: '',
  verify_tls: true
})

async function load() {
  pending.value = true
  try {
    instances.value = (await api.get<{ instances: Instance[] }>('/api/admin/instances')).instances
  } finally {
    pending.value = false
  }
}

function openCreate() {
  editing.value = null
  Object.assign(form, { name: '', base_url: '', username: '', app_password: '', verify_tls: true })
  formError.value = null
  modalOpen.value = true
}

function openEdit(instance: Instance) {
  editing.value = instance
  Object.assign(form, {
    name: instance.name,
    base_url: instance.base_url,
    username: instance.username,
    app_password: '',
    verify_tls: instance.verify_tls
  })
  formError.value = null
  modalOpen.value = true
}

async function save() {
  saving.value = true
  formError.value = null

  try {
    if (editing.value) {
      await api.put(`/api/admin/instances/${editing.value.uuid}`, form)
    } else {
      await api.post('/api/admin/instances', form)
    }

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

async function test(instance: Instance) {
  const result = await api.post<{ ok: boolean, message: string }>(
    `/api/admin/instances/${instance.uuid}/test`
  )

  toast.add({
    title: result.ok ? 'Verbindung steht' : 'Verbindung fehlgeschlagen',
    description: result.message,
    color: result.ok ? 'success' : 'error'
  })

  await load()
}

async function remove(instance: Instance) {
  if (!confirm(`Instanz "${instance.name}" mit ${instance.documents_count} indizierten Dokumenten entfernen? In der Nextcloud selbst wird dabei nichts verändert.`)) {
    return
  }

  await api.del(`/api/admin/instances/${instance.uuid}`)
  await load()
}

onMounted(load)
</script>

<template>
  <UContainer class="py-8 space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold">
          Nextcloud-Instanzen
        </h1>
        <p class="text-sm text-muted">
          NextSearch greift auf diese Instanzen ausschließlich lesend zu.
        </p>
      </div>

      <UButton
        icon="i-lucide-plus"
        label="Instanz hinzufügen"
        @click="openCreate"
      />
    </div>

    <UAlert
      color="info"
      variant="subtle"
      icon="i-lucide-info"
      title="Empfehlung zum Zugang"
      description="Hinterlegen Sie ein Nextcloud-App-Passwort eines eigens angelegten Kontos, kein Kontopasswort. Das Passwort wird verschlüsselt gespeichert und in der Oberfläche nie wieder angezeigt."
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
      v-else-if="instances.length === 0"
      class="rounded-lg border border-dashed border-default p-12 text-center"
    >
      <UIcon
        name="i-lucide-cloud-off"
        class="size-8 text-muted"
      />
      <p class="mt-2 text-sm text-muted">
        Noch keine Instanz hinterlegt.
      </p>
    </div>

    <div
      v-else
      class="space-y-3"
    >
      <UCard
        v-for="instance in instances"
        :key="instance.uuid"
      >
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <h2 class="font-medium">
                {{ instance.name }}
              </h2>
              <UBadge
                size="sm"
                variant="subtle"
                :color="instance.health_state === 'ok' ? 'success' : instance.health_state === 'failed' ? 'error' : 'neutral'"
                :label="instance.health_state === 'ok' ? 'erreichbar' : instance.health_state === 'failed' ? 'Fehler' : 'ungeprüft'"
              />
              <UBadge
                v-if="!instance.enabled"
                size="sm"
                color="neutral"
                variant="subtle"
                label="deaktiviert"
              />
            </div>

            <p class="text-sm text-muted break-all">
              {{ instance.base_url }} · Benutzer {{ instance.username }}
            </p>
            <p
              v-if="instance.health_message"
              class="text-xs text-muted mt-1"
            >
              {{ instance.health_message }}
              <template v-if="instance.health_checked_at">
                ({{ dateTime(instance.health_checked_at) }})
              </template>
            </p>
            <p class="text-xs text-muted mt-1">
              {{ instance.folders_count }} Ordner · {{ instance.documents_count.toLocaleString('de-DE') }} Dokumente
            </p>
          </div>

          <div class="flex gap-2">
            <UButton
              size="sm"
              color="neutral"
              variant="outline"
              icon="i-lucide-plug-zap"
              label="Testen"
              @click="test(instance)"
            />
            <UButton
              size="sm"
              color="neutral"
              variant="outline"
              icon="i-lucide-folder-plus"
              label="Ordner"
              :to="`/admin/folders?instance=${instance.uuid}`"
            />
            <UButton
              size="sm"
              color="neutral"
              variant="ghost"
              icon="i-lucide-pencil"
              @click="openEdit(instance)"
            />
            <UButton
              size="sm"
              color="error"
              variant="ghost"
              icon="i-lucide-trash-2"
              @click="remove(instance)"
            />
          </div>
        </div>
      </UCard>
    </div>

    <UModal
      v-model:open="modalOpen"
      :title="editing ? 'Instanz bearbeiten' : 'Instanz hinzufügen'"
    >
      <template #body>
        <form
          class="space-y-4"
          @submit.prevent="save"
        >
          <UFormField
            label="Bezeichnung"
            hint="Frei wählbar, taucht in den Suchergebnissen auf"
          >
            <UInput
              v-model="form.name"
              required
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Basis-URL"
            hint="z. B. https://cloud.example.de"
          >
            <UInput
              v-model="form.base_url"
              type="url"
              required
              class="w-full"
            />
          </UFormField>

          <UFormField label="Benutzername">
            <UInput
              v-model="form.username"
              required
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="App-Passwort"
            :hint="editing ? 'Leer lassen, um das gespeicherte Passwort zu behalten' : undefined"
          >
            <UInput
              v-model="form.app_password"
              type="password"
              autocomplete="new-password"
              :required="!editing"
              class="w-full"
            />
          </UFormField>

          <UCheckbox
            v-model="form.verify_tls"
            label="TLS-Zertifikat prüfen"
          />

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
              label="Speichern"
            />
          </div>
        </form>
      </template>
    </UModal>
  </UContainer>
</template>
