<template>
  <div v-if="visible" class="sn-card p-4" data-testid="readiness-guidance-panel">
    <div class="flex items-start justify-between gap-3 mb-3">
      <div>
        <p class="sn-eyebrow">Hannah</p>
        <h2 class="text-sm font-semibold mt-0.5" style="color: var(--text-primary);">{{ title }}</h2>
        <p class="text-xs mt-1" style="color: var(--text-secondary);">
          {{ gaps.length }} thing{{ gaps.length === 1 ? '' : 's' }} left before this is fully live. Tap one to fix it or ask me how.
        </p>
      </div>
      <button type="button" class="shrink-0 text-xs px-2 py-1 rounded" style="color: var(--text-muted);" @click="dismiss">
        Dismiss
      </button>
    </div>

    <ul class="space-y-2">
      <li v-for="item in items" :key="item.key">
        <div
          class="w-full rounded-lg px-3 py-2 flex items-start justify-between gap-3"
          style="background: var(--bg-elevated); border: 1px solid var(--border);"
        >
          <div class="min-w-0">
            <p class="text-sm font-medium flex items-center gap-1.5" style="color: var(--text-primary);">
              <span v-if="item.status === 'ok'" style="color: var(--accent);">✓</span>
              <span v-else-if="item.status === 'warning'" style="color: #FFAA00;">⚠</span>
              <span v-else style="color: #FF6B6B;">●</span>
              {{ item.label }}
            </p>
            <p v-if="item.detail" class="text-xs mt-0.5" style="color: var(--text-muted);">{{ item.detail }}</p>
          </div>
          <button
            v-if="item.ask_hannah_prompt"
            type="button"
            class="shrink-0 text-xs px-3 py-1.5 rounded-lg font-medium"
            style="background: var(--accent-weak); color: var(--accent); border: 1px solid rgba(0,229,200,0.30);"
            @click="askHannah(item)"
          >
            Ask Hannah
          </button>
          <a
            v-else-if="item.doc_link"
            :href="item.doc_link"
            target="_blank"
            rel="noopener"
            class="shrink-0 text-xs px-3 py-1.5 rounded-lg font-medium"
            style="background: var(--bg-card); color: var(--text-secondary); border: 1px solid var(--border);"
          >
            View docs
          </a>
        </div>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'

const props = defineProps({
  items: { type: Array, default: () => [] },
  title: { type: String, default: 'Go-live checklist' },
  storageKey: { type: String, default: 'cockpit:readiness-guidance-dismissed' },
})

const router = useRouter()
const dismissedLocal = ref(false)

const gaps = computed(() => props.items.filter((i) => i.status !== 'ok'))

const visible = computed(() => {
  if (dismissedLocal.value) return false
  if (typeof window !== 'undefined' && window.localStorage.getItem(props.storageKey) === '1') return false
  return gaps.value.length > 0
})

function askHannah(item) {
  router.push({ path: '/atlas', query: { hannah: '1', prefill: item.ask_hannah_prompt } })
}

function dismiss() {
  dismissedLocal.value = true
  if (typeof window !== 'undefined') window.localStorage.setItem(props.storageKey, '1')
}
</script>

<style scoped>
.sn-eyebrow { font-size: 11px; letter-spacing: 0.18em; text-transform: uppercase; color: var(--accent); }
.sn-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; }
</style>
