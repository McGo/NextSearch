<script setup lang="ts">
useHead({ title: 'Status' })

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
  queues: Record<string, number>
  services: { tika: boolean, search: { numberOfDocuments?: number, isIndexing?: boolean } }
}

const api = useApi()
const { dateTime } = useFormat()

const status = ref<Status | null>(null)
const pending = ref(true)
const expanded = ref<string | null>(null)

async function load() {
  try {
    status.value = await api.get<Status>('/api/admin/status')
  } finally {
    pending.value = false
  }
}

// Solange etwas läuft, lohnt sich der Blick alle fünf Sekunden.
let timer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  load()
  timer = setInterval(load, 5000)
})

onUnmounted(() => clearInterval(timer))

const documentStates: Record<string, string> = {
  pending: 'wartet',
  indexed: 'indiziert',
  failed: 'fehlgeschlagen',
  skipped: 'übersprungen'
}
</script>

<template>
  <UContainer class="py-8 space-y-6">
    <div>
      <h1 class="text-xl font-semibold">
        Status
      </h1>
      <p class="text-sm text-muted">
        Dienste, Warteschlangen und die letzten Durchläufe.
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
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <UCard>
          <p class="text-xs text-muted">
            Suchindex
          </p>
          <p class="text-xl font-semibold tabular-nums">
            {{ (status.services.search.numberOfDocuments ?? 0).toLocaleString('de-DE') }}
          </p>
          <p class="text-xs text-muted">
            Dokumente
          </p>
        </UCard>

        <UCard>
          <p class="text-xs text-muted">
            Textextraktion
          </p>
          <p class="text-xl font-semibold">
            <UBadge
              variant="subtle"
              :color="status.services.tika ? 'success' : 'error'"
              :label="status.services.tika ? 'erreichbar' : 'nicht erreichbar'"
            />
          </p>
        </UCard>

        <UCard
          v-for="(size, queue) in status.queues"
          :key="queue"
        >
          <p class="text-xs text-muted">
            Warteschlange {{ queue }}
          </p>
          <p class="text-xl font-semibold tabular-nums">
            {{ size }}
          </p>
        </UCard>
      </div>

      <div class="flex flex-wrap gap-2">
        <UBadge
          v-for="(count, state) in status.documents"
          :key="state"
          variant="subtle"
          :color="state === 'failed' ? 'error' : state === 'indexed' ? 'success' : 'neutral'"
          :label="`${count.toLocaleString('de-DE')} ${documentStates[state] ?? state}`"
        />
      </div>

      <div class="space-y-2">
        <h2 class="font-medium">
          Letzte Durchläufe
        </h2>

        <p
          v-if="status.runs.length === 0"
          class="text-sm text-muted"
        >
          Noch kein Durchlauf gelaufen.
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
                  :label="run.state === 'completed' ? 'fertig' : run.state === 'failed' ? 'abgebrochen' : 'läuft'"
                />
                <UBadge
                  v-if="run.full"
                  size="sm"
                  color="neutral"
                  variant="outline"
                  label="vollständig"
                />
              </div>

              <p class="text-xs text-muted">
                {{ run.instance }} · gestartet {{ dateTime(run.started_at) }}
                <template v-if="run.finished_at">
                  · beendet {{ dateTime(run.finished_at) }}
                </template>
                <template v-if="run.state === 'running'">
                  · {{ run.pending_jobs }} offen
                </template>
              </p>
            </div>

            <div class="flex flex-wrap gap-3 text-xs tabular-nums">
              <span>{{ run.files_seen }} gesehen</span>
              <span class="text-success">{{ run.files_new }} neu</span>
              <span>{{ run.files_updated }} geändert</span>
              <span>{{ run.files_skipped }} übersprungen</span>
              <span>{{ run.files_removed }} entfernt</span>
              <button
                v-if="run.files_failed > 0"
                type="button"
                class="text-error underline"
                @click="expanded = expanded === run.uuid ? null : run.uuid"
              >
                {{ run.files_failed }} Fehler
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
  </UContainer>
</template>
