<script setup lang="ts">
/**
 * A screenshot on the help page.
 *
 * The images are generated against a demo installation (see
 * demo/help-screenshots.sh) and land in public/help-shots/<locale>/. The
 * directory is deliberately not called "help": that is a page route, and a file
 * below it would be answered with the application shell instead of the image.
 *
 * A checkout without the screenshots is a normal case — the figure removes
 * itself rather than leaving a broken image behind.
 */
const props = defineProps<{
  /** File name without extension, e.g. "search". */
  name: string
  caption: string
}>()

const { locale } = useI18n()
const failed = ref(false)

const src = computed(() => `/help-shots/${locale.value}/${props.name}.png`)

// A language switch asks for a different file; give it another chance.
watch(src, () => {
  failed.value = false
})
</script>

<template>
  <figure
    v-if="!failed"
    class="my-4"
  >
    <!-- The wide shots are scaled into the column; the link opens the file at
         its own size for anyone who wants to read the small print. -->
    <a
      :href="src"
      target="_blank"
      rel="noopener"
    >
      <img
        :src="src"
        :alt="caption"
        loading="lazy"
        class="max-w-full rounded-lg border border-default"
        @error="failed = true"
      >
    </a>
    <figcaption class="mt-1.5 text-xs text-muted">
      {{ caption }}
    </figcaption>
  </figure>
</template>
