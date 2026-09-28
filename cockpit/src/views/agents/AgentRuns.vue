<template>
  <div class="px-6 py-7 max-w-7xl mx-auto" data-testid="agent-runs-page">
    <div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
      <div>
        <div class="sn-eyebrow">Observe · Agent runs</div>
        <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Agent runs</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">
          Every live and finished run across your agent workspaces — with the question it is waiting on, if any.
        </p>
      </div>
      <button type="button" class="sn-btn" :disabled="store.loading" data-testid="runs-refresh" @click="load">
        {{ store.loading ? 'Loading…' : 'Refresh' }}
      </button>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6" data-testid="runs-tiles">
      <div class="sn-card p-4" data-testid="runs-tile-total">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Runs</p>
        <p class="text-2xl font-bold mt-1" style="color: var(--text-primary);">{{ store.runs.length }}</p>
      </div>
      <div class="sn-card p-4" data-testid="runs-tile-active">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Active</p>
        <p class="text-2xl font-bold mt-1" style="color: var(--accent);">{{ store.activeCount }}</p>
      </div>
      <div class="sn-card p-4" data-testid="runs-tile-blocked">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Blocked on you</p>
        <p class="text-2xl font-bold mt-1" :style="{ color: store.blockedCount ? 'var(--danger)' : 'var(--text-primary)' }">{{ store.blockedCount }}</p>
      </div>
      <div class="sn-card p-4" data-testid="runs-tile-cost">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Cost</p>
        <p class="text-2xl font-bold mt-1 mono" style="color: var(--text-primary);">${{ store.totalCost.toFixed(2) }}</p>
      </div>
    </div>

    <div class="flex flex-wrap gap-2 mb-4" role="search" aria-label="Filter runs">
      <select :value="filters.status" class="sn-input" style="width: auto;" aria-label="Status" data-testid="runs-filter-status" @change="setFilter('status', $event.target.value)">
        <option value="">All statuses</option>
        <option v-for="s in store.meta.statuses || []" :key="s" :value="s">{{ s.replace(/_/g, ' ') }}</option>
      </select>
      <select :value="filters.skill" class="sn-input" style="width: auto;" aria-label="Skill" data-testid="runs-filter-skill" @change="setFilter('skill', $event.target.value)">
        <option value="">All skills</option>
        <option v-for="s in store.skillsSeen" :key="s.slug" :value="s.slug">{{ s.name }}</option>
      </select>
      <button v-if="filters.status || filters.skill" type="button" class="sn-btn-secondary text-sm" data-testid="runs-filter-clear" @click="router.replace({ query: {} })">Clear</button>
    </div>

    <p
      v-if="store.error"
      class="text-sm mb-3 rounded-md px-3 py-2"
      style="color: var(--amber); background: rgba(245,165,36,0.08); border: 1px solid rgba(245,165,36,0.25);"
      data-testid="runs-error"
    >{{ store.error }}</p>

    <div class="sn-card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wider" style="color: var(--text-muted);">
            <th class="p-3">Run</th>
            <th class="p-3">Skill</th>
            <th class="p-3">Agent</th>
            <th class="p-3">Status</th>
            <th class="p-3">Trigger</th>
            <th class="p-3">Cost</th>
            <th class="p-3">Started</th>
            <th class="p-3">Took</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="store.loading && !store.runs.length"><td colspan="8" class="p-4" style="color: var(--text-muted);">Loading…</td></tr>
          <tr v-else-if="!store.filtered.length" data-testid="runs-empty"><td colspan="8" class="p-4" style="color: var(--text-muted);">No runs match. Run a skill from its card to see it here.</td></tr>
          <tr v-for="r in store.filtered" :key="r.id" style="border-top: 1px solid var(--border);" :data-testid="`run-row-${r.id}`">
            <td class="p-3">
              <RouterLink :to="`/agents/runs/${r.id}`" class="mono text-xs hover:underline" style="color: var(--text-primary);" :data-testid="`run-link-${r.id}`">{{ r.id }}</RouterLink>
              <p v-if="r.error" class="text-[11px] mt-0.5 truncate max-w-[240px]" style="color: var(--danger);">{{ r.error }}</p>
            </td>
            <td class="p-3">
              <RouterLink v-if="r.skill_slug" :to="`/skills/${r.skill_slug}`" class="hover:underline" style="color: var(--text-primary);">{{ r.skill_name || r.skill_slug }}</RouterLink>
              <span v-else>—</span>
            </td>
            <td class="p-3" style="color: var(--text-secondary);">
              <RouterLink v-if="r.agent?.slug" :to="`/agents/${r.agent.slug}/workspace`" class="hover:underline">{{ r.agent.name || r.agent.slug }}</RouterLink>
              <span v-if="r.agent?.core_agent" class="text-[11px]" style="color: var(--text-muted);"> · {{ coreAgent(r.agent.core_agent).name }}</span>
            </td>
            <td class="p-3"><span class="sn-pill" :class="runStatusPill(r.status)" :data-testid="`run-status-${r.id}`">{{ String(r.status || '').replace(/_/g, ' ') }}</span></td>
            <td class="p-3 text-xs" style="color: var(--text-secondary);">{{ r.trigger_type || '—' }}</td>
            <td class="p-3 mono text-xs" style="color: var(--text-secondary);">${{ Number(r.cost_usd || 0).toFixed(2) }}</td>
            <td class="p-3 text-xs" style="color: var(--text-muted);" :title="fmtDate(r.started_at)">{{ timeAgo(r.started_at) }}</td>
            <td class="p-3 text-xs mono" style="color: var(--text-muted);">{{ r.started_at ? fmtDuration(durationBetween(r.started_at, r.finished_at)) : '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useRunsStore } from '../../stores/runs.js'
import { timeAgo, fmtDate, fmtDuration, durationBetween, runStatusPill, coreAgent } from '../../utils/format.js'

const route = useRoute()
const router = useRouter()
const store = useRunsStore()

const str = (v) => (Array.isArray(v) ? String(v[0] ?? '') : v == null ? '' : String(v))
const filters = computed(() => ({ status: str(route.query.status), skill: str(route.query.skill) }))

function setFilter(key, value) {
  const query = { ...route.query }
  if (value) query[key] = value
  else delete query[key]
  router.replace({ query })
}

function load() {
  return store.fetchRuns(filters.value)
}

watch(() => route.query, load, { immediate: true })
</script>
