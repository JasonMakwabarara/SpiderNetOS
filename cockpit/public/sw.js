// SpiderNetOS cockpit service worker.
// Web-push + notification click, plus a light offline layer:
//  - navigations: network-first, falling back to cached shell → offline.html
//  - scripts/styles/fonts: network-first with cache fallback (never stale)
//  - images: stale-while-revalidate
// All paths derive from the registration scope, so the same file works at
// '/' (prod, cockpit.spidernetos.com) and '/cockpit/' (local dev).

const VERSION = 'snos-v2'
const BASE = new URL(self.registration.scope).pathname
const SHELL_CACHE = `${VERSION}-shell`
const ASSET_CACHE = `${VERSION}-assets`
const IMAGE_CACHE = `${VERSION}-images`
const KNOWN_CACHES = [SHELL_CACHE, ASSET_CACHE, IMAGE_CACHE]

const PRECACHE = [
  BASE,
  `${BASE}offline.html`,
  `${BASE}manifest.webmanifest`,
  `${BASE}icon.svg`,
  `${BASE}icons/icon-192.png`,
  `${BASE}icons/icon-512.png`,
]

// Paths the SW must never intercept: API + realtime + auth endpoints.
const BYPASS = [`${BASE}api/`, '/api/', '/broadcasting/', '/sanctum/', '/@']

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(SHELL_CACHE)
      // allSettled: a missing precache entry must not brick installation.
      .then((cache) => Promise.allSettled(PRECACHE.map((url) => cache.add(url))))
      .then(() => self.skipWaiting()),
  )
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const names = await caches.keys()
      await Promise.all(names.filter((n) => !KNOWN_CACHES.includes(n)).map((n) => caches.delete(n)))
      if (self.registration.navigationPreload) {
        try {
          await self.registration.navigationPreload.enable()
        } catch {
          /* optional */
        }
      }
      await self.clients.claim()
    })(),
  )
})

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting()
})

async function trimCache(name, maxEntries) {
  const cache = await caches.open(name)
  const keys = await cache.keys()
  for (let i = 0; i < keys.length - maxEntries; i++) await cache.delete(keys[i])
}

async function networkFirst(event, cacheName, fallbackUrls = []) {
  const { request } = event
  const cache = await caches.open(cacheName)
  try {
    const preload = event.preloadResponse ? await event.preloadResponse : null
    const response = preload || (await fetch(request))
    if (response && response.ok && response.type === 'basic') {
      cache.put(request, response.clone())
      trimCache(cacheName, 80)
    }
    return response
  } catch (err) {
    const cached = await cache.match(request)
    if (cached) return cached
    for (const url of fallbackUrls) {
      const fallback = await caches.match(url)
      if (fallback) return fallback
    }
    throw err
  }
}

async function staleWhileRevalidate(event, cacheName) {
  const { request } = event
  const cache = await caches.open(cacheName)
  const cached = await cache.match(request)
  const refresh = fetch(request)
    .then((response) => {
      if (response && response.ok && response.type === 'basic') {
        cache.put(request, response.clone())
        trimCache(cacheName, 100)
      }
      return response
    })
    .catch(() => null)
  return cached || refresh.then((r) => r || Promise.reject(new Error('offline')))
}

self.addEventListener('fetch', (event) => {
  const { request } = event
  if (request.method !== 'GET') return
  const url = new URL(request.url)
  if (url.origin !== self.location.origin) return
  if (BYPASS.some((p) => url.pathname.startsWith(p))) return
  if (request.headers.has('range')) return

  if (request.mode === 'navigate') {
    event.respondWith(networkFirst(event, SHELL_CACHE, [BASE, `${BASE}offline.html`]))
    return
  }

  const dest = request.destination
  if (dest === 'script' || dest === 'style' || dest === 'worker' || dest === 'font') {
    event.respondWith(networkFirst(event, ASSET_CACHE))
    return
  }
  if (dest === 'image') {
    event.respondWith(staleWhileRevalidate(event, IMAGE_CACHE))
  }
})

// ── Web-push + notification click ────────────────────────────────────────────

self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { title: 'SpiderNetOS', body: event.data && event.data.text() }
  }
  const title = data.title || 'SpiderNetOS'
  const options = {
    body: data.body || '',
    icon: `${BASE}icons/icon-192.png`,
    badge: `${BASE}icons/icon-192.png`,
    tag: data.event_type || 'spidernetos',
    data: { url: data.url || BASE },
  }
  event.waitUntil(self.registration.showNotification(title, options))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = (event.notification.data && event.notification.data.url) || BASE
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
      for (const w of wins) {
        if ('focus' in w) {
          if ('navigate' in w) w.navigate(url)
          return w.focus()
        }
      }
      if (self.clients.openWindow) return self.clients.openWindow(url)
    }),
  )
})
