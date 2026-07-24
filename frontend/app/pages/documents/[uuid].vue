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
const { t } = useI18n()
const { bytes, dateTime, icon } = useFormat()

const uuid = route.params.uuid as string
const document = ref<DocumentDetail | null>(null)
const error = ref<string | null>(null)
const pending = ref(true)

const rawUrl = computed(() => `/api/documents/${uuid}/raw`)

/**
 * Must match the whitelist in DocumentController::INLINE_TYPES — everything else
 * the backend serves as a download, and embedding it would go nowhere.
 */
const INLINE_TYPES = [
  'application/pdf',
  'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp',
  'text/plain'
]

const embeddable = computed(() => INLINE_TYPES.includes(document.value?.mime_type ?? ''))

/**
 * Formats the app renders itself (see DocumentController::content): Markdown
 * rendered, email as headers plus text.
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

// Only render what the browser doesn't embed anyway (txt already streams inline
// as text/plain).
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
        // No harm — the download button remains.
        rendered.value = null
      } finally {
        contentPending.value = false
      }
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('document.loadFailed')
  } finally {
    pending.value = false
  }
})

const metadataRows = computed(() => {
  const doc = document.value
  if (!doc) return []

  return [
    [t('document.meta.folder'), `${doc.instance.name} · ${doc.folder.label}`],
    [t('document.meta.path'), doc.directory || '/'],
    [t('document.meta.type'), doc.mime_type || doc.extension || t('document.meta.unknown')],
    [t('document.meta.size'), bytes(doc.size)],
    [t('document.meta.modified'), dateTime(doc.modified_at)],
    [t('document.meta.indexed'), dateTime(doc.indexed_at)],
    ...(doc.page_count ? [[t('document.meta.pages'), String(doc.page_count)]] : []),
    ...(doc.metadata?.author ? [[t('document.meta.author'), String(doc.metadata.author)]] : []),
    ...(doc.metadata?.title ? [[t('document.meta.title'), String(doc.metadata.title)]] : []),
    ...(doc.metadata?.mail_from ? [[t('document.meta.sender'), String(doc.metadata.mail_from)]] : []),
    ...(doc.metadata?.mail_to ? [[t('document.meta.recipient'), String(doc.metadata.mail_to)]] : [])
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
      :label="t('document.back')"
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

        <!-- The original is streamed by the backend; the Nextcloud credentials
             stay there. -->
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

        <!-- Formats the app renders: Markdown and email. -->
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

          <!-- Email: headers plus body. The body is plain text, hence safe. -->
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
                  {{ t('document.email.from') }}
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
                  {{ t('document.email.to') }}
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
                  {{ t('document.email.subject') }}
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
                  {{ t('document.email.date') }}
                </dt>
                <dd class="min-w-0 break-words">
                  {{ rendered.date }}
                </dd>
              </div>
            </dl>
            <pre class="whitespace-pre-wrap break-words font-sans text-sm leading-relaxed">{{ rendered.body }}</pre>
          </div>

          <!-- Markdown: rendered HTML-safe in the backend (no raw HTML, no unsafe
               links), so it may go into a v-html without further sanitising. -->
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
            :title="t('document.unavailableTitle')"
            :description="t('document.unavailableDesc')"
          />
        </div>

        <UAlert
          v-else
          color="neutral"
          variant="subtle"
          icon="i-lucide-download"
          :title="t('document.noPreviewTitle')"
          :description="t('document.noPreviewDesc', { type: document.extension?.toUpperCase() || t('document.meta.unknown') })"
        />
      </section>

      <aside class="space-y-4">
        <div class="flex flex-col gap-2">
          <UButton
            :to="rawUrl"
            target="_blank"
            icon="i-lucide-external-link"
            :label="t('document.openOriginal')"
            block
          />
          <UButton
            :to="`${rawUrl}?download=1`"
            color="neutral"
            variant="outline"
            icon="i-lucide-download"
            :label="t('document.download')"
            block
          />
          <UButton
            :to="document.nextcloud_url"
            target="_blank"
            color="neutral"
            variant="ghost"
            icon="i-lucide-cloud"
            :label="t('document.openInNextcloud')"
            block
          />
        </div>

        <UAlert
          v-if="document.ocr_used"
          color="warning"
          variant="subtle"
          icon="i-lucide-scan-text"
          :title="t('document.ocrTitle')"
          :description="t('document.ocrDesc')"
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
/* Plain styling for rendered Markdown — deliberately without the typography
   plugin, so no extra dependency is needed. */
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
