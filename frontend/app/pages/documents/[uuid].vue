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

/**
 * Formate, die die App selbst rendert (siehe DocumentController::content):
 * Markdown gerendert, E-Mail als Kopf plus Text.
 */
const RENDERED_EXTENSIONS = ['md', 'markdown', 'eml', 'msg', 'txt']

interface RenderedContent {
  type: 'markdown' | 'email' | 'text'
  html?: string
  text?: string
  from?: string | null
  to?: string | null
  subject?: string | null
  date?: string | null
  body?: string
}

const rendered = ref<RenderedContent | null>(null)
const contentPending = ref(false)

// Nur rendern, was der Browser nicht ohnehin selbst einbettet (txt streamt
// als text/plain bereits inline).
const renderInApp = computed(() =>
  !embeddable.value && RENDERED_EXTENSIONS.includes(document.value?.extension ?? '')
)

onMounted(async () => {
  try {
    const response = await api.get<{ document: DocumentDetail }>(`/api/documents/${uuid}`)
    document.value = response.document
    useHead({ title: response.document.name })

    if (renderInApp.value) {
      contentPending.value = true
      try {
        rendered.value = await api.get<RenderedContent>(`/api/documents/${uuid}/content`)
      } catch {
        // Kein Beinbruch — der Download-Knopf bleibt.
        rendered.value = null
      } finally {
        contentPending.value = false
      }
    }
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

        <!-- Von der App gerenderte Formate: Markdown und E-Mail. -->
        <div
          v-else-if="renderInApp"
          class="rounded-lg border border-default bg-elevated/40 p-5 min-h-40"
        >
          <div
            v-if="contentPending"
            class="flex justify-center py-16"
          >
            <UIcon
              name="i-lucide-loader-circle"
              class="animate-spin size-5 text-muted"
            />
          </div>

          <!-- E-Mail: Kopf plus Textkörper. Der Body ist reiner Text, deshalb
               ungefährlich. -->
          <div
            v-else-if="rendered?.type === 'email'"
            class="space-y-4"
          >
            <dl class="space-y-1 text-sm border-b border-default pb-3">
              <div
                v-if="rendered.from"
                class="flex gap-2"
              >
                <dt class="w-20 shrink-0 text-muted">
                  Von
                </dt>
                <dd class="min-w-0 break-words">
                  {{ rendered.from }}
                </dd>
              </div>
              <div
                v-if="rendered.to"
                class="flex gap-2"
              >
                <dt class="w-20 shrink-0 text-muted">
                  An
                </dt>
                <dd class="min-w-0 break-words">
                  {{ rendered.to }}
                </dd>
              </div>
              <div
                v-if="rendered.subject"
                class="flex gap-2"
              >
                <dt class="w-20 shrink-0 text-muted">
                  Betreff
                </dt>
                <dd class="min-w-0 break-words font-medium">
                  {{ rendered.subject }}
                </dd>
              </div>
              <div
                v-if="rendered.date"
                class="flex gap-2"
              >
                <dt class="w-20 shrink-0 text-muted">
                  Datum
                </dt>
                <dd class="min-w-0 break-words">
                  {{ rendered.date }}
                </dd>
              </div>
            </dl>
            <pre class="whitespace-pre-wrap break-words font-sans text-sm leading-relaxed">{{ rendered.body }}</pre>
          </div>

          <!-- Markdown: im Backend HTML-sicher gerendert (kein rohes HTML,
               keine unsicheren Links), darf hier ohne weitere Bereinigung in
               ein v-html. -->
          <!-- eslint-disable-next-line vue/no-v-html -->
          <div
            v-else-if="rendered?.type === 'markdown'"
            class="md-body"
            v-html="rendered.html"
          />

          <UAlert
            v-else
            color="neutral"
            variant="subtle"
            icon="i-lucide-download"
            title="Vorschau nicht verfügbar"
            description="Der Inhalt ließ sich nicht darstellen. Über den Knopf rechts laden Sie das Original herunter."
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

<style scoped>
/* Schlichte Auszeichnung für gerendertes Markdown — bewusst ohne
   Typography-Plugin, damit keine zusätzliche Abhängigkeit nötig ist. */
.md-body {
  font-size: 0.9rem;
  line-height: 1.6;
}
.md-body :deep(h1),
.md-body :deep(h2),
.md-body :deep(h3) {
  font-weight: 600;
  line-height: 1.3;
  margin: 1.2em 0 0.5em;
}
.md-body :deep(h1) { font-size: 1.4em; }
.md-body :deep(h2) { font-size: 1.2em; }
.md-body :deep(h3) { font-size: 1.05em; }
.md-body :deep(p),
.md-body :deep(ul),
.md-body :deep(ol),
.md-body :deep(blockquote),
.md-body :deep(table) {
  margin: 0.6em 0;
}
.md-body :deep(ul),
.md-body :deep(ol) {
  padding-left: 1.4em;
}
.md-body :deep(ul) { list-style: disc; }
.md-body :deep(ol) { list-style: decimal; }
.md-body :deep(a) {
  color: var(--ui-primary);
  text-decoration: underline;
}
.md-body :deep(code) {
  font-family: ui-monospace, monospace;
  font-size: 0.85em;
  background: var(--ui-bg-elevated);
  padding: 0.1em 0.35em;
  border-radius: 0.25rem;
}
.md-body :deep(pre) {
  background: var(--ui-bg-elevated);
  padding: 0.8em 1em;
  border-radius: 0.5rem;
  overflow-x: auto;
}
.md-body :deep(pre code) {
  background: transparent;
  padding: 0;
}
.md-body :deep(blockquote) {
  border-left: 3px solid var(--ui-border);
  padding-left: 1em;
  color: var(--ui-text-muted);
}
.md-body :deep(table) {
  border-collapse: collapse;
  width: 100%;
}
.md-body :deep(th),
.md-body :deep(td) {
  border: 1px solid var(--ui-border);
  padding: 0.4em 0.6em;
  text-align: left;
}
</style>
