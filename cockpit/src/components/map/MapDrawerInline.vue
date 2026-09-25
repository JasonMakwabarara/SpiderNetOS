<template>
  <aside
    v-if="modelValue"
    class="sn-drawer w-[380px] shrink-0 rounded-xl overflow-hidden"
    role="complementary"
    :aria-labelledby="titleId"
    data-testid="map-drawer-inline"
    @keydown.esc.stop="close"
  >
    <header
      class="flex items-start justify-between gap-3 px-5 py-4 border-b shrink-0"
      style="border-color: var(--border);"
    >
      <div class="min-w-0 flex-1">
        <p v-if="eyebrow" class="sn-eyebrow">{{ eyebrow }}</p>
        <h2 :id="titleId" class="text-base font-semibold truncate" style="color: var(--text-primary);">{{ title }}</h2>
        <slot name="subtitle" />
      </div>
      <button
        type="button"
        class="sn-btn-secondary shrink-0"
        style="padding: 0.35rem 0.6rem;"
        aria-label="Close node details"
        data-testid="map-drawer-close"
        @click="close"
      >✕</button>
    </header>

    <div class="flex-1 overflow-y-auto px-5 py-4" data-testid="map-drawer-body">
      <slot />
    </div>

    <footer
      v-if="$slots.footer"
      class="px-5 py-3 border-t shrink-0"
      style="border-color: var(--border); background: rgba(0,0,0,0.2);"
    >
      <slot name="footer" />
    </footer>
  </aside>
</template>

<script setup>
/**
 * MapDrawerInline — the ≥lg, non-modal twin of SideDrawer: same props and
 * slots, rendered in the page flow beside the map so the map stays usable
 * while a node is open. MapNodeDrawer swaps between the two.
 */
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title:      { type: String, default: '' },
  eyebrow:    { type: String, default: '' },
  width:      { type: String, default: '380px' },
})

const emit = defineEmits(['update:modelValue', 'close'])

const titleId = `map-drawer-title-${Math.random().toString(36).slice(2, 9)}`

function close() {
  if (!props.modelValue) return
  emit('update:modelValue', false)
  emit('close')
}
</script>
