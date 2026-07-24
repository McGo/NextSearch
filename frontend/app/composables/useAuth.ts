export interface AuthUser {
  id: number
  name: string
  email: string
  role: 'admin' | 'user'
  is_admin: boolean
  folder_count: number | null
}

const user = ref<AuthUser | null>(null)
const resolved = ref(false)

export function useAuth() {
  const api = useApi()

  async function refresh(): Promise<AuthUser | null> {
    try {
      const { user: current } = await api.get<{ user: AuthUser }>('/api/auth/me')
      user.value = current
    } catch {
      user.value = null
    } finally {
      resolved.value = true
    }

    return user.value
  }

  /** Beim ersten Aufruf einmal nachschlagen, danach aus dem Zustand bedienen. */
  async function ensureResolved(): Promise<AuthUser | null> {
    if (!resolved.value) await refresh()
    return user.value
  }

  async function login(email: string, password: string, remember = false) {
    const { user: current } = await api.post<{ user: AuthUser }>('/api/auth/login', {
      email,
      password,
      remember
    })

    user.value = current
    resolved.value = true

    return current
  }

  async function logout() {
    try {
      await api.post('/api/auth/logout')
    } finally {
      user.value = null
      await navigateTo('/login')
    }
  }

  return {
    user: readonly(user),
    isAdmin: computed(() => user.value?.is_admin === true),
    isAuthenticated: computed(() => user.value !== null),
    resolved: readonly(resolved),
    refresh,
    ensureResolved,
    login,
    logout
  }
}
