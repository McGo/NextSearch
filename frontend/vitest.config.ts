import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    environment: 'happy-dom',
    include: ['test/**/*.spec.ts'],
    // Supplies the Nuxt auto-imports the composables expect — see test/setup.ts.
    setupFiles: ['test/setup.ts']
  }
})
