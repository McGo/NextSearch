<script setup lang="ts">
const { t } = useI18n()
useHead({ title: () => t('admin.status.title') })

interface IndexRun {
  uuid: string
  state: 'running' | 'completed' | 'failed'
  trigger: string
  full: boolean
  folder: string
  instance: string
  files_seen: number
  files_new: number
  files_updated: number
  files_removed: number
  files_skipped: number
  files_failed: number
  pending_jobs: number
  errors: { path: string, message: string }[]
  started_at: string | null
  finished_at: string | null
}

interface Status {
  runs: IndexRun[]
  documents: Record<string, number>
  queues: { crawl: number, process: number }
  services: { tika: boolean, search: { numberOfDocuments?: number, isIndexing?: boolean } }
}

const api = useApi()
const toast = useToast()
const { dateTime, number } = useFormat()

const status = ref<Status | null>(null)
const pending = ref(true)
const expanded = ref<string | null>(null)

// Clearing a stuck queue — the target ('crawl' | 'process') doubles as the
// open state of the confirmation modal.
const clearTarget = ref<'crawl' | 'process' | null>(null)
const clearing = ref(false)

const clearModalOpen = computed({
  get: () => clearTarget.value !== null,
  set: (open: boolean) => { if (!open) clearTarget.value = null }
})

const clearTargetLabel = computed(() =>
  clearTarget.value === 'crawl'
    ? t('admin.status.crawlStage.title')
    : t('admin.status.processStage.title')
)

const clearTargetCount = computed(() =>
  clearTarget.value ? (status.value?.queues[clearTarget.value] ?? 0) : 0
)

async function confirmClear() {
  if (!clearTarget.value) return
  clearing.value = true

  try {
    const res = await api.post<{ removed: number }>(`/api/admin/queues/${clearTarget.value}/clear`)
    toast.add({ title: t('admin.status.cleared', { count: res.removed }), color: 'success' })
    clearTarget.value = null
    await load()
  } catch (e) {
    toast.add({
      title: e instanceof ApiError ? e.message : t('admin.status.clearFailed'),
      color: 'error'
    })
  } finally {
    clearing.value = false
  }
}

// Whole-index maintenance — clear or rebuild, each behind a confirmation.
const indexAction = ref<'clear' | 'rebuild' | null>(null)
const indexBusy = ref(false)

const indexModalOpen = computed({
  get: () => indexAction.value !== null,
  set: (open: boolean) => { if (!open) indexAction.value = null }
})

async function confirmIndexAction() {
  if (!indexAction.value) return
  indexBusy.value = true

  try {
    const res = await api.post<{ message: string }>(`/api/admin/index/${indexAction.value}`)
    toast.add({ title: res.message, color: 'success' })
    indexAction.value = null
    await load()
  } catch (e) {
    toast.add({
      title: e instanceof ApiError ? e.message : t('admin.status.indexActionFailed'),
      color: 'error'
    })
  } finally {
    indexBusy.value = false
  }
}

async function load() {
  try {
    status.value = await api.get<Status>('/api/admin/status')
  } finally {
    pending.value = false
  }
}

// While something is running, a glance every five seconds is worthwhile.
let timer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  load()
  timer = setInterval(load, 5000)
})

onUnmounted(() => clearInterval(timer))

function docStateLabel(state: string): string {
  const known = ['pending', 'indexed', 'failed', 'skipped']
  return known.includes(state) ? t(`admin.status.docState.${state}`) : state
}

function runStateLabel(state: IndexRun['state']): string {
  return t(`admin.status.runState.${state}`)
}
</script>

