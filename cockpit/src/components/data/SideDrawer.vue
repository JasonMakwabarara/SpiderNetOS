<template>
  <Teleport to="body">
    <Transition name="sn-drawer">
      <div
        v-if="modelValue"
        class="fixed inset-0 z-[70] flex justify-end"
        style="background: rgba(5,7,10,0.55); backdrop-filter: blur(6px);"
        data-testid="side-drawer-backdrop"
        @click.self="onBackdrop"
      >
        <aside
          ref="panelRef"
          class="sn-drawer"
          :style="{ width }"
          role="dialog"
          aria-modal="true"
          :aria-label="title || undefined"
          :aria-labelledby="$slots.header ? undefined : titleId"
          tabindex="-1"
          data-testid="side-drawer"
        >
          <header
            class="flex items-start justify-between gap-3 px-5 py-4 border-b shrink-0"
            style="border-color: var(--border);"
          >
            <div class="min-w-0 flex-1">
              <slot name="header">
                <p v-if="eyebrow" class="sn-eyebrow">{{ eyebrow }}</p>
                <h2
                  :id="titleId"
                  class="text-base font-semibold truncate"
                  style="color: var(--text-primary);"
                  data-testid="side-drawer-title"
                >{{ title }}</h2>
              </slot>
            </div>
            <button
              type="button"
              class="sn-btn-secondary shrink-0"
              style="padding: 0.35rem 0.6rem;"
              aria-label="Close"
              data-testid="side-drawer-close"
              @click="close"
            >✕</button>
          </header>

          <div class="flex-1 overflow-y-auto px-5 py-4" data-testid="side-drawer-body">
            <slot />
          </div>

          <footer
            v-if="$slots.footer"
            class="px-5 py-3 border-t shrink-0"
            style="border-color: var(--border); background: rgba(0,0,0,0.2);"
            data-testid="side-drawer-footer"
          >
            <slot name="footer" />
          </footer>
        </aside>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
/**
 * SideDrawer — right-hand modal panel. Teleports to <body>, traps Tab
 * focus inside, closes on Esc / backdrop click, and returns focus to the
 * element that opened it. Owns the a11y contract so every drawer in the
 * cockpit (brain file, map node, run detail…) behaves the same way.
 */
import { ref, watch, nextTick, onBeforeUnmount } from 'vue'

const props = defineProps({
  modelValue:      { type: Boolean, default: false },
  title:           { type: String, default: '' },
  eyebrow:         { type: String, default: '' },
  width:           { type: String, default: '480px' },
  closeOnBackdrop: { type: Boolean, default: true },
})

const emit = defineEmits(['update:modelValue', 'close'])

const panelRef = ref(null)
const titleId = `side-drawer-title-${Math.random().toString(36).slice(2, 9)}`

const FOCUSABLE =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), ' +
  'select:not([disabled]), [tabindex]:not([tabindex="-1"])'

let previouslyFocused = null

function focusables() {
  const root = panelRef.value
  if (!root) return []
  return Array.from(root.querySelectorAll(FOCUSABLE)).filter((el) => !el.hasAttribute('hidden'))
}

function close() {
  emit('update:modelValue', false)
  emit('close')
}

function onBackdrop() {
  if (props.closeOnBackdrop) close()
}

function onKeydown(event) {
  if (event.key === 'Escape') {
    event.stopPropagation()
    close()
    return
  }
  if (event.key !== 'Tab') return

  const list = focusables()
  if (!list.length) {
    event.preventDefault()
    panelRef.value?.focus()
    return
  }
  const first = list[0]
  const last = list[list.length - 1]
  const active = document.activeElement
  const inside = panelRef.value?.contains(active)

  if (event.shiftKey && (active === first || active === panelRef.value || !inside)) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && (active === last || !inside)) {
    event.preventDefault()
    first.focus()
  }
}

function attach() {
  previouslyFocused = document.activeElement
  document.addEventListener('keydown', onKeydown)
  document.body.style.overflow = 'hidden'
  nextTick(() => {
    const root = panelRef.value
    if (!root) return
    const preferred = root.querySelector('[autofocus]')
    ;(preferred || focusables()[0] || root).focus?.()
  })
}

function detach() {
  document.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
  const prev = previouslyFocused
  previouslyFocused = null
  if (prev && typeof prev.focus === 'function' && prev.isConnected !== false) prev.focus()
}

watch(
  () => props.modelValue,
  (open, was) => {
    if (open && !was) attach()
    else if (!open && was) detach()
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  if (props.modelValue) detach()
})
</script>

<style scoped>
.sn-drawer-enter-active, .sn-drawer-leave-active { transition: opacity 0.16s ease; }
.sn-drawer-enter-from, .sn-drawer-leave-to { opacity: 0; }
.sn-drawer-enter-active > aside, .sn-drawer-leave-active > aside { transition: transform 0.18s ease; }
.sn-drawer-enter-from > aside, .sn-drawer-leave-to > aside { transform: translateX(24px); }
</style>
