import type { NitroFetchOptions } from 'nitropack'

/**
 * Access to the Laravel API. It runs on the same origin as the UI, so the
 * session cookie goes along automatically; for writing requests the XSRF-TOKEN
 * cookie is sent back as a header.
 */

let csrfReady = false

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp('(^|;\\s*)' + name + '=([^;]*)'))
  return match ? decodeURIComponent(match[2]!) : null
}

async function ensureCsrfCookie() {
  if (csrfReady && readCookie('XSRF-TOKEN')) return
  await $fetch('/api/auth/csrf', { credentials: 'include' })
  csrfReady = true
}

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly errors: Record<string, string[]> = {}
  ) {
    super(message)
  }

  /** First message for a field — for display right at the input. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

export function useApi() {
  // useApi runs from places without a component setup context (e.g. the global
  // auth middleware), where useI18n() would throw. The global i18n instance on
  // nuxtApp works everywhere.
  const i18n = useNuxtApp().$i18n

  async function request<T>(path: string, options: NitroFetchOptions<string> = {}): Promise<T> {
    const method = (options.method || 'GET').toString().toUpperCase()

    if (method !== 'GET' && method !== 'HEAD') {
      await ensureCsrfCookie()
    }

    const token = readCookie('XSRF-TOKEN')

    try {
      return await $fetch<T>(path, {
        ...options,
        credentials: 'include',
        headers: {
          'Accept': 'application/json',
          // Lets the backend localise its messages to the chosen UI language.
          'X-Locale': i18n.locale.value,
          ...(token ? { 'X-XSRF-TOKEN': token } : {}),
          ...(options.headers as Record<string, string> | undefined)
        }
      })
    } catch (error: unknown) {
      const response = (error as { response?: { status: number, _data?: unknown } }).response
      const data = response?._data as { message?: string, errors?: Record<string, string[]> } | undefined

      throw new ApiError(
        data?.message || i18n.t('common.requestFailed'),
        response?.status ?? 0,
        data?.errors ?? {}
      )
    }
  }

  return {
    get: <T>(path: string, query?: Record<string, unknown>) =>
      request<T>(path, { method: 'GET', query }),
    post: <T>(path: string, body?: unknown) =>
      request<T>(path, { method: 'POST', body: body as Record<string, unknown> }),
    put: <T>(path: string, body?: unknown) =>
      request<T>(path, { method: 'PUT', body: body as Record<string, unknown> }),
    del: <T>(path: string, body?: unknown) =>
      request<T>(path, { method: 'DELETE', body: body as Record<string, unknown> }),
    // File upload. With FormData, ofetch sets the Content-Type including the
    // boundary itself — so we don't pass one here.
    upload: <T>(path: string, form: FormData) =>
      request<T>(path, { method: 'POST', body: form })
  }
}
