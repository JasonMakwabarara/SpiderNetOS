import { computed, readonly, ref } from 'vue'

// Module scope: beforeinstallprompt fires once, early — capture it at import
// time (main.js bootstrap) so no component race can miss it.
const deferredPrompt = ref(null)
const installed = ref(false)

const isStandalone = () =>
  window.matchMedia('(display-mode: standalone), (display-mode: minimal-ui)').matches ||
  window.navigator.standalone === true

const isIOS = () =>
  /iphone|ipad|ipod/i.test(navigator.userAgent) ||
  (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)

if (typeof window !== 'undefined') {
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault() // suppress Chrome's mini-infobar; we render our own UI
    deferredPrompt.value = e
  })
  window.addEventListener('appinstalled', () => {
    deferredPrompt.value = null
    installed.value = true
  })
}

export function usePwaInstall() {
  const canPrompt = computed(() => !!deferredPrompt.value)

  /** Shows the native install dialog. Returns 'accepted' | 'dismissed' | null. */
  async function promptInstall() {
    const e = deferredPrompt.value
    if (!e) return null
    e.prompt()
    const { outcome } = await e.userChoice
    // The event is single-use; Chrome re-fires beforeinstallprompt later if dismissed.
    deferredPrompt.value = null
    return outcome
  }

  return {
    canPrompt,
    installed: readonly(installed),
    promptInstall,
    isStandalone,
    isIOS,
  }
}
