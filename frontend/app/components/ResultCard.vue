<script setup lang="ts">
import type { SearchHit } from '~/composables/useSearch'

const props = defineProps<{ hit: SearchHit }>()

const { t } = useI18n()
const route = useRoute()
const { bytes, date, icon } = useFormat()
const { folderImageById, instanceImageByName } = useDirectory()
const previewFailed = ref(false)

// Carry the current search URL (query, filters, sort, page all live in it) so
// the document's "back to search" returns to exactly this result list.
const documentTo = computed(() => ({
  path: `/documents/${props.hit.uuid}`,
  query: { from: route.fullPath }
}))

const previewUrl = computed(() =>
  props.hit.has_preview ? `/api/documents/${props.hit.uuid}/preview` : null
)

// Each origin part carries its own image next to its own name.
const instanceImage = computed(() => instanceImageByName.value[props.hit.instance_name] ?? null)
const folderImage = computed(() => folderImageById.value[props.hit.folder_id] ?? null)
</script>

<template>
  <!-- v-html is intentional here: DocumentSearch::highlight() escapes the text
       from the indexed files and then inserts only <mark> tags. -->
  <!-- eslint-disable vue/no-v-html -->
  <NuxtLink
    :to="documentTo"
    class="flex gap-4 rounded-lg border border-default p-4 transition hover:border-primary hover:bg-elevated/50"
  >
    <!-- Preview, otherwise a type tile: .eml, .md and .txt can't be rendered
         usefully. -->
    <div class="shrink-0 w-20 h-28 rounded border border-default bg-elevated overflow-hidden flex items-center justify-center">
      <img
        v-if="previewUrl && !previewFailed"
        :src="previewUrl"
        :alt="hit.name"
        class="w-full h-full object-cover object-top"
        loading="lazy"
        @error="previewFailed = true"
      >
      <UIcon
        v-else
        :name="icon(hit.extension)"
        class="size-8 text-muted"
      />
    </div>

    <div class="min-w-0 flex-1 space-y-1">
      <h2
        class="font-medium truncate"
        v-html="hit.highlighted_name || hit.name"
      />

      <p class="flex items-center gap-1 text-xs text-muted truncate">
        <img
          v-if="instanceImage"
          :src="instanceImage"
          :alt="hit.instance_name"
          class="size-4 shrink-0 rounded object-cover"
          loading="lazy"
        >
        <span class="shrink-0">{{ hit.instance_name }}</span>
        <span class="shrink-0 opacity-60">·</span>
        <img
          v-if="folderImage"
          :src="folderImage"
          :alt="hit.folder_label"
          class="size-4 shrink-0 rounded object-cover"
          loading="lazy"
        >
        <span class="shrink-0">{{ hit.folder_label }}</span>
        <span class="truncate">· {{ hit.directory || '/' }}</span>
      </p>

      <p
        v-if="hit.snippet"
        class="text-sm text-toned line-clamp-3 [&_mark]:bg-primary/20 [&_mark]:text-default [&_mark]:rounded [&_mark]:px-0.5"
        v-html="hit.snippet"
      />

      <div class="flex flex-wrap items-center gap-2 pt-1">
        <UBadge
          size="sm"
          color="neutral"
          variant="subtle"
          :label="hit.extension.toUpperCase()"
        />
        <span class="text-xs text-muted">{{ bytes(hit.size) }}</span>
        <span class="text-xs text-muted">{{ date(hit.modified_at) }}</span>
        <UBadge
          v-if="hit.ocr_used"
          size="sm"
          color="warning"
          variant="subtle"
          icon="i-lucide-scan-text"
          :label="t('result.ocr')"
        />
        <span
          v-if="hit.page_count"
          class="text-xs text-muted"
        >{{ t('result.pages', { count: hit.page_count }, hit.page_count) }}</span>
      </div>
    </div>
  </NuxtLink>
</template>
