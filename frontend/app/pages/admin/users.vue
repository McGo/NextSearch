<script setup lang="ts">
useHead({ title: 'Benutzer' })

interface ManagedUser {
  id: number
  name: string
  email: string
  role: 'admin' | 'user'
  is_admin: boolean
  created_at: string | null
  folders: { uuid: string, label: string }[]
}

interface FolderOption {
  uuid: string
  label: string
  instance: { name: string }
}

const api = useApi()
const toast = useToast()
const { user: currentUser } = useAuth()

const users = ref<ManagedUser[]>([])
const folders = ref<FolderOption[]>([])
const pending = ref(true)

const userModal = ref(false)
const shareModal = ref(false)
const editing = ref<ManagedUser | null>(null)
const saving = ref(false)
const formError = ref<string | null>(null)

const form = reactive({ name: '', email: '', password: '', role: 'user' as 'admin' | 'user' })
const selectedFolders = ref<string[]>([])

async function load() {
  pending.value = true
  try {
    const [userResponse, folderResponse] = await Promise.all([
      api.get<{ users: ManagedUser[] }>('/api/admin/users'),
      api.get<{ folders: FolderOption[] }>('/api/admin/folders')
    ])

    users.value = userResponse.users
    folders.value = folderResponse.folders
  } finally {
    pending.value = false
  }
}

function openCreate() {
  editing.value = null
  Object.assign(form, { name: '', email: '', password: '', role: 'user' })
  formError.value = null
  userModal.value = true
}

function openEdit(user: ManagedUser) {
  editing.value = user
  Object.assign(form, { name: user.name, email: user.email, password: '', role: user.role })
  formError.value = null
  userModal.value = true
}

function openShares(user: ManagedUser) {
  editing.value = user
  selectedFolders.value = user.folders.map(folder => folder.uuid)
  shareModal.value = true
}

async function save() {
  saving.value = true
  formError.value = null

  try {
    if (editing.value) {
      await api.put(`/api/admin/users/${editing.value.id}`, form)
    } else {
      await api.post('/api/admin/users', form)
    }

    userModal.value = false
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError
      ? (Object.values(e.errors)[0]?.[0] || e.message)
      : 'Speichern fehlgeschlagen.'
  } finally {
    saving.value = false
  }
}

async function saveShares() {
  saving.value = true

  try {
    await api.put(`/api/admin/users/${editing.value!.id}/folders`, {
      folders: selectedFolders.value
    })

    shareModal.value = false
    await load()
    toast.add({ title: 'Freigaben gespeichert.', color: 'success' })
  } finally {
    saving.value = false
  }
}

async function remove(user: ManagedUser) {
  if (!confirm(`Konto "${user.email}" löschen?`)) return

  try {
    await api.del(`/api/admin/users/${user.id}`)
    await load()
  } catch (e) {
    toast.add({
      title: 'Löschen nicht möglich',
      description: e instanceof ApiError ? e.message : undefined,
      color: 'error'
    })
  }
}

function toggleFolder(uuid: string) {
  selectedFolders.value = selectedFolders.value.includes(uuid)
    ? selectedFolders.value.filter(value => value !== uuid)
    : [...selectedFolders.value, uuid]
}

onMounted(load)
</script>

