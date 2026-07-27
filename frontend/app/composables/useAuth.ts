export interface AuthUser {
  id: number
  name: string
  email: string
  role: 'admin' | 'user'
  is_admin: boolean
  two_factor_enabled: boolean
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

  /** Look up once on the first call, then serve from state. */
  async function ensureResolved(): Promise<AuthUser | null> {
    if (!resolved.value) await refresh()
    return user.value
  }

  /**
   * Returns { twoFactor: true } when the password was right but a second factor
   * is still needed — the caller then collects a code and calls
   * twoFactorChallenge. Otherwise the user is signed in.
   */
  async function login(email: string, password: string, remember = false): Promise<{ twoFactor: boolean }> {
    const res = await api.post<{ user?: AuthUser, two_factor?: boolean }>('/api/auth/login', {
      email,
      password,
      remember
    })

    if (res.two_factor) {
      return { twoFactor: true }
    }

    user.value = res.user ?? null
    resolved.value = true

    return { twoFactor: false }
  }

  /** Second step of a 2FA login: a TOTP code or a one-time recovery code. */
  async function twoFactorChallenge(payload: { code?: string, recovery_code?: string }): Promise<AuthUser> {
    const { user: current } = await api.post<{ user: AuthUser }>('/api/auth/two-factor-challenge', payload)

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
    twoFactorChallenge,
    logout
  }
}
