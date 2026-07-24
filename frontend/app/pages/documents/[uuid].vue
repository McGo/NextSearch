<script setup lang="ts">
interface DocumentDetail {
  uuid: string
  name: string
  path: string
  directory: string
  extension: string | null
  mime_type: string | null
  size: number
  modified_at: string | null
  indexed_at: string | null
  page_count: number | null
  ocr_used: boolean
  metadata: Record<string, string | number> | null
  has_preview: boolean
  instance: { name: string, uuid: string }
  folder: { label: string, uuid: string }
  nextcloud_url: string
}

const route = useRoute()
const api = useApi()
const { bytes, dateTime, icon } = useFormat()

const uuid = route.params.uuid as string
const document = ref<DocumentDetail | null>(null)
const error = ref<string | null>(null)
const pending = ref(true)

const rawUrl = computed(() => `/api/documents/${uuid}/raw`)

/**
 * Muss zur Whitelist in DocumentController::INLINE_TYPES passen — alles andere
 * liefert das Backend als Download aus, eine Einbettung liefe ins Leere.
 */
const INLINE_TYPES = [
  'application/pdf',
  'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp',
  'text/plain'
]

const embeddable = computed(() => INLINE_TYPES.includes(document.value?.mime_type ?? ''))

onMounted(async () => {
  try {
    const response = await api.get<{ document: DocumentDetail }>(`/api/documents/${uuid}`)
    document.value = response.document
    useHead({ title: response.document.name })
  } catch (e) {
    error.value = e instanceof ApiError
      ? e.message
      : 'Das Dokument konnte nicht geladen werden.'
  } finally {
    pending.value = false
  }
})

const metadataRows = computed(() => {
  const doc = document.value
  if (!doc) return []

  return [
    ['Ordner', `${doc.instance.name} · ${doc.folder.label}`],
    ['Pfad', doc.directory || '/'],
    ['Typ', doc.mime_type || doc.extension || 'unbekannt'],
    ['Größe', bytes(doc.size)],
    ['Geändert', dateTime(doc.modified_at)],
    ['Indiziert', dateTime(doc.indexed_at)],
    ...(doc.page_count ? [['Seiten', String(doc.page_count)]] : []),
    ...(doc.metadata?.author ? [['Autor', String(doc.metadata.author)]] : []),
    ...(doc.metadata?.title ? [['Titel', String(doc.metadata.title)]] : []),
    ...(doc.metadata?.mail_from ? [['Absender', String(doc.metadata.mail_from)]] : []),
    ...(doc.metadata?.mail_to ? [['Empfänger', String(doc.metadata.mail_to)]] : [])
  ] as [string, string][]
})
</script>

<template>
  <UContainer class="py-8">
    <UButton
      to="/"
      color="neutral"
      variant="ghost"
      icon="i-lucide-arrow-left"
      label="Zurück zur Suche"
      class="mb-4"
    />

    <div
      v-if="pending"
      class="flex justify-center py-24"
    >
      <UIcon
        name="i-lucide-loader-circle"
        class="animate-spin size-6 text-muted"
      />
    </div>

    <UAlert
      v-else-if="error"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :description="error"
    />

    <div
      v-else-if="document"
      class="grid grid-cols-1 lg:grid-cols-[1fr_20rem] gap-8"
    >
      <section class="min-w-0 space-y-4">
        <div class="flex items-start gap-3">
          <UIcon
            :name="icon(document.extension)"
            class="size-6 shrink-0 text-muted mt-0.5"
          />
          <div class="min-w-0">
            <h1 class="text-lg font-semibold break-words">
              {{ document.name }}
            </h1>
            <p class="text-sm text-muted break-all">
              {{ document.path }}
            </p>
          </div>
        </div>

        <!-- Das Original wird vom Backend durchgereicht; die Zugangsdaten der
             Nextcloud bleiben dort. -->
        <div
          v-if="embeddable"
          class="rounded-lg border border-default overflow-hidden bg-elevated"
        >
          <img
            v-if="document.mime_type?.startsWith('image/')"
            :src="rawUrl"
            :alt="document.name"
            class="w-full h-auto"
          >
          <iframe
            v-else
            :src="rawUrl"
            :title="document.name"
            class="w-full h-[75vh] bg-white"
          />
        </div>

        <UAlert
          v-else
          color="neutral"
          variant="subtle"
          icon="i-lucide-download"
          title="Keine Vorschau im Browser"
          :description="`Dateien vom Typ ${document.extension?.toUpperCase() || 'unbekannt'} lassen sich hier nicht darstellen. Über den Knopf rechts laden Sie das Original herunter.`"
        />
      </section>

      <aside class="space-y-4">
        <div class="flex flex-col gap-2">
          <UButton
            :to="rawUrl"
            target="_blank"
            icon="i-lucide-external-link"
            label="Original öffnen"
            block
          />
          <UButton
            :to="`${rawUrl}?download=1`"
            color="neutral"
            variant="outline"
            icon="i-lucide-download"
            label="Herunterladen"
            block
          />
          <UButton
            :to="document.nextcloud_url"
            target="_blank"
            color="neutral"
            variant="ghost"
            icon="i-lucide-cloud"
            label="In Nextcloud anzeigen"
            block
          />
        </div>

        <UAlert
          v-if="document.ocr_used"
          color="warning"
          variant="subtle"
          icon="i-lucide-scan-text"
          title="Text stammt aus einer Texterkennung"
          description="Das Dokument hatte keinen Textlayer. Erkennungsfehler sind möglich."
        />

        <UCard :ui="{ body: 'p-0 sm:p-0' }">
          <dl class="divide-y divide-default text-sm">
            <div
              v-for="[label, value] in metadataRows"
              :key="label"
              class="flex gap-3 px-4 py-2"
            >
              <dt class="w-24 shrink-0 text-muted">
                {{ label }}
              </dt>
              <dd class="min-w-0 break-words">
                {{ value }}
              </dd>
            </div>
          </dl>
        </UCard>
      </aside>
    </div>
  </UContainer>
</template>
