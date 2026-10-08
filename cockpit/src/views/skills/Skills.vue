<template>
  <div class="px-6 py-7 max-w-7xl mx-auto" data-testid="skills-page">
    <div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
      <div>
        <div class="sn-eyebrow">Build · Skills</div>
        <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Skills</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">
          The roster of specialised skills Atlas can run for your business. Nothing provisions until you run or enable it.
        </p>
      </div>
      <div class="flex gap-2">
        <RouterLink to="/brain" class="sn-btn-secondary text-sm" data-testid="skills-link-brain">Business brain</RouterLink>
        <RouterLink to="/agents/runs" class="sn-btn-secondary text-sm" data-testid="skills-link-runs">Agent runs</RouterLink>
      </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6" data-testid="skills-tiles">
      <div v-for="tile in tiles" :key="tile.key" class="sn-card p-4" :data-testid="`skills-tile-${tile.key}`">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">{{ tile.label }}</p>
        <p class="text-2xl font-bold mt-1" :style="{ color: tile.color || 'var(--text-primary)' }">{{ tile.value }}</p>
        <p v-if="tile.hint" class="text-[11px] mt-0.5" style="color: var(--text-muted);">{{ tile.hint }}</p>
      </div>
    </div>

    <div class="flex flex-wrap gap-2 mb-4" role="search" aria-label="Filter skills">
      <select
        :value="filters.pillar"
        class="sn-input"
        style="width: auto;"
        aria-label="Pillar"
        data-testid="skills-filter-pillar"
        @change="setFilter('pillar', $event.target.value)"
      >
        <option value="">All pillars</option>
        <option v-for="p in store.pillarsInOrder" :key="p.key" :value="p.key">{{ p.label }}</option>
      </select>
      <select
        :value="filters.enabled"
        class="sn-input"
        style="width: auto;"
        aria-label="Enabled"
        data-testid="skills-filter-enabled"
        @change="setFilter('enabled', $event.target.value)"
      >
        <option value="">Enabled: any</option>
        <option value="1">Enabled</option>
        <option value="0">Not enabled</option>
      </select>
      <input
        :value="filters.q"
        type="search"
        class="sn-input"
        style="width: 16rem;"
        placeholder="Search skills, identities, tags"
        aria-label="Search skills"
        data-testid="skills-filter-q"
        @change="setFilter('q', $event.target.value.trim())"
        @keyup.enter="setFilter('q', $event.target.value.trim())"
      />
      <button
        v-if="hasFilters"
        type="button"
        class="sn-btn-secondary text-sm"
        data-testid="skills-filter-clear"
        @click="clearFilters"
      >Clear</button>
    </div>

    <p
      v-if="store.error"
      class="text-sm mb-3 rounded-md px-3 py-2"
      style="color: var(--amber); background: rgba(245,165,36,0.08); border: 1px solid rgba(245,165,36,0.25);"
      data-testid="skills-error"
    >{{ store.error }}</p>
    <p v-if="store.loading && !store.catalogue.length" class="text-sm" style="color: var(--text-muted);" data-testid="skills-loading">
      Loading skills…
    </p>

    <section
      v-for="group in store.byPillar"
      :key="group.key"
      class="mb-8"
      :aria-labelledby="`pillar-${group.key}`"
      :data-testid="`skills-pillar-${group.key}`"
    >
      <div class="flex items-baseline justify-between mb-3">
        <h2 :id="`pillar-${group.key}`" class="sn-section-title" style="margin-bottom: 0;">{{ group.label }}</h2>
        <span class="text-xs" style="color: var(--text-muted);">{{ group.skills.length }} skill{{ group.skills.length === 1 ? '' : 's' }}</span>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <SkillMiniCard
          v-for="s in group.skills"
          :key="s.slug"
          :skill="s"
          :busy="enabling === s.slug"
          :notice="notices[s.slug]?.text || ''"
          :notice-error="!!notices[s.slug]?.error"
          @enable="onEnable"
        />
      </div>
    </section>

    <EmptyState
      v-if="!store.loading && !store.byPillar.length"
      title="No skills match"
      description="Try another pillar, or clear the search."
      data-testid="skills-empty"
    >
      <template #actions>
        <button type="button" class="sn-btn" @click="clearFilters">Clear filters</button>
      </template>
    </EmptyState>
  </div>
</template>

<script setup>
/**
 * Skills roster (surface B). Filters live in the route query so a
 * filtered view is linkable; pillar sections follow `meta.pillars.order`.
 */
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSkillsStore } from '../../stores/skills.js'
import SkillMiniCard from '../../components/skills/SkillMiniCard.vue'
import EmptyState from '../../components/data/EmptyState.vue'

const route = useRoute()
const router = useRouter()
const store = useSkillsStore()

const enabling = ref(null)
const notices = reactive({})

const str = (v) => (Array.isArray(v) ? String(v[0] ?? '') : v == null ? '' : String(v))
const filters = computed(() => ({
  pillar: str(route.query.pillar),
  q: str(route.query.q),
  enabled: str(route.query.enabled),
}))
const hasFilters = computed(() => !!(filters.value.pillar || filters.value.q || filters.value.enabled))

const tiles = computed(() => {
  const s = store.stats
  return [
    { key: 'total',      label: 'Skills',      value: s.total },
    { key: 'enabled',    label: 'Enabled',     value: s.enabled, color: 'var(--accent)' },
    { key: 'brain',      label: 'Brain-ready', value: s.brainReady, color: s.brainReady === s.total ? 'var(--success)' : 'var(--amber)', hint: `${Math.max(0, s.total - s.brainReady)} still reading blanks` },
    { key: 'autonomous', label: 'Autonomous',  value: s.autonomous, color: 'var(--success)' },
  ]
})

function setFilter(key, value) {
  const query = { ...route.query }
  if (value) query[key] = value
  else delete query[key]
  router.replace({ query })
}

function clearFilters() {
  router.replace({ query: {} })
}

async function onEnable(skill) {
  enabling.value = skill.slug
  delete notices[skill.slug]
  const res = await store.enable(skill.slug)
  enabling.value = null
  if (res.success) {
    notices[skill.slug] = { text: 'Enabled — open the card to run it.' }
  } else if (res.status === 402) {
    notices[skill.slug] = { text: res.checkout_hint || res.error, error: true }
  } else {
    notices[skill.slug] = { text: res.error, error: true }
  }
}

watch(
  () => route.query,
  () => { store.fetchCatalogue(filters.value) },
  { immediate: true },
)
</script>
