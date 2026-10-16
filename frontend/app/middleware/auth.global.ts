const PUBLIC_ROUTES = ['/login']

export default defineNuxtRouteMiddleware(async (to) => {
  // An invitation link opens for anyone holding it, signed in or not — an
  // administrator may want to try the link before passing it on.
  if (to.path.startsWith('/invite/')) return

  const { ensureResolved, user } = useAuth()
  const current = await ensureResolved()

  if (PUBLIC_ROUTES.includes(to.path)) {
    return current ? navigateTo('/') : undefined
  }

  if (!current) {
    return navigateTo({ path: '/login', query: to.fullPath === '/' ? {} : { next: to.fullPath } })
  }

  // The admin area is enforced on the server too; this only saves the detour
  // through a 403 response.
  if (to.path.startsWith('/admin') && !user.value?.is_admin) {
    return navigateTo('/')
  }
})
