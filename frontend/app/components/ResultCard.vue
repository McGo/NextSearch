<script setup lang="ts">
import type { SearchHit } from '~/composables/useSearch'

const props = defineProps<{ hit: SearchHit }>()

const { bytes, date, icon } = useFormat()
const { folderImageById, instanceImageByName } = useDirectory()
const previewFailed = ref(false)

const previewUrl = computed(() =>
  props.hit.has_preview ? `/api/documents/${props.hit.uuid}/preview` : null
)

// Herkunfts-Bild: erst der Ordner, sonst die Instanz.
const originImage = computed(() =>
  folderImageById.value[props.hit.folder_id]
  ?? instanceImageByName.value[props.hit.instance_name]
  ?? null
)
</script>

<template>
  <!-- v-html ist hier Absicht: DocumentSearch::highlight() escaped den Text aus
       den indizierten Dateien und setzt danach ausschließlich <mark>-Tags. -->
  <!-- eslint-disable vue/no-v-html -->
  <NuxtLink
    :to="`/documents/${hit.uuid}`"
    class="flex gap-4 rounded-lg border border-default p-4 transition hover:border-primary hover:bg-elevated/50"
  >
    <!-- Vorschau, sonst eine Typ-Kachel: .eml, .md und .txt lassen sich nicht
         sinnvoll rendern. -->
    <div class="shrink-0 w-20 h-28 rounded border border-default bg-elevated overflow-hidden flex items-center justify-center">
      <img
        v-if="previewUrl && !previewFailed"
        :src="previewUrl"
        :alt="`Vorschau von ${hit.name}`"
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

      <p class="flex items-center gap-1.5 text-xs text-muted truncate">
        <img
          v-if="originImage"
          :src="originImage"
          :alt="hit.folder_label"
          class="size-4 shrink-0 rounded object-cover"
          loading="lazy"
        >
        <span class="truncate">{{ hit.instance_name }} · {{ hit.folder_label }} · {{ hit.directory || '/' }}</span>
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
          label="OCR"
        />
        <span
          v-if="hit.page_count"
          class="text-xs text-muted"
        >{{ hit.page_count }} Seiten</span>
      </div>
    </div>
  </NuxtLink>
</template>
