import api from '../services/api.js'

/** VAPID base64url → Uint8Array for PushManager.subscribe(). */
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  const out = new Uint8Array(raw.length)
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i)
  return out
}

export function useWebPush() {
  const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

  async function isSubscribed() {
    if (!supported) return false
    const reg = await navigator.serviceWorker.ready
    return !!(await reg.pushManager.getSubscription())
  }

  async function subscribe() {
    if (!supported) throw new Error('Notifications are not supported in this browser.')
    const permission = await Notification.requestPermission()
    if (permission !== 'granted') throw new Error('Notification permission was denied.')

    const { data } = await api.get('/api/notifications/vapid-key')
    if (!data.public_key) throw new Error('Push notifications are not configured on the server yet.')

    const reg = await navigator.serviceWorker.ready
    const sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(data.public_key),
    })
    const json = sub.toJSON()
    await api.post('/api/notifications/push/subscribe', { endpoint: json.endpoint, keys: json.keys })
  }

  async function unsubscribe() {
    if (!supported) return
    const reg = await navigator.serviceWorker.ready
    const sub = await reg.pushManager.getSubscription()
    if (sub) {
      await api.post('/api/notifications/push/unsubscribe', { endpoint: sub.endpoint }).catch(() => {})
      await sub.unsubscribe()
    }
  }

  return { supported, isSubscribed, subscribe, unsubscribe }
}
