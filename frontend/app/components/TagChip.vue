<script setup lang="ts">
import type { BadgeProps } from '@nuxt/ui'

/**
 * One tag as a badge. Personal tags carry a small marker so it is clear at a
 * glance which of them nobody else sees.
 */
const props = withDefaults(defineProps<{
  tag: { uuid?: string, slug: string, name: string | null, color: string, scope?: string, is_system: boolean }
  size?: BadgeProps['size']
  removable?: boolean
}>(), {
  size: 'sm',
  removable: false
})

const emit = defineEmits<{ remove: [] }>()

const { t } = useI18n()
const { label } = useTags()

const color = computed(() => (props.tag.color || 'neutral') as BadgeProps['color'])
const personal = computed(() => props.tag.scope === 'personal')
</script>

<template>
  <UBadge
    :color="color"
    variant="subtle"
    :size="size"
    class="gap-1"
  >
    <UIcon
      v-if="personal"
      name="i-lucide-user"
      class="size-3 shrink-0 opacity-70"
      :aria-label="t('tags.personalMarker')"
    />
    <span class="truncate">{{ label(tag) }}</span>
    <button
      v-if="removable"
      type="button"
      class="shrink-0 opacity-60 hover:opacity-100"
      :aria-label="t('tags.remove', { tag: label(tag) })"
      @click.stop.prevent="emit('remove')"
    >
      <UIcon
        name="i-lucide-x"
        class="size-3"
      />
    </button>
  </UBadge>
</template>
