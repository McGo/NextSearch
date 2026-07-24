<script setup lang="ts">
// Points right between stages on desktop, down when they stack on mobile.
</script>

<template>
  <div class="flex items-center justify-center shrink-0 text-muted">
    <UIcon
      name="i-lucide-chevron-down"
      class="size-5 lg:hidden"
    />
    <UIcon
      name="i-lucide-chevron-right"
      class="size-5 hidden lg:block"
    />
  </div>
</template>
