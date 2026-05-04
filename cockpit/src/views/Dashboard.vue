<template>
  <div class="px-8 py-6 space-y-6 max-w-[1400px] mx-auto">
    <!-- Header -->
    <header class="flex items-start justify-between gap-6">
      <div>
        <h1 class="text-[26px] font-heading font-semibold tracking-tight" style="color: var(--text-primary);">
          Dashboard
        </h1>
        <p class="text-sm mt-1" style="color: var(--text-muted);">
          Welcome back, <span style="color: var(--text-secondary);">{{ authStore.user?.name || 'Operator' }}</span> ·
          the last 24 hours of <span style="color: var(--accent);">{{ authStore.tenant?.name || 'your workspace' }}</span>.
        </p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <button class="sn-btn" data-testid="dashboard-run-atlas" @click="router.push('/atlas')">
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5M4 19l2-2h13a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v13z"/>
          </svg>
          Open Atlas
        </button>
        <button class="sn-btn-primary" data-testid="dashboard-new-flow" @click="router.push('/flows/new')">
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" d="M12 5v14M5 12h14"/>
          </svg>
          New flow
        </button>
      </div>
    </header>

    <!-- Status strip -->
    <section class="grid grid-cols-2 md:grid-cols-4 gap-3" data-testid="dashboard-stats">
      <div class="sn-card p-4">
        <div class="flex items-start justify-between">
          <div>
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Automation</div>
            <div class="mt-1 text-xl font-heading font-semibold capitalize" style="color: var(--text-primary);">{{ authStore.automationLevel }}</div>
          </div>
          <span class="sn-pill sn-pill-accent">{{ authStore.automationLevel === 'autonomous' ? 'Autopilot' : 'Guarded' }}</span>
        </div>
        <div class="mt-3 text-xs" style="color: var(--text-muted);">
          <RouterLink to="/settings/automation-level" class="hover:underline" style="color: var(--accent);">Change mode →</RouterLink>
        </div>
      </div>

      <div class="sn-card p-4">
        <div class="flex items-start justify-between">
          <div>
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Pending approvals</div>
            <div class="mt-1 text-xl font-heading font-semibold" style="color: var(--text-primary);">{{ pendingApprovals }}</div>
          </div>
          <span v-if="pendingApprovals > 0" class="sn-pill sn-pill-warn">Action</span>
          <span v-else class="sn-pill sn-pill-success">Clear</span>
        </div>
        <div class="mt-3 text-xs">
          <RouterLink to="/approvals" class="hover:underline" style="color: var(--accent);">Review queue →</RouterLink>
        </div>
      </div>

      <div class="sn-card p-4">
        <div class="flex items-start justify-between">
          <div>
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Active agents</div>
            <div class="mt-1 text-xl font-heading font-semibold" style="color: var(--text-primary);">{{ activeAgentsCount }}</div>
          </div>
          <span class="sn-pill">{{ allAgentsCount }} total</span>
        </div>
        <div class="mt-3 text-xs">
          <RouterLink to="/agents" class="hover:underline" style="color: var(--accent);">Manage agents →</RouterLink>
        </div>
      </div>

      <div class="sn-card p-4">
        <div class="flex items-start justify-between">
          <div>
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Spend today</div>
            <div class="mt-1 text-xl font-heading font-semibold mono" :style="costColorStyle">
              ${{ (currentSpendDaily).toFixed(2) }}
            </div>
          </div>
          <span class="sn-pill" :class="budgetPillClass">{{ dailyPct }}%</span>
        </div>
        <div class="mt-3 h-1.5 rounded-full overflow-hidden" style="background: var(--bg-elevated);">
          <div class="h-full rounded-full" :style="`width:${dailyPct}%; background: ${dailyPct > 80 ? 'var(--warn)' : 'var(--accent)'};`"></div>
        </div>
      </div>
    </section>

    <!-- Main grid -->
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-5">
      <!-- Ops feed -->
      <div class="lg:col-span-2 sn-card p-0 overflow-hidden">
        <header class="px-4 py-3 border-b flex items-center justify-between" style="border-color: var(--border);">
          <div>
            <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Live Ops Feed</div>
            <h3 class="font-heading text-[15px] font-semibold mt-0.5" style="color: var(--text-primary);">Recent decisions &amp; traces</h3>
          </div>
          <RouterLink to="/traces" class="text-xs hover:underline" style="color: var(--accent);">All traces →</RouterLink>
        </header>
        <ul class="divide-y" style="border-color: var(--border);">
          <li v-if="!recentTraces.length" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
            No recent activity — Atlas is idle.
          </li>
          <li
            v-for="t in recentTraces.slice(0, 8)" :key="t.id"
            class="px-4 py-3 flex items-center gap-3 hover:bg-ink-700 cursor-pointer"
            style="border-color: var(--divider);"
            @click="router.push('/traces')"
          >
            <span class="w-1.5 h-1.5 rounded-full shrink-0" :style="traceDot(t.status)"></span>
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-2 text-sm">
                <span class="mono text-[12px]" style="color: var(--text-muted);">{{ t.id }}</span>
                <span class="sn-pill" :class="traceKindPill(t.kind)">{{ t.kind }}</span>
                <span class="truncate" style="color: var(--text-primary);">{{ t.subject }}</span>
              </div>
              <div class="text-[11px] mt-0.5" style="color: var(--text-muted);">
                {{ t.actor }} · {{ timeAgo(t.created_at) }} · {{ t.duration_ms }}ms · ${{ (t.cost_usd || 0).toFixed(4) }}
              </div>
            </div>
          </li>
        </ul>
      </div>

      <!-- Side column -->
      <div class="space-y-5">
        <!-- Budget snapshot -->
        <div class="sn-card p-4">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">This month</div>
              <h3 class="font-heading text-[15px] font-semibold mt-0.5" style="color: var(--text-primary);">Budget</h3>
            </div>
            <RouterLink to="/usage" class="text-xs hover:underline" style="color: var(--accent);">Details →</RouterLink>
          </div>
          <div class="mt-3">
            <div class="flex items-end justify-between">
              <span class="text-2xl font-heading font-semibold mono" style="color: var(--text-primary);">${{ currentSpendMonthly.toFixed(0) }}</span>
              <span class="text-xs mono" style="color: var(--text-muted);">/ ${{ monthlyCap.toFixed(0) }}</span>
            </div>
            <div class="mt-2 h-1.5 rounded-full overflow-hidden" style="background: var(--bg-elevated);">
              <div class="h-full rounded-full" :style="`width:${monthlyPct}%; background: ${monthlyPct > 80 ? 'var(--warn)' : 'var(--accent)'};`"></div>
            </div>
            <div class="mt-2 text-xs" style="color: var(--text-muted);">
              Projected month-end: ${{ projected.toFixed(0) }}
            </div>
          </div>
        </div>

        <!-- Hannah guidance -->
        <div class="sn-card p-4">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Suggested next actions</div>
              <h3 class="font-heading text-[15px] font-semibold mt-0.5" style="color: var(--text-primary);">Hannah says…</h3>
            </div>
          </div>
          <ul class="mt-3 space-y-2 text-sm">
            <li v-for="(s, i) in suggestions" :key="i"
                class="flex items-start justify-between gap-2 rounded-md px-2.5 py-2 transition-colors"
                :style="`background: var(--bg-elevated); border: 1px solid var(--border);`">
              <span style="color: var(--text-primary);">{{ s.text }}</span>
              <button class="sn-btn py-0.5 px-2 text-[11px]" :data-testid="`hannah-suggestion-${i}`" @click="runSuggestion(s)">Run</button>
            </li>
          </ul>
        </div>

        <!-- Quick links -->
        <div class="sn-card p-4">
          <div class="text-[10px] tracking-widest uppercase font-semibold mb-2" style="color: var(--text-muted);">Quick jumps</div>
          <div class="grid grid-cols-2 gap-2">
            <RouterLink to="/flows" class="sn-btn justify-start text-xs" data-testid="qj-flows">Flows</RouterLink>
            <RouterLink to="/agents" class="sn-btn justify-start text-xs" data-testid="qj-agents">Agents</RouterLink>
            <RouterLink to="/memory" class="sn-btn justify-start text-xs" data-testid="qj-memory">Memory</RouterLink>
            <RouterLink to="/intelligence" class="sn-btn justify-start text-xs" data-testid="qj-intel">Intelligence</RouterLink>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth.js'
