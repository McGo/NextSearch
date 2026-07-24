<script setup lang="ts">
const { t } = useI18n()
useHead({ title: () => t('admin.settings.title') })

const config = useRuntimeConfig()
const api = useApi()
const toast = useToast()
const { state: branding, refresh } = useBranding()

const siteName = computed(() => branding.value.site_name || config.public.appName)

// Site name field, kept in sync with the loaded value.
const nameInput = ref('')
watch(
  () => branding.value.site_name,
  (value) => {
    nameInput.value = value ?? ''
  },
  { immediate: true }
)
const savingName = ref(false)

async function saveName() {
  savingName.value = true
  try {
    await api.put('/api/admin/branding/name', { name: nameInput.value.trim() })
    await refresh()
    toast.add({ title: t('admin.settings.name.saved'), color: 'success' })
  } catch {
    toast.add({ title: t('admin.settings.name.failed'), color: 'error' })
  } finally {
    savingName.value = false
  }
}

// Logo upload.
const fileInput = ref<HTMLInputElement | null>(null)
const busy = ref(false)

async function onPick(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  busy.value = true
  try {
    const form = new FormData()
    form.append('image', file)
    await api.upload('/api/admin/branding/logo', form)
    await refresh()
    toast.add({ title: t('admin.settings.logo.saved'), color: 'success' })
  } catch (e) {
    toast.add({
      title: t('admin.settings.logo.failed'),
      description: e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] || e.message) : undefined,
      color: 'error'
    })
  } finally {
    busy.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

async function removeLogo() {
  busy.value = true
  try {
    await api.del('/api/admin/branding/logo')
    await refresh()
    toast.add({ title: t('admin.settings.logo.removed'), color: 'success' })
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <UContainer class="py-8 space-y-6">
    <div>
      <h1 class="text-xl font-semibold">
        {{ t('admin.settings.title') }}
      </h1>
      <p class="text-sm text-muted">
        {{ t('admin.settings.subtitle') }}
      </p>
    </div>

    <section class="rounded-xl border border-default p-4 sm:p-6 space-y-4">
      <div>
        <h2 class="font-medium">
          {{ t('admin.settings.brand.title') }}
        </h2>
        <p class="text-sm text-muted">
          {{ t('admin.settings.brand.desc') }}
        </p>
      </div>

      <!-- Live preview of the header lockup, on light and on dark. -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="rounded-lg border border-default bg-white p-4 flex items-center h-16">
          <span class="flex items-center gap-2 font-semibold text-neutral-900">
            <img
              v-if="branding.logo_url"
              :src="branding.logo_url"
              alt=""
              class="h-7 w-auto max-w-[160px] object-contain"
            >
            <UIcon
              v-else
              name="i-lucide-file-search"
              class="size-5 text-blue-600"
            />
            {{ siteName }}
          </span>
        </div>
        <div class="rounded-lg border border-default bg-neutral-900 p-4 flex items-center h-16">
          <span class="flex items-center gap-2 font-semibold text-white">
            <img
              v-if="branding.logo_url"
              :src="branding.logo_url"
              alt=""
              class="h-7 w-auto max-w-[160px] object-contain"
            >
            <UIcon
              v-else
              name="i-lucide-file-search"
              class="size-5 text-blue-500"
            />
            {{ siteName }}
          </span>
        </div>
      </div>

      <!-- Site name -->
      <UFormField
        :label="t('admin.settings.name.label')"
        :hint="t('admin.settings.name.hint')"
      >
        <div class="flex gap-2">
          <UInput
            v-model="nameInput"
            :placeholder="config.public.appName"
            maxlength="60"
            class="flex-1"
            @keydown.enter="saveName"
          />
          <UButton
            color="neutral"
            :loading="savingName"
            :label="t('common.save')"
            @click="saveName"
          />
        </div>
      </UFormField>

      <!-- Logo -->
      <UFormField
        :label="t('admin.settings.logo.label')"
        :hint="t('admin.settings.logo.hint')"
      >
        <div class="flex items-center gap-2">
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-upload"
            :label="branding.has_logo ? t('image.replace') : t('image.upload')"
            :loading="busy"
            @click="fileInput?.click()"
          />
          <UButton
            v-if="branding.has_logo"
            color="neutral"
            variant="ghost"
            icon="i-lucide-trash-2"
            :label="t('admin.settings.logo.reset')"
            :loading="busy"
            @click="removeLogo"
          />
        </div>
      </UFormField>

      <input
        ref="fileInput"
        type="file"
        accept="image/png,image/jpeg,image/webp"
        class="hidden"
        @change="onPick"
      >
    </section>
  </UContainer>
</template>
