import { computed, nextTick, reactive, ref, unref, watch } from 'vue'
import { createI18n } from 'vue-i18n'
import { ApiError, useApi } from '../app/composables/useApi'
import de from '../i18n/locales/de.json'
import en from '../i18n/locales/en.json'

/**
 * The composables under test are written for Nuxt, where `computed`, `useI18n`
 * and friends are auto-imported. Vitest runs them as plain modules, so the
 * handful they actually reach for is provided here.
 *
 * Deliberately not the Nuxt test environment: booting Nuxt per test file costs
 * seconds, and none of this is about Nuxt. The translations are the real locale
 * files, so a test that asserts a German label fails when that label is gone.
 */
const i18n = createI18n({
  legacy: false,
  locale: 'de',
  fallbackLocale: 'en',
  messages: { de, en }
})

/** Keyed by the same string useState() takes, so two callers share one ref. */
const states = new Map<string, unknown>()

const globals: Record<string, unknown> = {
  computed,
  nextTick,
  reactive,
  ref,
  unref,
  watch,

  useI18n: () => i18n.global,

  useState: <T>(key: string, init?: () => T) => {
    if (!states.has(key)) states.set(key, ref(init ? init() : undefined))
    return states.get(key)
  },

  // The real one — it only reaches for the network once a method is called,
  // and nothing here calls one. Anything that would talk to the backend
  // belongs in a backend test.
  useApi,
  ApiError,

  // Enough for a composable to be constructed.
  useNuxtApp: () => ({ $i18n: i18n.global }),
  useRoute: () => ({ query: {}, params: {}, path: '/', fullPath: '/' }),
  useRouter: () => ({ replace: () => {}, push: () => {} })
}

for (const [name, value] of Object.entries(globals)) {
  Object.defineProperty(globalThis, name, { value, writable: true, configurable: true })
}
