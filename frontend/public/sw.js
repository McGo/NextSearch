// Minimal service worker — its purpose is to make NextSearch installable to the
// home screen. It deliberately does not cache the app shell offline: the app is
// useless without the API and the Nextcloud source, so a cached-but-dead shell
// would only mislead. Requests pass straight through to the network.
self.addEventListener('install', () => self.skipWaiting())

self.addEventListener('activate', event => event.waitUntil(self.clients.claim()))

// A fetch handler is required for installability; passthrough is enough.
self.addEventListener('fetch', () => {})
