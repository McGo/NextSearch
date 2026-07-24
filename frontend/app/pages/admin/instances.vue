<script setup lang="ts">
const { t } = useI18n()
useHead({ title: () => t('admin.instances.title') })

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
  image_url: string | null
  folders_count: number
  documents_count: number
}

const api = useApi()
const toast = useToast()
const { dateTime, number } = useFormat()

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
      : t('admin.instances.form.saveFailed')
  } finally {
    saving.value = false
  }
}

async function test(instance: Instance) {
  const result = await api.post<{ ok: boolean, message: string }>(
    `/api/admin/instances/${instance.uuid}/test`
  )

  toast.add({
    title: result.ok ? t('admin.instances.testOk') : t('admin.instances.testFailed'),
    description: result.message,
    color: result.ok ? 'success' : 'error'
  })

  await load()
}

async function remove(instance: Instance) {
  if (!confirm(t('admin.instances.deleteConfirm', { name: instance.name, documents: instance.documents_count }))) {
    return
  }

  await api.del(`/api/admin/instances/${instance.uuid}`)
  await load()
}

function healthLabel(state: Instance['health_state']) {
  return state === 'ok'
    ? t('admin.instances.healthOk')
    : state === 'failed' ? t('admin.instances.healthFailed') : t('admin.instances.healthUnknown')
}

onMounted(load)
</script>

<template>
  <UContainer class="py-8 space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold">
          {{ t('admin.instances.title') }}
        </h1>
        <p class="text-sm text-muted">
          {{ t('admin.instances.subtitle') }}
        </p>
      </div>

      <UButton
        icon="i-lucide-plus"
        :label="t('admin.instances.add')"
        @click="openCreate"
      />
    </div>

    <UAlert
      color="info"
      variant="subtle"
      icon="i-lucide-info"
      :title="t('admin.instances.tipTitle')"
      :description="t('admin.instances.tipDesc')"
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
        {{ t('admin.instances.none') }}
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
                :label="healthLabel(instance.health_state)"
              />
              <UBadge
                v-if="!instance.enabled"
                size="sm"
                color="neutral"
                variant="subtle"
                :label="t('admin.instances.disabled')"
              />
            </div>

            <p class="text-sm text-muted break-all">
              {{ instance.base_url }} · {{ t('admin.instances.userLabel', { name: instance.username }) }}
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
              {{ t('admin.instances.counts', { folders: instance.folders_count, documents: number(instance.documents_count) }) }}
            </p>

            <DirectoryImageUpload
              class="mt-3"
              :label="instance.name"
              :current-url="instance.image_url"
              :upload-path="`/api/admin/instances/${instance.uuid}/image`"
              :remove-path="`/api/admin/instances/${instance.uuid}/image`"
              @changed="load"
            />
          </div>

          <div class="flex gap-2">
            <UButton
              size="sm"
              color="neutral"
              variant="outline"
              icon="i-lucide-plug-zap"
              :label="t('admin.instances.test')"
              @click="test(instance)"
            />
            <UButton
              size="sm"
              color="neutral"
              variant="outline"
              icon="i-lucide-folder-plus"
              :label="t('admin.instances.foldersBtn')"
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
      :title="editing ? t('admin.instances.form.editTitle') : t('admin.instances.form.addTitle')"
    >
      <template #body>
        <form
          class="space-y-4"
          @submit.prevent="save"
        >
          <UFormField
            :label="t('admin.instances.form.name')"
            :hint="t('admin.instances.form.nameHint')"
          >
            <UInput
              v-model="form.name"
              required
              class="w-full"
            />
          </UFormField>

          <UFormField
            :label="t('admin.instances.form.baseUrl')"
            :hint="t('admin.instances.form.baseUrlHint')"
          >
            <UInput
              v-model="form.base_url"
              type="url"
              required
              class="w-full"
            />
          </UFormField>

          <UFormField :label="t('admin.instances.form.username')">
            <UInput
              v-model="form.username"
              required
              class="w-full"
            />
          </UFormField>

          <UFormField
            :label="t('admin.instances.form.appPassword')"
            :hint="editing ? t('admin.instances.form.appPasswordHint') : undefined"
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
            :label="t('admin.instances.form.verifyTls')"
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
              :label="t('common.cancel')"
              @click="modalOpen = false"
            />
            <UButton
              type="submit"
              :loading="saving"
              :label="t('common.save')"
            />
          </div>
        </form>
      </template>
    </UModal>
  </UContainer>
</template>
