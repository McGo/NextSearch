const PUBLIC_ROUTES = ['/login']

export default defineNuxtRouteMiddleware(async (to) => {
  const { ensureResolved, user } = useAuth()
  const current = await ensureResolved()

  if (PUBLIC_ROUTES.includes(to.path)) {
    return current ? navigateTo('/') : undefined
  }

  if (!current) {
    return navigateTo({ path: '/login', query: to.fullPath === '/' ? {} : { weiter: to.fullPath } })
  }

  // Der Admin-Bereich ist auch serverseitig gesperrt; das hier erspart nur den
  // Umweg über eine 403-Antwort.
  if (to.path.startsWith('/admin') && !user.value?.is_admin) {
    return navigateTo('/')
  }
})
