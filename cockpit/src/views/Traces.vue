<template>
  <div class="px-8 py-6 max-w-[1500px] mx-auto space-y-5">
    <!-- Header -->
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-[26px] font-heading font-semibold tracking-tight" style="color: var(--text-primary);">Traces</h1>
        <p class="text-sm mt-1" style="color: var(--text-muted);">
          Searchable, replayable execution history. Share any trace as a read-only link.
        </p>
      </div>
      <button class="sn-btn" data-testid="traces-refresh" @click="refresh">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8 8 0 004.582 9M20 20v-5h-.581m0 0a8 8 0 01-15.357-2"/>
        </svg>
        Refresh
      </button>
    </header>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-3">
      <div class="relative flex-1 min-w-[260px]">
        <input v-model="searchQuery" type="search" placeholder="Search by id, subject, actor…"
               class="pl-9" data-testid="traces-search" />
        <svg class="absolute left-2.5 top-2.5 w-4 h-4" style="color: var(--text-muted);"
             fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="M21 21l-4.3-4.3"/>
        </svg>
      </div>
      <select v-model="statusFilter" data-testid="traces-status-filter" class="max-w-[180px]">
        <option value="">All statuses</option>
        <option value="ok">OK</option>
        <option value="warn">Warn</option>
        <option value="error">Error</option>
      </select>
      <input v-model="dateFrom" type="date" class="max-w-[170px]" data-testid="traces-date-from" />
      <span class="text-xs" style="color: var(--text-muted);">to</span>
      <input v-model="dateTo" type="date" class="max-w-[170px]" data-testid="traces-date-to" />
    </div>

    <!-- Stats -->
    <section class="grid grid-cols-2 md:grid-cols-4 gap-3" data-testid="traces-stats">
      <div class="sn-card p-4">
        <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Total</div>
        <div class="mt-1 text-xl font-heading font-semibold mono" style="color: var(--text-primary);">{{ tracesStore.traces.length }}</div>
      </div>
      <div class="sn-card p-4">
        <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">OK</div>
        <div class="mt-1 text-xl font-heading font-semibold mono" style="color: var(--success);">{{ countByStatus.ok }}</div>
      </div>
      <div class="sn-card p-4">
        <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Warn / Error</div>
        <div class="mt-1 text-xl font-heading font-semibold mono" style="color: var(--warn);">{{ countByStatus.warn + countByStatus.error }}</div>
      </div>
      <div class="sn-card p-4">
        <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Total cost</div>
        <div class="mt-1 text-xl font-heading font-semibold mono" style="color: var(--text-primary);">${{ totalCost.toFixed(4) }}</div>
      </div>
    </section>

    <!-- List -->
    <section class="sn-card overflow-hidden">
      <div v-if="filteredTraces.length === 0" class="px-6 py-16 text-center text-sm" style="color: var(--text-muted);">
        No traces match the current filters.
      </div>
      <ul v-else class="divide-y" style="border-color: var(--divider);">
        <li v-for="t in filteredTraces" :key="t.id" data-testid="trace-row">
          <header
            class="flex items-center gap-3 px-4 py-3 cursor-pointer transition-colors"
            :class="expanded.has(t.id) ? 'bg-ink-700' : ''"
            @click="toggle(t.id)"
          >
            <span class="w-1.5 h-1.5 rounded-full shrink-0" :style="dot(t.status)"></span>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 text-sm">
                <span class="mono text-[12px]" style="color: var(--text-muted);">{{ t.id }}</span>
                <span class="sn-pill text-[10px]">{{ t.kind }}</span>
                <span class="truncate" style="color: var(--text-primary);">{{ t.subject }}</span>
              </div>
              <div class="text-[11px] mt-0.5" style="color: var(--text-muted);">
                {{ t.actor }} · {{ formatDate(t.created_at) }}
              </div>
            </div>
            <div class="hidden md:flex items-center gap-5 text-xs">
              <span class="mono" style="color: var(--text-secondary);">{{ t.duration_ms }}ms</span>
              <span class="mono" style="color: var(--text-secondary);">${{ (t.cost_usd || 0).toFixed(4) }}</span>
              <span class="sn-pill" :class="statusPill(t.status)">{{ t.status }}</span>
            </div>
            <svg class="w-4 h-4 transition-transform" :class="expanded.has(t.id) ? 'rotate-180' : ''"
                 style="color: var(--text-muted);" viewBox="0 0 20 20" fill="currentColor">
              <path d="M5 7l5 5 5-5H5z"/>
            </svg>
          </header>

          <div v-if="expanded.has(t.id)" class="px-5 pb-5 grid grid-cols-1 md:grid-cols-[1fr_320px] gap-5">
            <!-- Timeline -->
            <div>
              <div class="text-[10px] tracking-widest uppercase font-semibold mb-2" style="color: var(--text-muted);">Timeline</div>
              <ol class="relative pl-5">
                <span class="absolute left-1.5 top-1 bottom-1 w-px" style="background: var(--border);"></span>
                <li v-for="(ev, i) in (detailFor(t)?.events || [])" :key="i" class="relative pb-3">
                  <span class="absolute -left-[15px] top-1.5 w-2 h-2 rounded-full"
                        :style="`background: ${
                          ev.level === 'warn'  ? 'var(--warn)' :
                          ev.level === 'error' ? 'var(--danger)' :
                          'var(--accent)'
                        };`"></span>
                  <div class="text-sm" style="color: var(--text-primary);">{{ ev.msg }}</div>
                  <div class="text-[11px] mt-0.5 mono" style="color: var(--text-muted);">{{ formatDate(ev.ts) }}</div>
                </li>
              </ol>
            </div>
            <!-- Side: metadata + share -->
            <aside class="space-y-3">
              <div class="sn-card p-3">
                <div class="text-[10px] tracking-widest uppercase font-semibold mb-1" style="color: var(--text-muted);">Metadata</div>
                <pre class="mono text-[11px] whitespace-pre-wrap break-words"
                     style="color: var(--text-secondary);">{{ formatJSON(t.metadata) }}</pre>
              </div>
              <button
                class="sn-btn w-full justify-center"
                :disabled="sharingId === t.id"
                :data-testid="`trace-share-${t.id}`"
                @click="openShare(t)"
              >
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8.7 13.3a3 3 0 010-2.6l5.6-3.2a3 3 0 102 0l-5.6 3.2a3 3 0 100 2.6l5.6 3.2a3 3 0 102 0l-5.6-3.2z"/>
                </svg>
                {{ sharingId === t.id ? 'Generating link…' : 'Share trace' }}
              </button>
            </aside>
          </div>
        </li>
      </ul>
    </section>

    <!-- Share dialog -->
    <Teleport to="body">
      <Transition name="cmdbar">
        <div
          v-if="shareDialog.open"
          class="fixed inset-0 z-[9998] flex items-center justify-center px-4"
          style="background: rgba(5,7,10,0.65); backdrop-filter: blur(6px);"
          role="dialog" aria-modal="true"
          data-testid="trace-share-dialog"
          @click.self="closeShare"
          @keydown.escape="closeShare"
        >
          <div class="w-full max-w-lg sn-panel overflow-hidden">
            <header class="px-5 py-4 border-b" style="border-color: var(--border);">
              <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Share-a-Trace</div>
              <h3 class="font-heading font-semibold text-[15px] mt-0.5" style="color: var(--text-primary);">
                Read-only link · {{ shareDialog.trace?.id }}
              </h3>
            </header>
            <div class="px-5 py-4 space-y-3 text-sm" style="color: var(--text-secondary);">
              <p>
                Anyone with this link can read the trace timeline, metadata and outcomes —
                but cannot replay, sign in or see other traces. Expires in 7 days.
              </p>
              <div class="flex items-stretch gap-2">
                <input
                  ref="urlRef"
                  :value="shareDialog.url"
                  readonly
                  class="mono text-xs flex-1 select-all"
                  data-testid="trace-share-url"
                />
                <button class="sn-btn-primary" data-testid="trace-share-copy" @click="copyShareUrl">
                  {{ shareDialog.copied ? 'Copied' : 'Copy' }}
                </button>
              </div>
              <p class="text-[11px] mono" style="color: var(--text-muted);">
                expires {{ formatDate(shareDialog.expires) }}
              </p>
            </div>
            <footer class="px-5 py-3 flex justify-end border-t"
                    style="border-color: var(--border); background: rgba(0,0,0,0.25);">
              <button class="sn-btn" data-testid="trace-share-close" @click="closeShare">Close</button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, reactive, onMounted, nextTick } from 'vue'
import { useTracesStore } from '../stores/traces.js'
import api from '../services/api.js'

const tracesStore = useTracesStore()

const searchQuery = ref('')
const statusFilter = ref('')
const dateFrom = ref('')
const dateTo = ref('')
const expanded = reactive(new Set())
const detailCache = reactive({})

const sharingId = ref(null)
const urlRef = ref(null)
const shareDialog = reactive({ open: false, trace: null, url: '', expires: '', copied: false })

const filteredTraces = computed(() => {
  let list = tracesStore.traces || []
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase()
    list = list.filter((t) =>
      String(t.id).toLowerCase().includes(q) ||
      (t.subject || '').toLowerCase().includes(q) ||
      (t.actor || '').toLowerCase().includes(q) ||
      (t.kind || '').toLowerCase().includes(q)
    )
  }
  if (statusFilter.value) list = list.filter((t) => t.status === statusFilter.value)
  if (dateFrom.value) {
    const from = new Date(dateFrom.value)
    list = list.filter((t) => new Date(t.created_at) >= from)
  }
  if (dateTo.value) {
    const to = new Date(dateTo.value); to.setHours(23, 59, 59, 999)
    list = list.filter((t) => new Date(t.created_at) <= to)
  }
  return list
})

const countByStatus = computed(() => {
  const out = { ok: 0, warn: 0, error: 0 }
  for (const t of (tracesStore.traces || [])) {
    if (t.status in out) out[t.status]++
  }
  return out
})

const totalCost = computed(() => (tracesStore.traces || []).reduce((s, t) => s + (t.cost_usd || 0), 0))

function detailFor(t) {
  return detailCache[t.id] || t
}

function dot(s) {
  if (s === 'ok')    return 'background: var(--success); box-shadow: 0 0 6px rgba(34,211,155,0.6);'
  if (s === 'warn')  return 'background: var(--warn);    box-shadow: 0 0 6px rgba(245,165,36,0.6);'
  if (s === 'error') return 'background: var(--danger);  box-shadow: 0 0 6px rgba(255,90,122,0.6);'
  return 'background: var(--text-muted);'
}
function statusPill(s) {
  if (s === 'ok')    return 'sn-pill-success'
  if (s === 'warn')  return 'sn-pill-warn'
  if (s === 'error') return 'sn-pill-danger'
  return ''
}
function formatDate(ts) {
  if (!ts) return ''
  return new Date(ts).toLocaleString()
}
function formatJSON(o) {
  if (!o) return '{}'
  try { return JSON.stringify(o, null, 2) } catch { return String(o) }
}

async function toggle(id) {
  if (expanded.has(id)) { expanded.delete(id); return }
  expanded.add(id)
  if (!detailCache[id]) {
    try {
      const { data } = await api.get(`/api/traces/${id}`)
      detailCache[id] = data?.data || null
    } catch { /* keep collapsed-style data */ }
  }
}

function refresh() { tracesStore.fetchTraces?.() }

async function openShare(t) {
  sharingId.value = t.id
  try {
    const { data } = await api.post(`/api/traces/${t.id}/share`)
    const path = data?.share_path || `/share/trace/${data.token}`
    shareDialog.trace   = t
    shareDialog.url     = `${window.location.origin}${path}`
    shareDialog.expires = data?.expires_at || ''
    shareDialog.copied  = false
    shareDialog.open    = true
    nextTick(() => urlRef.value?.select())
  } catch (err) {
    console.error('share-trace failed', err)
  } finally {
    sharingId.value = null
  }
}

function closeShare() { shareDialog.open = false }

async function copyShareUrl() {
  try {
    await navigator.clipboard.writeText(shareDialog.url)
    shareDialog.copied = true
    setTimeout(() => { shareDialog.copied = false }, 2400)
  } catch {
    urlRef.value?.select()
  }
}

onMounted(() => tracesStore.fetchTraces?.())
</script>

<style scoped>
.cmdbar-enter-active, .cmdbar-leave-active { transition: opacity 0.16s ease; }
.cmdbar-enter-from, .cmdbar-leave-to { opacity: 0; }
</style>