import { useAgentsStore } from '../stores/agents.js'
import { useFlowsStore } from '../stores/flows.js'
import { useUsageStore } from '../stores/usage.js'
import { useTracesStore } from '../stores/traces.js'
import { useApprovalsStore } from '../stores/approvals.js'
import { useAtlasStore } from '../stores/atlas.js'

const router = useRouter()
const authStore = useAuthStore()
const agentsStore = useAgentsStore()
const flowsStore = useFlowsStore()
const usageStore = useUsageStore()
const tracesStore = useTracesStore()
const approvalsStore = useApprovalsStore()
const atlasStore = useAtlasStore()

const suggestions = ref([
  { text: 'Publish the Invoice Anomaly Sweep flow.',  command: '/flows publish inv-sweep' },
  { text: "Review this week's budget anomalies.",       command: '/usage anomalies' },
  { text: 'Promote Lead Qualifier to autonomous mode.', command: '/agents promote ag_1' },
])

const currentSpendDaily   = computed(() => usageStore.currentSpend?.daily   ?? 0)
const currentSpendMonthly = computed(() => usageStore.currentSpend?.monthly ?? 0)
const monthlyCap   = computed(() => usageStore.budget?.monthly_limit ?? 1800)
const dailyCap     = computed(() => usageStore.budget?.daily_limit   ?? 95)
const dailyPct     = computed(() => Math.min(100, Math.round((currentSpendDaily.value / dailyCap.value) * 100)))
const monthlyPct   = computed(() => Math.min(100, Math.round((currentSpendMonthly.value / monthlyCap.value) * 100)))
const projected    = computed(() => currentSpendMonthly.value * (30 / Math.max(1, new Date().getDate())))

