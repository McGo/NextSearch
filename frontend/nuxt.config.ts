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

  // Installable as a home-screen app (PWA). The manifest, icons and the small
  // service worker live in public/; the SW is registered by plugins/pwa.client.
  app: {
    head: {
      link: [
        { rel: 'manifest', href: '/manifest.webmanifest' },
        // Falls back to the bundled icon until a logo is uploaded.
        { rel: 'apple-touch-icon', href: '/api/branding/icon/apple' }
      ],
      meta: [
        { name: 'theme-color', content: '#2563eb' },
        { name: 'mobile-web-app-capable', content: 'yes' },
        { name: 'apple-mobile-web-app-capable', content: 'yes' },
        { name: 'apple-mobile-web-app-status-bar-style', content: 'default' },
        { name: 'apple-mobile-web-app-title', content: 'NextSearch' }
      ]
    }
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
  // listing it here. The `flag` is the emoji shown in the language switcher.
  // See CONTRIBUTING.md.
  i18n: {
    strategy: 'no_prefix',
    defaultLocale: 'en',
    locales: [
      { code: 'en', name: 'English', language: 'en', file: 'en.json', flag: '🇬🇧' },
      { code: 'de', name: 'Deutsch', language: 'de', file: 'de.json', flag: '🇩🇪' }
    ],
    detectBrowserLanguage: {
      useCookie: true,
      cookieKey: 'nextsearch_locale',
      redirectOn: 'no prefix'
    }
  }
})
