interface BrandingState {
  has_logo: boolean
  logo_url: string | null
}

/**
 * The installation's logo state, shared across the app so the header and the
 * settings page agree. Public endpoint — works before sign-in too.
 */
export function useBranding() {
  const api = useApi()
  const state = useState<BrandingState>('branding', () => ({ has_logo: false, logo_url: null }))
  const loaded = useState<boolean>('branding-loaded', () => false)

  async function load(force = false) {
    if (loaded.value && !force) return

    try {
      state.value = await api.get<BrandingState>('/api/branding')
      loaded.value = true
    } catch {
      // No branding is a fine default — keep the built-in mark.
    }
  }

  return {
    state,
    load,
    refresh: () => load(true)
  }
}
