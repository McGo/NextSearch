// Runtime proxy for /api/** to the Laravel backend. The target is read from
// runtimeConfig at request time — overridable per deployment via
// NUXT_BACKEND_URL — unlike a routeRules proxy, whose target is frozen into the
// build. `event.path` carries the full path including /api and the query, so
// the proxy stays same-origin from the browser: the session cookie and CSRF
// protection keep working exactly as before.
export default defineEventHandler((event) => {
  const { backendUrl } = useRuntimeConfig(event)

  return proxyRequest(event, backendUrl.replace(/\/$/, '') + event.path)
})
