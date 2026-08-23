<script setup lang="ts">
/**
 * What the search page shows while there is nothing to search.
 *
 * A fresh installation lands on an empty result list, and an empty result list
 * looks like a broken one. The three steps below are the actual order of work —
 * connect a Nextcloud, pick a folder, wait for the first crawl — and the guide
 * says which of them is due.
 *
 * Only an administrator can act on the first two. Everyone else gets a sentence
 * saying who can.
 */
const props = defineProps<{
  /** The step that is due now. */
  step: 'instance' | 'folder' | 'indexing'
  isAdmin: boolean
  /** Documents already in the index while the crawl runs on. */
  indexed?: number
  pending?: number
}>()

const { t, n } = useI18n()

const steps = computed(() => [
  {
    key: 'instance' as const,
    title: t('setup.instance.title'),
    body: t('setup.instance.body'),
    to: '/admin/instances',
    action: t('setup.instance.action')
  },
  {
    key: 'folder' as const,
    title: t('setup.folder.title'),
    body: t('setup.folder.body'),
    to: '/admin/folders',
    action: t('setup.folder.action')
  },
  {
    key: 'indexing' as const,
    title: t('setup.indexing.title'),
    body: props.pending
      ? t('setup.indexing.bodyPending', { pending: n(props.pending), indexed: n(props.indexed ?? 0) })
      : t('setup.indexing.body'),
    to: '/admin/folders',
    action: t('setup.indexing.action')
  }
])

const currentIndex = computed(() => steps.value.findIndex(s => s.key === props.step))

function state(index: number): 'done' | 'current' | 'todo' {
  if (index < currentIndex.value) return 'done'

  return index === currentIndex.value ? 'current' : 'todo'
}
</script>

<template>
  <div class="rounded-lg border border-default p-6">
    <h2 class="font-medium">
      {{ t('setup.title') }}
    </h2>
    <p class="mt-1 text-sm text-muted">
      {{ isAdmin ? t('setup.subtitleAdmin') : t('setup.subtitleUser') }}
    </p>

    <ol class="mt-5 space-y-4">
      <li
        v-for="(entry, index) in steps"
        :key="entry.key"
        class="flex gap-3"
      >
        <span
          class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-medium"
          :class="{
            'bg-primary text-inverted': state(index) === 'current',
            'bg-elevated text-muted': state(index) !== 'current'
          }"
        >
          <UIcon
            v-if="state(index) === 'done'"
            name="i-lucide-check"
            class="size-3.5"
          />
          <template v-else>{{ index + 1 }}</template>
        </span>

        <div class="min-w-0 flex-1">
          <p
            class="text-sm"
            :class="state(index) === 'current' ? 'font-medium' : 'text-muted'"
          >
            {{ entry.title }}
          </p>

          <template v-if="state(index) === 'current'">
            <p class="mt-0.5 text-sm text-muted">
              {{ entry.body }}
            </p>

            <UButton
              v-if="isAdmin"
              class="mt-2"
              size="sm"
              :to="entry.to"
              :icon="entry.key === 'indexing' ? 'i-lucide-refresh-cw' : 'i-lucide-arrow-right'"
              :label="entry.action"
            />
          </template>
        </div>
      </li>
    </ol>

    <p class="mt-5 border-t border-default pt-4 text-xs text-muted">
      {{ t('setup.helpHint') }}
      <NuxtLink
        to="/help"
        class="text-primary hover:underline"
      >{{ t('setup.helpLink') }}</NuxtLink>
    </p>
  </div>
</template>
