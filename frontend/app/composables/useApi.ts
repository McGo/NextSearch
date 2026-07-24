import type { NitroFetchOptions } from 'nitropack'

/**
 * Zugriff auf die Laravel-API. Läuft über denselben Origin wie die UI, deshalb
 * geht der Session-Cookie automatisch mit; für schreibende Anfragen wird der
 * XSRF-TOKEN-Cookie als Header nachgereicht.
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

  /** Erste Meldung zu einem Feld — für die Anzeige direkt am Eingabefeld. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

export function useApi() {
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
          Accept: 'application/json',
          ...(token ? { 'X-XSRF-TOKEN': token } : {}),
          ...(options.headers as Record<string, string> | undefined)
        }
      })
    } catch (error: unknown) {
      const response = (error as { response?: { status: number, _data?: unknown } }).response
      const data = response?._data as { message?: string, errors?: Record<string, string[]> } | undefined

      throw new ApiError(
        data?.message || 'Die Anfrage ist fehlgeschlagen.',
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
    del: <T>(path: string) =>
      request<T>(path, { method: 'DELETE' }),
    // Datei-Upload. ofetch setzt bei FormData den Content-Type samt boundary
    // selbst — deshalb hier keinen mitgeben.
    upload: <T>(path: string, form: FormData) =>
      request<T>(path, { method: 'POST', body: form })
  }
}
