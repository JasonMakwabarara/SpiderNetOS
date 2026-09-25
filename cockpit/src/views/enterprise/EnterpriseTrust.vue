<template>
  <div class="px-6 py-7 max-w-6xl mx-auto" data-testid="ent-trust">
    <div class="mb-7">
      <div class="sn-eyebrow">Enterprise · Audit & Trust</div>
      <h1 class="text-2xl font-semibold tracking-tight mt-1" style="color: var(--text-primary);">
        Audit & Trust Center
      </h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">
        Immutable audit trail, signed exports, and live compliance posture from the same telemetry your tenants run on.
      </p>
    </div>

    <!-- Summary tiles -->
    <div v-if="summary" class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-7">
      <div class="sn-tile">
        <div class="mono-label">Uptime · 30d</div>
        <div class="value">{{ summary.uptime.current_30d }}%</div>
      </div>
      <div class="sn-tile">
        <div class="mono-label">SLA target</div>
        <div class="value">{{ summary.uptime.sla_target }}%</div>
      </div>
      <div class="sn-tile">
        <div class="mono-label">Audit · 24h</div>
        <div class="value">{{ summary.operations.audit_events_24h }}</div>
      </div>
      <div class="sn-tile">
        <div class="mono-label">Bundles signed</div>
        <div class="value">{{ summary.operations.bundles_signed_total }}</div>
      </div>
    </div>

    <!-- Live audit -->
    <div class="sn-card mb-6">
      <div class="flex items-center justify-between px-5 py-3 border-b" style="border-color: var(--border);">
        <h2 class="font-medium" style="color: var(--text-primary);">Live audit stream</h2>
        <div class="flex items-center gap-3">
          <input
            v-model="filter"
            data-testid="ent-trust-filter"
            placeholder="Filter action, actor, target…"
            class="sn-input-inline"
          />
          <button @click="exportSample" :disabled="exporting" data-testid="ent-trust-export" class="sn-btn-ghost">
            {{ exporting ? 'Signing…' : 'Generate signed export sample' }}
          </button>
        </div>
      </div>
      <div v-if="loadingAudit" class="px-5 py-8 text-sm" style="color: var(--text-muted);">Loading events…</div>
      <ol v-else>
        <li
          v-for="e in filteredEvents"
          :key="e.id"
          class="px-5 py-3 flex gap-4 hover:bg-white/[0.02]"
          style="border-bottom: 1px solid var(--divider);"
        >
          <span class="dot" :class="`dot-${e.severity || 'info'}`"></span>
          <div class="flex-1 grid md:grid-cols-12 gap-2 text-sm">
            <div class="md:col-span-3 mono" style="color: var(--text-muted); font-size: 11px;">
              {{ formatTime(e.ts) }}
            </div>
            <div class="md:col-span-3 mono" style="color: var(--text-primary);">{{ e.action }}</div>
            <div class="md:col-span-3 truncate" style="color: var(--text-secondary);">{{ e.actor }}</div>
            <div class="md:col-span-3 truncate" style="color: var(--text-secondary);">{{ e.target }}</div>
          </div>
        </li>
        <li v-if="!filteredEvents.length" class="px-5 py-8 text-center text-sm" style="color: var(--text-muted);">
          No events match this filter.
        </li>
      </ol>
    </div>

    <!-- Signed export sample -->
    <div v-if="sample" class="sn-card p-6">
      <h2 class="font-medium mb-3" style="color: var(--text-primary);">Signed export envelope</h2>
      <p class="text-sm mb-4" style="color: var(--text-secondary);">
        Every audit export ships with a SHA-256 digest and an Ed25519 signature over the exact bytes, signed by
        the same key that signs production AIOS bundles.
      </p>
      <div class="space-y-2 mb-4">
        <KV label="SHA-256" :value="sample.signed_envelope.sha256" />
        <KV label="Signature" :value="sample.signed_envelope.signature" truncate />
        <KV label="Algorithm" :value="sample.signed_envelope.algorithm" />
      </div>
      <details>
        <summary class="cursor-pointer text-sm" style="color: var(--accent);">View redacted sample payload</summary>
        <pre class="mt-3 p-4 rounded-lg mono text-xs overflow-auto"
             style="background: var(--bg-subtle); border: 1px solid var(--divider); color: var(--text-secondary); max-height: 360px;"
        >{{ JSON.stringify({ export_version: '1.0', events: sample.sample }, null, 2) }}</pre>
      </details>
    </div>

    <!-- Compliance posture -->
    <div v-if="summary" class="sn-card mt-6 overflow-hidden">
      <div class="px-5 py-3 border-b" style="border-color: var(--border);">
        <h2 class="font-medium" style="color: var(--text-primary);">Compliance posture</h2>
      </div>
      <div
        v-for="c in summary.compliance"
        :key="c.framework"
        class="px-5 py-3 grid grid-cols-12 gap-3 items-center text-sm"
        style="border-bottom: 1px solid var(--divider);"
      >
        <div class="col-span-12 md:col-span-3 font-medium" style="color: var(--text-primary);">{{ c.framework }}</div>
        <div class="col-span-6 md:col-span-2">
          <span class="sn-pill" :class="statusClass(c.status)">{{ statusLabel(c.status) }}</span>
        </div>
        <div class="col-span-12 md:col-span-4 flex items-center gap-2">
          <div class="flex-1 h-1.5 rounded-full" style="background: var(--bg-elevated);">
            <div class="h-full rounded-full" :style="{ width: c.progress + '%', background: 'linear-gradient(90deg,#FF6B2C,#00D6C9)' }"></div>
          </div>
          <span class="mono text-xs w-10 text-right" style="color: var(--text-secondary);">{{ c.progress }}%</span>
        </div>
        <div class="col-span-6 md:col-span-3 mono text-xs" style="color: var(--text-secondary);">
          {{ c.target }} · {{ c.auditor }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, h } from 'vue'
