<script setup lang="ts">
const { t } = useI18n()
useHead({ title: () => t('admin.settings.title') })

const api = useApi()
const toast = useToast()
const { state: branding, refresh } = useBranding()

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

async function remove() {
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
          {{ t('admin.settings.logo.title') }}
        </h2>
        <p class="text-sm text-muted">
          {{ t('admin.settings.logo.desc') }}
        </p>
      </div>

      <!-- Preview on light and on dark, the way the header renders it. -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="rounded-lg border border-default bg-white p-4 flex items-center h-16">
          <img
            v-if="branding.logo_url"
            :src="branding.logo_url"
            alt=""
            class="h-7 w-auto max-w-[200px] object-contain"
          >
          <span
            v-else
            class="flex items-center gap-2 font-semibold text-neutral-900"
          >
            <UIcon
              name="i-lucide-file-search"
              class="size-5 text-blue-600"
            />
            NextSearch
          </span>
        </div>
        <div class="rounded-lg border border-default bg-neutral-900 p-4 flex items-center h-16">
          <img
            v-if="branding.logo_url"
            :src="branding.logo_url"
            alt=""
            class="h-7 w-auto max-w-[200px] object-contain"
          >
          <span
            v-else
            class="flex items-center gap-2 font-semibold text-white"
          >
            <UIcon
              name="i-lucide-file-search"
              class="size-5 text-blue-500"
            />
            NextSearch
          </span>
        </div>
      </div>

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
          @click="remove"
        />
      </div>

      <p class="text-xs text-muted">
        {{ t('admin.settings.logo.hint') }}
      </p>

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