const costColorStyle = computed(() =>
  usageStore.isDegraded ? 'color: var(--warn);' :
  usageStore.isNearLimit ? 'color: var(--amber);' :
  'color: var(--text-primary);'
)
const budgetPillClass = computed(() =>
  dailyPct.value > 80 ? 'sn-pill-warn' : dailyPct.value > 50 ? 'sn-pill' : 'sn-pill-success'
)

const activeAgentsCount = computed(() => (agentsStore.agents || []).filter((a) => a.status === 'active').length)
const allAgentsCount    = computed(() => (agentsStore.agents || []).length)
const pendingApprovals  = computed(() => (approvalsStore.approvals || []).filter((a) => a.status === 'pending').length)
const recentTraces      = computed(() => (tracesStore.traces || []))

function traceDot(status) {
  if (status === 'ok') return 'background: var(--success); box-shadow: 0 0 6px rgba(34,211,155,0.6);'
  if (status === 'warn') return 'background: var(--warn); box-shadow: 0 0 6px rgba(245,165,36,0.6);'
  if (status === 'error') return 'background: var(--danger); box-shadow: 0 0 6px rgba(255,90,122,0.6);'
  return 'background: var(--text-muted);'
}
function traceKindPill(kind) {
  if (kind === 'approval.request') return 'sn-pill-warn'
  if (kind === 'budget.alert') return 'sn-pill-warn'
  if (kind === 'stepup.challenge') return 'sn-pill-accent'
  return 'sn-pill'
}

function timeAgo(ts) {
  if (!ts) return ''
  const d = new Date(ts)
  const s = Math.max(1, Math.floor((Date.now() - d.getTime()) / 1000))
  if (s < 60) return `${s}s ago`
  if (s < 3600) return `${Math.floor(s / 60)}m ago`
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`
  return `${Math.floor(s / 86400)}d ago`
}

function runSuggestion(s) {
  atlasStore.sendMessage(s.command).catch(() => {})
  router.push('/atlas')
}

onMounted(() => {
  agentsStore.fetchAgents?.()
  flowsStore.fetchFlows?.()
  usageStore.fetchBudget()
  usageStore.fetchCurrentSpend()
  tracesStore.fetchTraces?.()
  approvalsStore.fetchApprovals?.()
})
</script>