import api from '../../services/api.js'

const events = ref([])
const summary = ref(null)
const sample = ref(null)
const filter = ref('')
const loadingAudit = ref(true)
const exporting = ref(false)

const filteredEvents = computed(() => {
  const f = filter.value.toLowerCase().trim()
  if (!f) return events.value
  return events.value.filter((e) =>
    (e.action + ' ' + (e.actor || '') + ' ' + (e.target || '')).toLowerCase().includes(f),
  )
})

function formatTime(t) {
  try { return new Date(t).toLocaleString() } catch { return t }
}

function statusClass(s) {
  return {
    compliant: 'sn-pill-success',
    aligned: 'sn-pill-cyan',
    in_audit: 'sn-pill-warn',
    capable: 'sn-pill-cyan',
    self_assessed: 'sn-pill-cyan',
  }[s] || 'sn-pill-cyan'
}

function statusLabel(s) {
  return {
    compliant: 'Compliant',
    aligned: 'Aligned',
    in_audit: 'In audit',
    capable: 'Capable',
    self_assessed: 'Self-assessed',
  }[s] || s
}

async function exportSample() {
  exporting.value = true
  try {
    const { data } = await api.get('/api/enterprise/trust/audit-sample')
    sample.value = data
  } finally {
    exporting.value = false
  }
}

const KV = (props) => h('div', { class: 'kv-row' }, [
  h('span', { class: 'kv-label' }, props.label),
  h('code', { class: 'kv-value mono', class: props.truncate ? 'kv-value mono truncate' : 'kv-value mono break-all' }, props.value),
])
KV.props = ['label', 'value', 'truncate']

onMounted(async () => {
  try {
    const [a, s] = await Promise.all([
      api.get('/api/enterprise/audit'),
      api.get('/api/enterprise/trust/summary'),
    ])
    events.value = a.data.data || []
    summary.value = s.data
  } finally {
    loadingAudit.value = false
  }
})
</script>

<style scoped>
.sn-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; }
.sn-tile { background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px; padding: 18px 18px 16px; }
.mono-label { font-family: 'JetBrains Mono', monospace; font-size: 10px; letter-spacing: 0.16em; text-transform: uppercase; color: var(--text-muted); }
.value { font-family: 'JetBrains Mono', monospace; font-size: 28px; letter-spacing: -0.02em; color: var(--text-primary); margin-top: 6px; }
.mono { font-family: 'JetBrains Mono', monospace; }
.dot { width: 9px; height: 9px; border-radius: 999px; margin-top: 7px; flex-shrink: 0; }
.dot-info { background: var(--accent); }
.dot-warn { background: var(--warn); }
.dot-high { background: var(--danger); }
.sn-input-inline { background: var(--bg-elevated); border: 1px solid var(--border); padding: 6px 12px; border-radius: 8px; color: var(--text-primary); font-size: 12px; outline: none; min-width: 220px; }
.sn-input-inline:focus { border-color: var(--accent); }
.sn-btn-ghost { padding: 6px 14px; border-radius: 999px; border: 1px solid rgba(0,214,201,0.3); color: var(--accent); font-size: 12px; transition: background 0.15s; }
.sn-btn-ghost:hover { background: rgba(0,214,201,0.08); }
.sn-btn-ghost:disabled { opacity: 0.5; cursor: not-allowed; }
.sn-pill { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 999px; font-family: 'JetBrains Mono', monospace; font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; border: 1px solid var(--border); color: var(--text-secondary); }
.sn-pill-success { color: var(--success); border-color: rgba(49,214,123,0.4); background: rgba(49,214,123,0.06); }
.sn-pill-cyan { color: var(--accent); border-color: rgba(0,214,201,0.4); background: rgba(0,214,201,0.06); }
.sn-pill-warn { color: var(--warn); border-color: rgba(245,184,75,0.4); background: rgba(245,184,75,0.06); }
.kv-row { display: flex; gap: 12px; align-items: center; }
.kv-label { font-family: 'JetBrains Mono', monospace; font-size: 10px; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-muted); width: 90px; flex-shrink: 0; }
.kv-value { font-size: 12px; color: var(--text-primary); }
.break-all { word-break: break-all; }
.truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
