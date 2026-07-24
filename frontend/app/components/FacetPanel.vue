<script setup lang="ts">
import type { Facet } from '~/composables/useSearch'

defineProps<{
  facets: Facet[]
  selected: Record<string, string[]>
  activeCount: number
}>()

const emit = defineEmits<{
  toggle: [facet: string, value: string]
  clear: []
}>()

const { facetValue } = useFormat()

/** Lange Facetten zeigen zunächst nur die häufigsten Werte. */
const expanded = ref<Record<string, boolean>>({})
const COLLAPSED = 6

function visible(facet: Facet) {
  return expanded.value[facet.name] ? facet.values : facet.values.slice(0, COLLAPSED)
}

function isSelected(props: Record<string, string[]>, facet: string, value: string) {
  return (props[facet] ?? []).includes(value)
}
</script>

<template>
  <aside class="space-y-6">
    <div
      v-if="activeCount > 0"
      class="flex items-center justify-between"
    >
      <span class="text-sm text-muted">{{ activeCount }} Filter aktiv</span>
      <UButton
        size="xs"
        color="neutral"
        variant="ghost"
        icon="i-lucide-x"
        label="Zurücksetzen"
        @click="emit('clear')"
      />
    </div>

    <p
      v-if="facets.length === 0"
      class="text-sm text-muted"
    >
      Filter erscheinen, sobald es Treffer gibt.
    </p>

    <div
      v-for="facet in facets"
      :key="facet.name"
      class="space-y-2"
    >
      <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">
        {{ FACET_LABELS[facet.name] ?? facet.name }}
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
              <span class="truncate">{{ facetValue(facet.name, entry.value) }}</span>
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
        :label="expanded[facet.name] ? 'Weniger' : `Alle ${facet.values.length} anzeigen`"
        @click="expanded[facet.name] = !expanded[facet.name]"
      />
    </div>
  </aside>
</template>
