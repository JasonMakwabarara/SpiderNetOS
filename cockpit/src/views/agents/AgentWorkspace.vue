<template>
  <div class="px-6 py-7 max-w-6xl mx-auto" data-testid="agent-workspace-page">
    <div class="mb-6 flex items-start justify-between gap-4">
      <div class="flex items-start gap-4 min-w-0">
        <div
          class="size-12 rounded-xl flex items-center justify-center text-lg font-bold shrink-0"
          style="background: var(--accent-weak); color: var(--accent); border: 1px solid rgba(0,214,201,0.30);"
          :title="`${core.name} — ${core.role}`"
          data-testid="workspace-core-badge"
          :data-core="ws?.agent?.core_agent || ''"
          aria-hidden="true"
        >{{ core.glyph }}</div>
        <div class="min-w-0">
          <div class="sn-eyebrow">Build · Agents · Workspace</div>
          <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);" data-testid="agent-workspace-title">
            {{ ws?.agent?.name || title }} workspace
          </h1>
          <p class="text-sm mt-1 flex items-center gap-2 flex-wrap" style="color: var(--text-secondary);" data-testid="workspace-core-line">
            <span>{{ core.name }}<span v-if="core.role"> — {{ core.role }}</span></span>
            <span v-if="ws" class="sn-pill" :class="statusPill(ws.status)" data-testid="workspace-status">{{ ws.status }}</span>
          </p>
        </div>
      </div>
      <RouterLink to="/agents/runs" class="sn-btn-secondary text-sm shrink-0">All runs</RouterLink>
    </div>

    <p
      v-if="store.error"
      class="text-sm mb-4 rounded-md px-3 py-2"
      style="color: var(--amber); background: rgba(245,165,36,0.08); border: 1px solid rgba(245,165,36,0.25);"
      data-testid="workspace-error"
    >{{ store.error }}</p>
    <p v-if="store.loading && !ws" class="text-sm" style="color: var(--text-muted);">Loading workspace…</p>

    <div v-if="ws" class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px] gap-4 items-start">
      <div class="space-y-4 min-w-0">
        <section class="sn-card p-5" aria-labelledby="ws-scratch-title" data-testid="workspace-scratch">
          <h2 id="ws-scratch-title" class="sn-section-title">Scratch</h2>
          <div v-if="ws.scratch" class="sn-prose text-sm" v-html="scratchHtml"></div>
          <p v-else class="text-xs" style="color: var(--text-muted);">Nothing on the scratchpad.</p>
        </section>

        <section class="sn-card p-5" aria-labelledby="ws-runs-title" data-testid="workspace-runs">
          <h2 id="ws-runs-title" class="sn-section-title">Recent runs</h2>
          <ul v-if="ws.recent_runs?.length" class="divide-y" style="border-color: var(--divider);">
            <li v-for="r in ws.recent_runs" :key="r.id" class="py-2 flex items-center gap-3" :data-testid="`workspace-run-${r.id}`">
              <span class="w-1.5 h-1.5 rounded-full shrink-0" :style="runStatusDot(r.status)" aria-hidden="true"></span>
              <div class="min-w-0 flex-1">
                <RouterLink :to="`/agents/runs/${r.id}`" class="mono text-xs hover:underline" style="color: var(--text-primary);">{{ r.id }}</RouterLink>
                <span class="text-sm ml-2" style="color: var(--text-secondary);">{{ r.skill_name || r.skill_slug }}</span>
              </div>
              <span class="sn-pill text-[10px]" :class="runStatusPill(r.status)">{{ String(r.status || '').replace(/_/g, ' ') }}</span>
              <span class="text-[11px] mono" style="color: var(--text-muted);">{{ timeAgo(r.started_at) }}<span v-if="r.cost_usd != null"> · ${{ Number(r.cost_usd).toFixed(2) }}</span></span>
            </li>
          </ul>
          <p v-else class="text-xs" style="color: var(--text-muted);">No runs yet.</p>
        </section>
      </div>

      <aside class="space-y-4">
        <section class="sn-card p-4" aria-labelledby="ws-budget-title" data-testid="workspace-budget">
          <h2 id="ws-budget-title" class="sn-section-title">Budget</h2>
          <BudgetBar label="Today" :budget="ws.budget_daily_usd" :actual="ws.spent_today_usd" />
        </section>

        <section class="sn-card p-4" aria-labelledby="ws-drafts-title" data-testid="workspace-drafts">
          <h2 id="ws-drafts-title" class="sn-section-title">Drafts</h2>
          <ul v-if="ws.drafts?.length" class="space-y-2">
            <li v-for="d in ws.drafts" :key="d.id" class="text-xs" :data-testid="`workspace-draft-${d.id}`">
              <p class="mono truncate" style="color: var(--text-primary);">{{ d.title || d.id }}</p>
              <p class="mt-0.5 flex items-center gap-1.5 flex-wrap" style="color: var(--text-muted);">
                <span class="sn-pill text-[10px]">{{ d.kind }}</span>
                <span class="sn-pill text-[10px]" :class="d.status === 'pending_approval' ? 'sn-pill-warn' : ''">{{ String(d.status || '').replace(/_/g, ' ') }}</span>
                <RouterLink v-if="d.approval_id" :to="{ path: '/approvals', query: { id: d.approval_id } }" class="underline" style="color: var(--accent);">approval</RouterLink>
              </p>
            </li>
          </ul>
          <p v-else class="text-xs" style="color: var(--text-muted);">No drafts waiting.</p>
        </section>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useRunsStore } from '../../stores/runs.js'
