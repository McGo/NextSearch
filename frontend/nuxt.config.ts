const backendUrl = process.env.NUXT_BACKEND_URL || 'http://app:8080'

export default defineNuxtConfig({
  modules: [
    '@nuxt/eslint',
    '@nuxt/ui'
  ],

  // Hinter dem Login gibt es nichts zu indexieren, und ohne Server-Rendering
  // entfällt das Durchreichen von Session-Cookies in die SSR-Requests. Nitro
  // bleibt trotzdem aktiv — es liefert die App aus und proxied /api.
  ssr: false,

  devtools: {
    enabled: false
  },

  css: ['~/assets/css/main.css'],

  runtimeConfig: {
    backendUrl,
    public: {
      appName: process.env.NUXT_PUBLIC_APP_NAME || 'NextSearch'
    }
  },

  routeRules: {
    // Das Laravel-Backend hat keinen veröffentlichten Port. Nitro reicht /api
    // durch — dadurch teilen UI und API eine Origin, und Session-Cookie samt
    // CSRF-Schutz funktionieren ohne weitere Konfiguration.
    '/api/**': { proxy: `${backendUrl}/api/**` }
  },

  compatibilityDate: '2026-06-30',

  nitro: {
    compressPublicAssets: true
  },

  eslint: {
    config: {
      stylistic: {
        commaDangle: 'never',
        braceStyle: '1tbs'
      }
    }
  }
})
