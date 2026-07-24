<script setup lang="ts">
const props = defineProps<{
  currentUrl: string | null
  uploadPath: string
  removePath: string
  label: string
}>()

const emit = defineEmits<{ changed: [] }>()

const api = useApi()
const toast = useToast()
const fileInput = ref<HTMLInputElement | null>(null)
const busy = ref(false)

// Ein Zeitstempel hängt an der Bild-URL, damit der Browser nach dem Austausch
// nicht das alte Bild aus dem Cache zeigt.
const version = ref(Date.now())
const displayUrl = computed(() =>
  props.currentUrl ? `${props.currentUrl}?v=${version.value}` : null
)

async function onPick(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  busy.value = true
  try {
    const form = new FormData()
    form.append('image', file)
    await api.upload(props.uploadPath, form)
    version.value = Date.now()
    emit('changed')
  } catch (e) {
    toast.add({
      title: 'Upload fehlgeschlagen',
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
    await api.del(props.removePath)
    emit('changed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex items-center gap-3">
    <div class="size-12 shrink-0 rounded-lg border border-default overflow-hidden bg-elevated flex items-center justify-center">
      <img
        v-if="displayUrl"
        :src="displayUrl"
        :alt="label"
        class="size-full object-cover"
      >
      <UIcon
        v-else
        name="i-lucide-image"
        class="size-5 text-muted"
      />
    </div>

    <div class="flex gap-2">
      <UButton
        size="xs"
        color="neutral"
        variant="outline"
        icon="i-lucide-upload"
        :label="currentUrl ? 'Bild ersetzen' : 'Bild hochladen'"
        :loading="busy"
        @click="fileInput?.click()"
      />
      <UButton
        v-if="currentUrl"
        size="xs"
        color="neutral"
        variant="ghost"
        icon="i-lucide-x"
        :loading="busy"
        @click="remove"
      />
    </div>

    <input
      ref="fileInput"
      type="file"
      accept="image/png,image/jpeg,image/webp,image/gif"
      class="hidden"
      @change="onPick"
    >
  </div>
</template>