import BudgetBar from '../../components/financial/BudgetBar.vue'
import { renderMarkdown } from '../../utils/markdown.js'
import { timeAgo, runStatusPill, runStatusDot, coreAgent, titleCase } from '../../utils/format.js'

const route = useRoute()
const store = useRunsStore()

const slug = computed(() => String(route.params.slug || ''))
const ws = computed(() => store.workspaces[slug.value] || null)
const title = computed(() => titleCase(slug.value) || 'Agent')
const core = computed(() => coreAgent(ws.value?.agent?.core_agent))
const scratchHtml = computed(() => renderMarkdown(ws.value?.scratch || ''))

function statusPill(s) {
  if (s === 'running' || s === 'active') return 'sn-pill-accent'
  if (s === 'paused' || s === 'demoted') return 'sn-pill-warn'
  if (s === 'error') return 'sn-pill-danger'
  return ''
}

watch(slug, (s) => { if (s) store.fetchWorkspace(s) }, { immediate: true })
</script>

<style scoped>
.sn-prose :deep(h1) { font-size: 1.1rem; font-weight: 600; margin: 0 0 0.5rem; color: var(--text-primary); }
.sn-prose :deep(h2) { font-size: 0.95rem; font-weight: 600; margin: 0.9rem 0 0.35rem; color: var(--text-primary); }
.sn-prose :deep(h3) { font-size: 0.85rem; font-weight: 600; margin: 0.7rem 0 0.3rem; color: var(--text-primary); text-transform: uppercase; letter-spacing: 0.04em; }
.sn-prose :deep(p)  { margin: 0 0 0.5rem; color: var(--text-secondary); line-height: 1.55; white-space: pre-line; }
.sn-prose :deep(ul), .sn-prose :deep(ol) { margin: 0 0 0.5rem 1.2rem; color: var(--text-secondary); }
.sn-prose :deep(ul) { list-style: disc; }
.sn-prose :deep(ol) { list-style: decimal; }
.sn-prose :deep(strong) { color: var(--text-primary); font-weight: 600; }
.sn-prose :deep(code) { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 0.8em; padding: 1px 5px; border-radius: 4px; background: var(--bg-elevated); border: 1px solid var(--border); }
</style>
