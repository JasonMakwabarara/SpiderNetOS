<template>
  <div class="dct-card p-6 space-y-4">
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Notifications</h2>
        <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }">
          Get alerted when an approval is waiting or your AI budget nears its limit — even when the cockpit is closed.
        </p>
      </div>
      <span class="text-xs px-2 py-1 rounded-full" :class="subscribed ? 'dct-pill-lime' : 'dct-pill-cyan'">
        {{ subscribed ? 'On this device' : 'Off' }}
      </span>
    </div>

    <p v-if="!supported" class="text-sm" :style="{ color: 'var(--text-muted)' }">
      This browser doesn't support push notifications.
    </p>

    <div v-else class="flex gap-2">
      <button v-if="!subscribed" class="dct-btn-primary px-5 py-2.5 text-sm" :disabled="busy" @click="enable">
        {{ busy ? 'Enabling…' : 'Enable notifications' }}
      </button>
      <button v-else class="px-4 py-2.5 rounded-xl text-sm border"
        :style="{ borderColor: 'var(--border)', color: 'var(--text-secondary)', background: 'var(--surface-low)' }"
        :disabled="busy" @click="disable">
        Turn off on this device
      </button>
    </div>

    <p v-if="error" class="text-xs text-red-500">{{ error }}</p>

    <!-- Per-event preferences -->
    <div class="space-y-2 pt-2">
      <label v-for="ev in events" :key="ev.key" class="flex items-center justify-between py-2 border-b"
        :style="{ borderColor: 'var(--border)' }">
        <span class="text-sm" :style="{ color: 'var(--text-primary)' }">{{ ev.label }}</span>
        <input type="checkbox" v-model="prefs[ev.key]" @change="savePref(ev.key)" class="w-4 h-4" />
      </label>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import api from '../../services/api.js'
import { useWebPush } from '../../composables/useWebPush.js'

const { supported, isSubscribed, subscribe, unsubscribe } = useWebPush()

const subscribed = ref(false)
const busy = ref(false)
const error = ref('')

const events = [
  { key: 'approval_pending', label: 'An approval is waiting for me' },
  { key: 'budget_alert', label: 'AI budget threshold reached' },
]
const prefs = reactive({ approval_pending: true, budget_alert: true })

onMounted(async () => {
  if (supported) subscribed.value = await isSubscribed()
  try {
    const { data } = await api.get('/api/notifications/preferences')
    for (const p of data.data || []) {
      if (p.channel === 'push' && p.event_type in prefs) prefs[p.event_type] = !!p.enabled
    }
  } catch { /* defaults */ }
})

async function enable() {
  busy.value = true
  error.value = ''
  try {
    await subscribe()
    subscribed.value = true
  } catch (e) {
    error.value = e.message || 'Could not enable notifications.'
  } finally {
    busy.value = false
  }
}

async function disable() {
  busy.value = true
  try {
    await unsubscribe()
    subscribed.value = false
  } finally {
    busy.value = false
  }
}

async function savePref(eventType) {
  try {
    await api.put('/api/notifications/preferences', {
      event_type: eventType, channel: 'push', enabled: prefs[eventType],
    })
  } catch { /* non-fatal */ }
}
</script>
