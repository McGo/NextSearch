// Registers the service worker so the browser offers "Add to home screen".
// Client-only (the `.client` suffix); failures are swallowed — a missing SW
// just means no install prompt, never a broken app.
//
// The plugin runs during hydration, which is usually after the window `load`
// event has already fired — so we register right away rather than waiting for
// an event that would never come.
export default defineNuxtPlugin(() => {
  if (!('serviceWorker' in navigator)) {
    return
  }

  navigator.serviceWorker.register('/sw.js').catch(() => {})
})
