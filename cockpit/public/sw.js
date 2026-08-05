// SpiderNetOS cockpit service worker — web-push + notification click.
// Notification-only SW (no offline precache); installability comes from the
// manifest + a registered SW over HTTPS.

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

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
    icon: '/cockpit/icon.svg',
    badge: '/cockpit/icon.svg',
    tag: data.event_type || 'spidernetos',
    data: { url: data.url || '/cockpit/' },
  }
  event.waitUntil(self.registration.showNotification(title, options))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = (event.notification.data && event.notification.data.url) || '/cockpit/'
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
