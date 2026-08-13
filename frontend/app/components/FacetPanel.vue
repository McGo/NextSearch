<script setup lang="ts">
import type { Facet, FacetValue } from '~/composables/useSearch'

defineProps<{
  facets: Facet[]
  selected: Record<string, string[]>
  activeCount: number
}>()

const emit = defineEmits<{
  toggle: [facet: string, value: string]
  clear: []
}>()

const { t } = useI18n()
const { facetValue } = useFormat()
const { folderImageByLabel, instanceImageByName } = useDirectory()

/** Long facets show only the most frequent values at first. */
const expanded = ref<Record<string, boolean>>({})
const COLLAPSED = 6

function visible(facet: Facet) {
  return expanded.value[facet.name] ? facet.values : facet.values.slice(0, COLLAPSED)
}

function isSelected(props: Record<string, string[]>, facet: string, value: string) {
  return (props[facet] ?? []).includes(value)
}

// Image for a facet value — only the origin facets carry one.
function facetImage(facet: string, value: string): string | null {
  if (facet === 'folder_label') return folderImageByLabel.value[value] ?? null
  if (facet === 'instance_name') return instanceImageByName.value[value] ?? null
  return null
}

const { label: tagLabel } = useTags()

/**
 * Tag facets bring their own label along — the backend resolves the uuid, and
 * a system tag without a name of its own is translated here.
 */
function entryLabel(facet: string, entry: FacetValue): string {
  if (entry.slug === undefined) return facetValue(facet, entry.value)

  return tagLabel({ name: entry.label ?? null, slug: entry.slug, is_system: entry.is_system === true })
}

/** Colour dot in front of a tag, in place of the origin facets' image. */
function entryDot(entry: FacetValue): string | null {
  if (entry.color === undefined) return null

  return {
    neutral: 'bg-neutral-400',
    primary: 'bg-primary',
    secondary: 'bg-secondary',
    success: 'bg-success',
    info: 'bg-info',
    warning: 'bg-warning',
    error: 'bg-error'
  }[entry.color] ?? 'bg-neutral-400'
}
</script>

<template>
  <aside class="space-y-6">
    <div
      v-if="activeCount > 0"
      class="flex items-center justify-between"
    >
      <span class="text-sm text-muted">{{ t('search.filtersActive', { count: activeCount }, activeCount) }}</span>
      <UButton
        size="xs"
        color="neutral"
        variant="ghost"
        icon="i-lucide-x"
        :label="t('search.reset')"
        @click="emit('clear')"
      />
    </div>

    <p
      v-if="facets.length === 0"
      class="text-sm text-muted"
    >
      {{ t('search.facetsEmpty') }}
    </p>

    <div
      v-for="facet in facets"
      :key="facet.name"
      class="space-y-2"
    >
      <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">
        {{ t(`facet.${facet.name}`) }}
      </h3>

      <ul class="space-y-1">
        <li
          v-for="entry in visible(facet)"
          :key="entry.value"
        >
          <button
            type="button"
            class="w-full flex items-center justify-between gap-2 rounded px-2 py-1 text-sm hover:bg-elevated"
            :class="isSelected(selected, facet.name, entry.value) ? 'bg-elevated font-medium' : ''"
            @click="emit('toggle', facet.name, entry.value)"
          >
            <span class="flex items-center gap-2 truncate">
              <UIcon
                :name="isSelected(selected, facet.name, entry.value) ? 'i-lucide-check-square' : 'i-lucide-square'"
                class="size-4 shrink-0"
                :class="isSelected(selected, facet.name, entry.value) ? 'text-primary' : 'text-muted'"
              />
              <img
                v-if="facetImage(facet.name, entry.value)"
                :src="facetImage(facet.name, entry.value)!"
                :alt="entry.value"
                class="size-4 shrink-0 rounded object-cover"
                loading="lazy"
              >
              <span
                v-else-if="entryDot(entry)"
                class="size-2 shrink-0 rounded-full"
                :class="entryDot(entry)!"
              />
              <span class="truncate">{{ entryLabel(facet.name, entry) }}</span>
            </span>
            <span class="text-xs text-muted tabular-nums">{{ entry.count }}</span>
          </button>
        </li>
      </ul>

      <UButton
        v-if="facet.values.length > COLLAPSED"
        size="xs"
        color="neutral"
        variant="link"
        :label="expanded[facet.name] ? t('search.showLess') : t('search.showAll', { count: facet.values.length })"
        @click="expanded[facet.name] = !expanded[facet.name]"
      />
    </div>
  </aside>
</template>