<template>
  <UContainer class="py-8 space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold">
          Benutzer
        </h1>
        <p class="text-sm text-muted">
          Konten und Ordnerfreigaben in NextSearch.
        </p>
      </div>

      <UButton
        icon="i-lucide-user-plus"
        label="Benutzer anlegen"
        @click="openCreate"
      />
    </div>

    <UAlert
      color="warning"
      variant="subtle"
      icon="i-lucide-shield-alert"
      title="Freigaben gelten unabhängig von der Nextcloud"
      description="NextSearch bildet die Dateirechte der Nextcloud nicht nach. Wer hier einen Ordner zugewiesen bekommt, sieht dessen Inhalte im Volltext und kann die Originale öffnen — auch wenn die Nextcloud selbst ihm den Zugriff verweigern würde."
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
      v-else
      class="space-y-3"
    >
      <UCard
        v-for="user in users"
        :key="user.id"
      >
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <h2 class="font-medium">
                {{ user.name }}
              </h2>
              <UBadge
                size="sm"
                variant="subtle"
                :color="user.is_admin ? 'primary' : 'neutral'"
                :label="user.is_admin ? 'Administrator' : 'Benutzer'"
              />
              <UBadge
                v-if="user.id === currentUser?.id"
                size="sm"
                color="neutral"
                variant="outline"
                label="Sie"
              />
            </div>

            <p class="text-sm text-muted">
              {{ user.email }}
            </p>
            <p class="text-xs text-muted mt-1">
              <template v-if="user.is_admin">
                Sieht alle Ordner.
              </template>
              <template v-else-if="user.folders.length === 0">
                Kein Ordner freigegeben — die Suche bleibt leer.
              </template>
              <template v-else>
                Freigegeben: {{ user.folders.map(f => f.label).join(', ') }}
              </template>
            </p>
          </div>

          <div class="flex gap-2">
            <UButton
              v-if="!user.is_admin"
              size="sm"
              color="neutral"
              variant="outline"
              icon="i-lucide-folder-key"
              label="Freigaben"
              @click="openShares(user)"
            />
            <UButton
              size="sm"
              color="neutral"
              variant="ghost"
              icon="i-lucide-pencil"
              @click="openEdit(user)"
            />
            <UButton
              v-if="user.id !== currentUser?.id"
              size="sm"
              color="error"
              variant="ghost"
              icon="i-lucide-trash-2"
              @click="remove(user)"
            />
          </div>
        </div>
      </UCard>
    </div>

    <UModal
      v-model:open="userModal"
      :title="editing ? 'Benutzer bearbeiten' : 'Benutzer anlegen'"
    >
      <template #body>
        <form
          class="space-y-4"
          @submit.prevent="save"
        >
          <UFormField label="Name">
            <UInput
              v-model="form.name"
              required
              class="w-full"
            />
          </UFormField>

          <UFormField label="E-Mail">
            <UInput
              v-model="form.email"
              type="email"
              required
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Passwort"
            :hint="editing ? 'Leer lassen, um es unverändert zu lassen' : 'Mindestens zwölf Zeichen'"
          >
            <UInput
              v-model="form.password"
              type="password"
              autocomplete="new-password"
              :required="!editing"
              class="w-full"
            />
          </UFormField>

          <UFormField label="Rolle">
            <USelect
              v-model="form.role"
              :items="[
                { label: 'Benutzer — sieht nur freigegebene Ordner', value: 'user' },
                { label: 'Administrator — sieht und verwaltet alles', value: 'admin' }
              ]"
              value-key="value"
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
              @click="userModal = false"
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

    <UModal
      v-model:open="shareModal"
      :title="`Freigaben für ${editing?.name}`"
    >
      <template #body>
        <div class="space-y-4">
          <p
            v-if="folders.length === 0"
            class="text-sm text-muted"
          >
            Es gibt noch keine überwachten Ordner.
          </p>

          <ul
            v-else
            class="divide-y divide-default rounded-lg border border-default max-h-80 overflow-y-auto"
          >
            <li
              v-for="folder in folders"
              :key="folder.uuid"
            >
              <button
                type="button"
                class="w-full flex items-center gap-3 px-3 py-2 text-left text-sm hover:bg-elevated"
                @click="toggleFolder(folder.uuid)"
              >
                <UIcon
                  :name="selectedFolders.includes(folder.uuid) ? 'i-lucide-check-square' : 'i-lucide-square'"
                  class="size-4 shrink-0"
                  :class="selectedFolders.includes(folder.uuid) ? 'text-primary' : 'text-muted'"
                />
                <span class="truncate">{{ folder.label }}</span>
                <span class="ml-auto text-xs text-muted shrink-0">{{ folder.instance.name }}</span>
              </button>
            </li>
          </ul>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              label="Abbrechen"
              @click="shareModal = false"
            />
            <UButton
              :loading="saving"
              label="Speichern"
              @click="saveShares"
            />
          </div>
        </div>
      </template>
    </UModal>
  </UContainer>
</template>
