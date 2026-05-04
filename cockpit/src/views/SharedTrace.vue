<template>
  <div class="min-h-screen px-4 py-10" style="background: var(--gradient-hero);">
    <div class="max-w-3xl mx-auto">
      <!-- Brand strip -->
      <header class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2">
          <div class="w-7 h-7 rounded-md flex items-center justify-center"
               style="background: linear-gradient(135deg,#00E5C8,#087D6E); box-shadow: 0 0 0 1px rgba(0,229,200,0.35);">
            <svg class="w-4 h-4" style="color:#05070A;" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 2l2.4 4.9 5.4.8-3.9 3.8.9 5.4L12 14.3 7.2 17l.9-5.4-3.9-3.8 5.4-.8L12 2z"/>
            </svg>
          </div>
          <span class="font-heading font-semibold tracking-tight sn-grad-text text-[15px]">SpiderNetOS</span>
          <span class="sn-pill ml-2 text-[10px]">Shared trace</span>
        </div>
        <RouterLink to="/login" class="text-xs hover:underline" style="color: var(--accent);">Open the cockpit →</RouterLink>
      </header>

      <!-- Loading -->
      <div v-if="loading" class="sn-card p-12 text-center text-sm" style="color: var(--text-muted);">
        Loading shared trace…
      </div>

      <!-- Error -->
      <div v-else-if="error" class="sn-card p-8 text-center" data-testid="share-trace-error">
        <h2 class="font-heading font-semibold text-[16px]" style="color: var(--text-primary);">
          This share link is invalid or has expired.
        </h2>
        <p class="text-sm mt-2" style="color: var(--text-muted);">
          Ask the operator to mint a new link from <code class="mono">/traces</code>.
        </p>
      </div>

      <!-- Trace -->
      <article v-else-if="trace" class="sn-card overflow-hidden" data-testid="share-trace-card">
        <header class="px-5 py-4 border-b flex items-start justify-between gap-3" style="border-color: var(--border);">
          <div>
            <div class="flex items-center gap-2 mb-1">
              <span class="sn-pill text-[10px]">{{ trace.kind }}</span>
              <span class="sn-pill" :class="statusPill(trace.status)">{{ trace.status }}</span>
              <span class="sn-pill text-[10px]" style="color: var(--text-muted);">read-only</span>
            </div>
            <h1 class="font-heading font-semibold text-[18px]" style="color: var(--text-primary);">
              {{ trace.subject || trace.id }}
            </h1>
            <p class="text-xs mt-1 mono" style="color: var(--text-muted);">
              {{ trace.id }} · {{ trace.actor }} · {{ formatDate(trace.created_at) }}
            </p>
          </div>
          <div class="text-right">
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Tenant</div>
            <div class="text-sm" style="color: var(--text-secondary);">{{ trace.tenant_name }}</div>
          </div>
        </header>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-5 border-b" style="border-color: var(--border);">
          <div class="sn-card p-3">
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Duration</div>
            <div class="mt-1 mono text-lg" style="color: var(--text-primary);">{{ trace.duration_ms }} ms</div>
          </div>
          <div class="sn-card p-3">
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Cost</div>
            <div class="mt-1 mono text-lg" style="color: var(--text-primary);">${{ (trace.cost_usd || 0).toFixed(4) }}</div>
          </div>
          <div class="sn-card p-3">
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Model</div>
            <div class="mt-1 mono text-sm" style="color: var(--text-primary);">{{ trace.metadata?.model || '—' }}</div>
          </div>
        </div>

        <section class="px-5 py-4">
          <div class="text-[10px] tracking-widest uppercase font-semibold mb-2" style="color: var(--text-muted);">
            Timeline
          </div>
          <ul class="space-y-2">
            <li v-for="(ev, i) in (trace.events || [])" :key="i"
                class="flex items-start gap-3">
              <span class="mt-1 w-1.5 h-1.5 rounded-full shrink-0"
                    :style="`background: ${
                      ev.level === 'warn'  ? 'var(--warn)' :
                      ev.level === 'error' ? 'var(--danger)' :
                      'var(--accent)'
                    };`"></span>
              <div class="min-w-0 flex-1">
                <div class="text-sm" style="color: var(--text-primary);">{{ ev.msg }}</div>
                <div class="text-[11px] mt-0.5 mono" style="color: var(--text-muted);">{{ formatDate(ev.ts) }}</div>
              </div>
            </li>
          </ul>
        </section>

        <footer class="px-5 py-3 border-t flex items-center justify-between text-xs"
                style="border-color: var(--border); background: rgba(0,0,0,0.25); color: var(--text-muted);">
          <span>Shared on {{ formatDate(trace.shared_at) }}</span>
          <span>Expires {{ formatDate(trace.expires_at) }}</span>
        </footer>
      </article>

      <p class="mt-6 text-center text-[11px]" style="color: var(--text-muted);">
        This is a tenant-scoped, read-only view. SpiderNetOS does not require
        sign-in to read it.
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '../services/api.js'

const route = useRoute()
const trace = ref(null)
const loading = ref(true)
const error = ref(null)

async function load() {
  loading.value = true
  try {
    const { data } = await api.get(`/api/public/traces/${route.params.token}`)
    trace.value = data?.data || null
    if (!trace.value) error.value = 'not_found'
  } catch (err) {
    error.value = err.response?.status === 404 ? 'not_found' : 'unknown'
  } finally {
    loading.value = false
  }
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

onMounted(load)
</script>