<template>
  <UContainer class="py-8 space-y-6">
    <div>
      <h1 class="text-xl font-semibold">
        {{ t('admin.status.title') }}
      </h1>
      <p class="text-sm text-muted">
        {{ t('admin.status.subtitle') }}
      </p>
    </div>

    <div
      v-if="pending && !status"
      class="flex justify-center py-16"
    >
      <UIcon
        name="i-lucide-loader-circle"
        class="animate-spin size-6 text-muted"
      />
    </div>

    <template v-else-if="status">
      <!-- The processing pipeline: what each queue does and how a document
           flows from Nextcloud into the search index. -->
      <section class="rounded-xl border border-default p-4 sm:p-6 space-y-4">
        <div>
          <h2 class="font-medium">
            {{ t('admin.status.pipelineTitle') }}
          </h2>
          <p class="text-sm text-muted">
            {{ t('admin.status.pipelineIntro') }}
          </p>
        </div>

        <div class="flex flex-col lg:flex-row lg:items-stretch gap-3">
          <!-- Source -->
          <div class="flex-1 rounded-lg border border-default bg-elevated/40 p-4">
            <div class="flex items-center gap-2">
              <UIcon
                name="i-lucide-cloud"
                class="size-5 text-primary shrink-0"
              />
              <span class="font-medium">{{ t('admin.status.source.title') }}</span>
            </div>
            <p class="mt-2 text-xs text-muted leading-relaxed">
              {{ t('admin.status.source.desc') }}
            </p>
          </div>

          <PipelineArrow />

          <!-- Crawl queue -->
          <div class="flex-1 rounded-lg border border-default p-4">
            <div class="flex items-center justify-between gap-2">
              <div class="flex items-center gap-2 min-w-0">
                <UIcon
                  name="i-lucide-folder-search"
                  class="size-5 text-primary shrink-0"
                />
                <span class="font-medium truncate">{{ t('admin.status.crawlStage.title') }}</span>
              </div>
              <UBadge
                size="sm"
                :color="status.queues.crawl > 0 ? 'info' : 'neutral'"
                variant="subtle"
                :label="t('admin.status.inQueue', { count: number(status.queues.crawl) })"
              />
            </div>
            <p class="mt-2 text-xs text-muted leading-relaxed">
              {{ t('admin.status.crawlStage.desc') }}
            </p>
            <UButton
              size="xs"
              color="neutral"
              variant="ghost"
              icon="i-lucide-trash-2"
              class="mt-2 -ml-1.5"
              :disabled="status.queues.crawl === 0"
              :label="t('admin.status.clear')"
              @click="clearTarget = 'crawl'"
            />
          </div>

          <PipelineArrow />

          <!-- Process queue -->
          <div class="flex-1 rounded-lg border border-default p-4">
            <div class="flex items-center justify-between gap-2">
              <div class="flex items-center gap-2 min-w-0">
                <UIcon
                  name="i-lucide-cog"
                  class="size-5 text-primary shrink-0"
                />
                <span class="font-medium truncate">{{ t('admin.status.processStage.title') }}</span>
              </div>
              <UBadge
                size="sm"
                :color="status.queues.process > 0 ? 'info' : 'neutral'"
                variant="subtle"
                :label="t('admin.status.inQueue', { count: number(status.queues.process) })"
              />
            </div>
            <p class="mt-2 text-xs text-muted leading-relaxed">
              {{ t('admin.status.processStage.desc') }}
            </p>
            <p class="mt-2 flex items-center gap-1.5 text-xs">
              <UIcon
                name="i-lucide-scan-text"
                class="size-3.5 text-muted"
              />
              <span class="text-muted">Tika</span>
              <UBadge
                size="sm"
                variant="subtle"
                :color="status.services.tika ? 'success' : 'error'"
                :label="status.services.tika ? t('admin.status.reachable') : t('admin.status.unreachable')"
              />
            </p>
            <UButton
              size="xs"
              color="neutral"
              variant="ghost"
              icon="i-lucide-trash-2"
              class="mt-2 -ml-1.5"
              :disabled="status.queues.process === 0"
              :label="t('admin.status.clear')"
              @click="clearTarget = 'process'"
            />
          </div>

          <PipelineArrow />

          <!-- Search index -->
          <div class="flex-1 rounded-lg border border-primary/40 bg-primary/5 p-4">
            <div class="flex items-center gap-2">
              <UIcon
                name="i-lucide-search"
                class="size-5 text-primary shrink-0"
              />
              <span class="font-medium">{{ t('admin.status.indexStage.title') }}</span>
            </div>
            <p class="mt-1 text-2xl font-semibold tabular-nums">
              {{ number(status.services.search.numberOfDocuments ?? 0) }}
              <span class="text-xs font-normal text-muted">{{ t('admin.status.documents') }}</span>
            </p>
            <p class="mt-1 text-xs text-muted leading-relaxed">
              {{ t('admin.status.indexStage.desc') }}
            </p>
            <div class="mt-2 flex flex-wrap gap-x-1 gap-y-0 -ml-1.5">
              <UButton
                size="xs"
                color="neutral"
                variant="ghost"
                icon="i-lucide-rotate-ccw"
                :label="t('admin.status.rebuildIndex')"
                @click="indexAction = 'rebuild'"
              />
              <UButton
                size="xs"
                color="error"
                variant="ghost"
                icon="i-lucide-trash-2"
                :label="t('admin.status.clearIndex')"
                @click="indexAction = 'clear'"
              />
            </div>
          </div>
        </div>
      </section>

      <div class="flex flex-wrap gap-2">
        <UBadge
          v-for="(count, state) in status.documents"
          :key="state"
          variant="subtle"
          :color="state === 'failed' ? 'error' : state === 'indexed' ? 'success' : 'neutral'"
          :label="`${number(count)} ${docStateLabel(state)}`"
        />
      </div>

      <div class="space-y-2">
        <div>
          <h2 class="font-medium">
            {{ t('admin.status.runsTitle') }}
          </h2>
          <p class="text-xs text-muted">
            {{ t('admin.status.runTypeHint') }}
          </p>
        </div>

        <p
          v-if="status.runs.length === 0"
          class="text-sm text-muted"
        >
          {{ t('admin.status.noRuns') }}
        </p>

        <UCard
          v-for="run in status.runs"
          :key="run.uuid"
          :ui="{ body: 'p-4 sm:p-4' }"
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <span class="font-medium">{{ run.folder }}</span>
                <UBadge
                  size="sm"
                  variant="subtle"
                  :color="run.state === 'completed' ? 'success' : run.state === 'failed' ? 'error' : 'info'"
                  :label="runStateLabel(run.state)"
                />
                <UBadge
                  size="sm"
                  variant="outline"
                  :color="run.full ? 'warning' : 'neutral'"
                  :label="run.full ? t('admin.status.runFull') : t('admin.status.runDelta')"
                />
              </div>

              <p class="text-xs text-muted">
                {{ run.instance }} · {{ t('admin.status.startedAt', { time: dateTime(run.started_at) }) }}
                <template v-if="run.finished_at">
                  · {{ t('admin.status.finishedAt', { time: dateTime(run.finished_at) }) }}
                </template>
                <template v-if="run.state === 'running'">
                  · {{ t('admin.status.openJobs', { count: run.pending_jobs }) }}
                </template>
              </p>
            </div>

            <div class="flex flex-wrap gap-3 text-xs tabular-nums">
              <span>{{ t('admin.status.seen', { count: run.files_seen }) }}</span>
              <span class="text-success">{{ t('admin.status.new', { count: run.files_new }) }}</span>
              <span>{{ t('admin.status.updated', { count: run.files_updated }) }}</span>
              <span>{{ t('admin.status.skipped', { count: run.files_skipped }) }}</span>
              <span>{{ t('admin.status.removed', { count: run.files_removed }) }}</span>
              <button
                v-if="run.files_failed > 0"
                type="button"
                class="text-error underline"
                @click="expanded = expanded === run.uuid ? null : run.uuid"
              >
                {{ t('admin.status.failedCount', { count: run.files_failed }) }}
              </button>
            </div>
          </div>

          <ul
            v-if="expanded === run.uuid"
            class="mt-3 space-y-1 border-t border-default pt-3 text-xs"
          >
            <li
              v-for="(entry, i) in run.errors"
              :key="i"
              class="break-all"
            >
              <span class="font-mono text-muted">{{ entry.path || '/' }}</span> — {{ entry.message }}
            </li>
          </ul>
        </UCard>
      </div>
    </template>

    <UModal
      v-model:open="clearModalOpen"
      :title="t('admin.status.clearConfirmTitle', { queue: clearTargetLabel })"
    >
      <template #body>
        <p class="text-sm text-muted">
          {{ t('admin.status.clearConfirmBody', { count: number(clearTargetCount), queue: clearTargetLabel }) }}
        </p>

        <div class="mt-6 flex justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            :label="t('common.cancel')"
            @click="clearModalOpen = false"
          />
          <UButton
            color="error"
            icon="i-lucide-trash-2"
            :loading="clearing"
            :label="t('admin.status.clearConfirmAction')"
            @click="confirmClear"
          />
        </div>
      </template>
    </UModal>

    <UModal
      v-model:open="indexModalOpen"
      :title="indexAction === 'rebuild'
        ? t('admin.status.rebuildConfirmTitle')
        : t('admin.status.clearIndexConfirmTitle')"
    >
      <template #body>
        <p class="text-sm text-muted">
          {{ indexAction === 'rebuild'
            ? t('admin.status.rebuildConfirmBody')
            : t('admin.status.clearIndexConfirmBody') }}
        </p>

        <div class="mt-6 flex justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            :label="t('common.cancel')"
            @click="indexModalOpen = false"
          />
          <UButton
            :color="indexAction === 'rebuild' ? 'primary' : 'error'"
            :icon="indexAction === 'rebuild' ? 'i-lucide-rotate-ccw' : 'i-lucide-trash-2'"
            :loading="indexBusy"
            :label="indexAction === 'rebuild'
              ? t('admin.status.rebuildIndex')
              : t('admin.status.clearIndex')"
            @click="confirmIndexAction"
          />
        </div>
      </template>
    </UModal>
  </UContainer>
</template>
