const backendUrl = process.env.NUXT_BACKEND_URL || 'http://app:8080'

export default defineNuxtConfig({
  modules: [
    '@nuxt/eslint',
    '@nuxt/ui',
    '@nuxtjs/i18n'
  ],

  // There is nothing to index behind the login, and without server rendering we
  // avoid forwarding session cookies into SSR requests. Nitro stays active — it
  // serves the app and proxies /api.
  ssr: false,

  devtools: {
    enabled: false
  },

  css: ['~/assets/css/main.css'],

  runtimeConfig: {
    backendUrl,
    public: {
      appName: process.env.NUXT_PUBLIC_APP_NAME || 'NextSearch',
      repoUrl: process.env.NUXT_PUBLIC_REPO_URL || 'https://github.com/McGo/NextSearch'
    }
  },

  routeRules: {
    // The Laravel backend has no published port. Nitro forwards /api — so the UI
    // and the API share an origin, and the session cookie plus CSRF protection
    // work without further configuration.
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
  },

  // Contributors add a language by dropping a JSON file into i18n/locales and
  // listing it here. See CONTRIBUTING.md.
  i18n: {
    strategy: 'no_prefix',
    defaultLocale: 'en',
    locales: [
      { code: 'en', name: 'English', language: 'en', file: 'en.json' },
      { code: 'de', name: 'Deutsch', language: 'de', file: 'de.json' }
    ],
    detectBrowserLanguage: {
      useCookie: true,
      cookieKey: 'nextsearch_locale',
      redirectOn: 'no prefix'
    }
  }
})
