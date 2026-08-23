// The OAuth discovery documents for the MCP server. They have to answer at the
// root of the domain — a client looks for them under /.well-known and nowhere
// else — but they are produced by Laravel, which sits behind this container
// without a published port.
//
// Middleware rather than a route file: a directory whose name starts with a dot
// is not reliably picked up by the route scanner. Everything that is not one of
// these paths falls straight through to the app.
export default defineEventHandler((event) => {
  const path = event.path.split('?')[0] ?? ''

  const proxied = path.startsWith('/.well-known/oauth-protected-resource')
    || path.startsWith('/.well-known/oauth-authorization-server')
    || path === '/.well-known/openid-configuration'

  if (!proxied) return

  const { backendUrl } = useRuntimeConfig(event)

  return proxyRequest(event, backendUrl.replace(/\/$/, '') + event.path)
})
