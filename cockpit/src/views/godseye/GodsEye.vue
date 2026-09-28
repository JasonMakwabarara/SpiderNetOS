<template>
  <div class="px-6 py-7 max-w-7xl mx-auto" data-testid="gods-eye-page">
    <div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
      <div>
        <div class="sn-eyebrow">Observe · God's Eye</div>
        <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">God's Eye</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">
          Every agent in the business on one screen — the last {{ store.snapshot.window_hours || 24 }} hours, grouped by who they report to.
        </p>
      </div>
      <div class="text-right">
        <button type="button" class="sn-btn" :disabled="store.loading" data-testid="godseye-refresh" @click="load">
          {{ store.loading ? 'Loading…' : 'Refresh' }}
        </button>
        <p v-if="store.lastFetched" class="text-xs mt-2 mono" style="color: var(--text-muted);" data-testid="godseye-updated">
          Updated {{ clockOf(store.lastFetched) }}
        </p>
      </div>
    </div>

    <!-- Off is a state, not an error: say so plainly and stop polling. -->
    <p
      v-if="store.disabled"
      class="sn-card p-4 text-sm"
      style="color: var(--text-secondary);"
      data-testid="godseye-disabled"
    >{{ store.error }}</p>

    <template v-else>
      <p
        v-if="store.error"
        class="text-sm mb-3 rounded-md px-3 py-2"
        style="color: var(--amber); background: rgba(245,165,36,0.08); border: 1px solid rgba(245,165,36,0.25);"
        data-testid="godseye-error"
      >{{ store.error }}</p>

      <!-- A wall of idle columns with the brake on would be a lie by omission. -->
      <div
        v-if="store.breaker.paused"
        class="sn-card p-4 mb-5"
        style="border-color: rgba(239,68,68,0.35); background: rgba(239,68,68,0.06);"
        data-testid="godseye-breaker"
      >
        <p class="text-sm font-semibold" style="color: var(--danger);">Agents are paused</p>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">
          <span v-for="(scope, i) in store.breaker.scopes" :key="i">
            {{ scope.scope }}<span v-if="scope.scope_id"> · {{ scope.scope_id }}</span>
            <span v-if="scope.reason"> — {{ scope.reason }}</span><span v-if="i < store.breaker.scopes.length - 1">; </span>
          </span>
        </p>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6" data-testid="godseye-tiles">
        <div class="sn-card p-4">
          <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Working</p>
          <p class="text-2xl font-bold mt-1" style="color: var(--accent);">
            {{ store.totals.characters_working || 0 }}<span class="text-sm font-normal" style="color: var(--text-muted);"> of 6</span>
          </p>
        </div>
        <div class="sn-card p-4">
          <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">On the wire</p>
          <p class="text-2xl font-bold mt-1" style="color: var(--text-primary);">{{ store.totals.active || 0 }}</p>
        </div>
        <div class="sn-card p-4">
          <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Waiting on you</p>
          <p class="text-2xl font-bold mt-1" :style="{ color: (store.totals.waiting || 0) ? 'var(--amber)' : 'var(--text-primary)' }">{{ store.totals.waiting || 0 }}</p>
        </div>
        <div class="sn-card p-4">
          <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Drafts</p>
          <p class="text-2xl font-bold mt-1" style="color: var(--text-primary);">{{ store.totals.drafts || 0 }}</p>
        </div>
        <div class="sn-card p-4">
          <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Cost</p>
          <p class="text-2xl font-bold mt-1 mono" style="color: var(--text-primary);">${{ Number(store.totals.cost_usd || 0).toFixed(2) }}</p>
        </div>
      </div>

      <p v-if="store.empty" class="sn-card p-5 text-sm mb-6" style="color: var(--text-secondary);" data-testid="godseye-empty">
        Nothing is switched on yet. Enable a skill and it appears here the moment it runs —
        <RouterLink to="/skills" class="underline">open the roster</RouterLink>.
      </p>

      <!-- Six columns, always. An empty column is the finding. -->
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 mb-6" data-testid="godseye-characters">
        <section
          v-for="character in store.characters"
          :key="character.slug"
          class="sn-card p-4"
          :data-testid="`godseye-character-${character.slug}`"
          :style="character.state === 'working' ? 'border-color: var(--accent);' : ''"
        >
          <header class="flex items-center justify-between gap-2 mb-3">
            <div class="flex items-center gap-2">
              <span
                class="inline-block rounded-full"
                style="width: 8px; height: 8px;"
                :style="{ background: store.stateOf(character.state).dot }"
                aria-hidden="true"
              ></span>
              <h2 class="text-base font-semibold" style="color: var(--text-primary);">{{ character.display_name }}</h2>
            </div>
            <span :class="store.stateOf(character.state).pill">{{ store.stateOf(character.state).label }}</span>
          </header>

          <p v-if="!character.skills.length" class="text-sm" style="color: var(--text-muted);">
            Nothing reports to {{ character.display_name }} yet.
          </p>

          <ul v-else class="space-y-2">
            <li
              v-for="skill in character.skills"
              :key="skill.slug"
              class="flex items-center justify-between gap-2 text-sm"
            >
              <RouterLink :to="`/skills/${skill.slug}`" class="truncate" style="color: var(--text-primary);">
                {{ skill.display_name }}
              </RouterLink>
              <span class="flex items-center gap-2 shrink-0">
                <span v-if="skill.active" class="mono text-xs" style="color: var(--accent);">{{ skill.active }} running</span>
                <span v-else-if="skill.waiting" class="mono text-xs" style="color: var(--amber);">{{ skill.waiting }} waiting</span>
                <span v-else-if="skill.failed" class="mono text-xs" style="color: var(--danger);">{{ skill.failed }} failed</span>
                <span v-else-if="!skill.enabled" class="mono text-xs" style="color: var(--text-muted);">off</span>
                <span v-else class="mono text-xs" style="color: var(--text-muted);">{{ skill.runs }} run{{ skill.runs === 1 ? '' : 's' }}</span>
              </span>
            </li>
          </ul>

          <footer
            v-if="character.skills.length"
            class="mt-3 pt-3 text-xs mono flex justify-between"
            style="border-top: 1px solid var(--border); color: var(--text-muted);"
          >
            <span>{{ character.runs }} run{{ character.runs === 1 ? '' : 's' }}</span>
            <span>${{ Number(character.cost_usd || 0).toFixed(3) }}</span>
          </footer>
        </section>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <section class="sn-card p-4" data-testid="godseye-live">
          <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">On the wire now</h2>
          <p v-if="!store.live.length" class="text-sm" style="color: var(--text-muted);">Nothing is running this second.</p>
          <ul v-else class="space-y-2">
            <li v-for="run in store.live" :key="run.id" class="flex items-center justify-between gap-2 text-sm">
              <RouterLink :to="`/agents/runs/${run.id}`" class="truncate" style="color: var(--text-primary);">{{ run.display_name }}</RouterLink>
              <span class="mono text-xs shrink-0" style="color: var(--text-muted);">{{ run.status }} · {{ run.trigger }}</span>
            </li>
          </ul>
        </section>

        <!-- The only actionable half of the wall, kept apart from the rest. -->
        <section class="sn-card p-4" data-testid="godseye-needs-you">
          <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Waiting on you</h2>
          <p v-if="!store.needsYou.length" class="text-sm" style="color: var(--text-muted);">Nothing is blocked on you.</p>
          <ul v-else class="space-y-3">
            <li v-for="run in store.needsYou" :key="run.id" class="text-sm">
              <RouterLink :to="run.path" class="font-medium" style="color: var(--text-primary);">{{ run.display_name }}</RouterLink>
              <p v-if="run.question" class="mt-0.5" style="color: var(--text-secondary);">{{ run.question }}</p>
              <p v-else class="mt-0.5" style="color: var(--text-secondary);">{{ String(run.status).replace(/_/g, ' ') }}</p>
            </li>
          </ul>
        </section>
      </div>
    </template>
  </div>
</template>

<script setup>
import { onMounted, onBeforeUnmount } from 'vue'
import { RouterLink } from 'vue-router'
import { useGodsEyeStore } from '../../stores/godseye.js'

const store = useGodsEyeStore()

function clockOf(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

async function load() {
  await store.fetchSnapshot()
}

onMounted(async () => {
  await load()
  store.startPolling()
})

onBeforeUnmount(() => store.stopPolling())
</script>
