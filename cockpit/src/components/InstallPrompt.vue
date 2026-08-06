<template>
  <Transition name="ip-slide">
    <div
      v-if="visible"
      class="fixed bottom-4 right-4 z-50 w-[340px] max-w-[calc(100vw-2rem)] rounded-lg border border-ink-500 bg-ink-800 p-4 shadow-panel-hi"
      role="dialog"
      aria-label="Install SpiderNetOS"
      data-testid="pwa-install-prompt"
    >
      <div class="flex items-start gap-3">
        <div class="shrink-0 rounded-md border border-ink-600 bg-ink-950 p-2">
          <svg width="28" height="28" viewBox="0 0 32 32" fill="none" aria-hidden="true">
            <defs>
              <linearGradient id="sn-ip" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#FF6B2C" />
                <stop offset="1" stop-color="#00D6C9" />
              </linearGradient>
            </defs>
            <circle cx="16" cy="16" r="3" fill="url(#sn-ip)" />
            <g stroke="url(#sn-ip)" stroke-width="1.4" stroke-linecap="round" opacity="0.9">
              <line x1="16" y1="16" x2="6" y2="6" /><line x1="16" y1="16" x2="26" y2="6" />
              <line x1="16" y1="16" x2="6" y2="26" /><line x1="16" y1="16" x2="26" y2="26" />
              <line x1="16" y1="16" x2="16" y2="2" /><line x1="16" y1="16" x2="16" y2="30" />
              <line x1="16" y1="16" x2="2" y2="16" /><line x1="16" y1="16" x2="30" y2="16" />
            </g>
          </svg>
        </div>

        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-fg-primary">Install SpiderNetOS</p>

          <template v-if="mode === 'native'">
            <p class="mt-1 text-xs leading-relaxed text-fg-secondary">
              Get the cockpit as an app — full-screen, offline-ready, with push alerts from your agents.
            </p>
            <div class="mt-3 flex items-center gap-2">
              <button
                class="rounded-md bg-cyan-500 px-3.5 py-1.5 text-xs font-semibold text-fg-inverted hover:bg-cyan-400"
                data-testid="pwa-install-accept"
                @click="onInstall"
              >
                Install app
              </button>
              <button
                class="rounded-md px-3 py-1.5 text-xs font-medium text-fg-muted hover:bg-ink-600 hover:text-fg-secondary"
                data-testid="pwa-install-dismiss"
                @click="dismiss"
              >
                Not now
              </button>
            </div>
          </template>

          <template v-else>
            <p class="mt-1 text-xs leading-relaxed text-fg-secondary">
              Add the cockpit to your Home Screen: tap
              <svg class="inline-block h-3.5 w-3.5 align-[-2px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-label="Share">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0-12l-4 4m4-4l4 4M5 13v6a2 2 0 002 2h10a2 2 0 002-2v-6" />
              </svg>
              <span class="font-medium text-fg-primary">Share</span>, then
              <span class="font-medium text-fg-primary">Add to Home Screen</span>.
            </p>
            <div class="mt-3">
              <button
                class="rounded-md px-3 py-1.5 text-xs font-medium text-fg-muted hover:bg-ink-600 hover:text-fg-secondary"
                data-testid="pwa-install-dismiss"
                @click="dismiss"
              >
                Got it
              </button>
            </div>
          </template>
        </div>

        <button
          class="shrink-0 rounded p-1 text-fg-muted hover:bg-ink-600 hover:text-fg-secondary"
          aria-label="Dismiss install prompt"
          @click="dismiss"
        >
          <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
            <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
          </svg>
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { usePwaInstall } from '../composables/usePwaInstall.js'

const SNOOZE_KEY = 'snos.installPrompt.dismissedAt'
const SNOOZE_DAYS = 14

const { canPrompt, installed, promptInstall, isStandalone, isIOS } = usePwaInstall()

const visible = ref(false)
const mode = ref('native') // 'native' (Chromium prompt) | 'ios' (manual instructions)
let showTimer = null

function snoozed() {
  const at = Number(localStorage.getItem(SNOOZE_KEY) || 0)
  return at && Date.now() - at < SNOOZE_DAYS * 24 * 60 * 60 * 1000
}

function scheduleShow(nextMode, delay) {
  clearTimeout(showTimer)
  showTimer = setTimeout(() => {
    mode.value = nextMode
    visible.value = true
  }, delay)
}

function dismiss() {
  visible.value = false
  localStorage.setItem(SNOOZE_KEY, String(Date.now()))
}

async function onInstall() {
  const outcome = await promptInstall()
  visible.value = false
  if (outcome !== 'accepted') localStorage.setItem(SNOOZE_KEY, String(Date.now()))
}

watch(canPrompt, (ready) => {
  if (ready && !isStandalone() && !snoozed()) scheduleShow('native', 2500)
})

onMounted(() => {
  if (isStandalone() || snoozed()) return
  if (canPrompt.value) scheduleShow('native', 2500)
  else if (isIOS()) scheduleShow('ios', 5000)
})

watch(installed, (done) => {
  if (done) visible.value = false
})

onBeforeUnmount(() => clearTimeout(showTimer))
</script>

<style scoped>
.ip-slide-enter-active,
.ip-slide-leave-active {
  transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.28s ease;
}
.ip-slide-enter-from,
.ip-slide-leave-to {
  transform: translateY(12px);
  opacity: 0;
}
</style>
