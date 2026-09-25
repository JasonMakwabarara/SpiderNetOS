<template>
  <div class="px-6 py-7 max-w-[1440px] mx-auto" data-testid="business-map-page">
    <!-- Header -->
    <div class="flex items-start justify-between gap-4 flex-wrap mb-5">
      <div class="min-w-0">
        <div class="sn-eyebrow">Operate · Business map</div>
        <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);" data-testid="map-title">
          {{ store.core?.name ? `${store.core.name}, mapped` : 'Business map' }}
        </h1>
        <p class="text-sm mt-1 max-w-2xl" style="color: var(--text-secondary);">
          The brain sits at the core. Every pillar of the business rings it, and every node shows whether it runs on its own,
          waits on a person, or is not set up yet.
        </p>
      </div>
      <RouterLink
        to="/operate/systems"
        class="sn-btn-secondary text-xs shrink-0"
        style="padding: 0.45rem 0.8rem;"
        data-testid="map-list-view"
      >List view</RouterLink>
    </div>

    <!-- Toolbar -->
    <div class="sn-card p-3 mb-4 flex items-center gap-3 flex-wrap" data-testid="map-toolbar">
      <label class="relative flex-1 min-w-[200px] max-w-sm">
        <span class="sr-only">Search the map</span>
        <input
          :value="store.query"
          type="search"
          class="w-full text-sm rounded-lg px-3 py-1.5 border"
          style="background: var(--surface-low, var(--bg-elevated)); border-color: var(--border); color: var(--text-primary);"
          placeholder="Search nodes and skills…"
          data-testid="map-search"
          @input="store.setQuery($event.target.value)"
          @keydown.enter.prevent="openFirstMatch"
        />
      </label>

      <div class="flex items-center gap-1.5 flex-wrap" role="group" aria-label="Filter the map">
        <button
          v-for="f in MAP_FILTERS"
          :key="f.key"
          type="button"
          class="sn-chip"
          :aria-pressed="store.filter === f.key ? 'true' : 'false'"
          :data-testid="`map-filter-${f.key}`"
          @click="store.setFilter(f.key)"
        >
          {{ f.label }}
          <span class="mono ml-1" style="color: var(--text-muted);">{{ store.counts.filters[f.key] ?? 0 }}</span>
        </button>
      </div>

      <div class="flex items-center gap-1 ml-auto" role="group" aria-label="Zoom">
        <button
          type="button"
          class="sn-btn-secondary text-xs"
          style="padding: 0.3rem 0.6rem;"
          aria-label="Zoom out"
          data-testid="map-zoom-out"
          @click="mapRef?.zoomOut()"
        >−</button>
        <span class="text-[11px] mono w-11 text-center" style="color: var(--text-muted);" data-testid="map-zoom-level">
          {{ zoomPct }}%
        </span>
        <button
          type="button"
          class="sn-btn-secondary text-xs"
          style="padding: 0.3rem 0.6rem;"
          aria-label="Zoom in"
          data-testid="map-zoom-in"
          @click="mapRef?.zoomIn()"
        >+</button>
        <button
          type="button"
          class="sn-btn-secondary text-xs"
          style="padding: 0.3rem 0.6rem;"
          data-testid="map-zoom-fit"
          @click="mapRef?.fit()"
        >Fit</button>
      </div>
    </div>

    <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
      <MapLegend :counts="store.counts" />
      <p class="text-[11px]" style="color: var(--text-muted);" aria-live="polite" data-testid="map-live">{{ liveText }}</p>
    </div>

    <p
      v-if="store.error"
      class="text-xs mb-3 rounded-lg px-3 py-2"
      style="color: var(--amber, var(--warn)); background: rgba(245,165,36,0.08); border: 1px solid rgba(245,165,36,0.25);"
      role="status"
      data-testid="map-error"
    >{{ store.error }}</p>

    <!-- Map + drawer -->
    <div class="flex items-stretch gap-4">
      <div
        class="sn-card relative flex-1 min-w-0 overflow-hidden"
        style="height: min(74vh, 820px); min-height: 480px;"
        data-testid="map-canvas"
      >
        <p
          v-if="store.loading && !store.pillars.length"
          class="absolute inset-0 flex items-center justify-center text-sm"
          style="color: var(--text-muted);"
          data-testid="map-loading"
        >Drawing your map…</p>

        <div
          v-else-if="!store.pillars.length"
          class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-center px-6"
          data-testid="map-empty"
        >
          <p class="text-sm" style="color: var(--text-secondary);">Nothing on the map yet. Tell Atlas about the business and the pillars fill in.</p>
          <RouterLink :to="{ path: '/atlas', query: { mode: 'launch' } }" class="sn-btn text-xs" style="padding: 0.45rem 0.9rem;">
            Start with Atlas
          </RouterLink>
        </div>

        <RadialMap
          v-else
          ref="mapRef"
          :core="store.core"
          :pillars="store.orderedPillars"
          :visible-ids="visibleIds"
          :selected-id="store.selectedNodeId"
          @select="openNode"
          @close="closeNode"
        />
      </div>

      <MapNodeDrawer
        :node="store.selectedNode"
        :open="!!store.selectedNodeId"
        :loading="store.detailLoading"
        :error="store.detailError || ''"
        @close="closeNode"
      />
    </div>
  </div>
</template>

<script setup>
/**
 * BusinessMap — `/map` (plan D6-C). The radial map of the business with
 * the three brains at the core, filter chips, search, zoom, a legend and
 * the node drawer. `?node=<id>` is the source of truth for the open node,
 * so a node can be linked to and closing the drawer clears it.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import RadialMap from '../../components/map/RadialMap.vue'
import MapNodeDrawer from '../../components/map/MapNodeDrawer.vue'
import MapLegend from '../../components/map/MapLegend.vue'
import { useMapStore, MAP_FILTERS } from '../../stores/map.js'
import { useSkillsStore } from '../../stores/skills.js'

const route = useRoute()
const router = useRouter()
const store = useMapStore()
const skills = useSkillsStore()

const mapRef = ref(null)

const visibleIds = computed(() => {
  const unfiltered = store.filter === 'all' && !String(store.query || '').trim()
  return unfiltered ? null : [...store.visibleNodeIds]
})

const zoomPct = computed(() => Math.round((mapRef.value?.zoom ?? 1) * 100))

const liveText = computed(() => {
  const total = store.counts.total
  if (!total) return ''
  const shown = store.visibleNodeIds.size
  return shown === total ? `Showing all ${total} nodes.` : `Showing ${shown} of ${total} nodes.`
})

function queryWith(patch) {
  const next = { ...route.query, ...patch }
  Object.keys(next).forEach((k) => {
    if (next[k] == null || next[k] === '') delete next[k]
  })
  return next
}

function openNode(id) {
  if (id == null) return
  if (String(route.query.node || '') === String(id)) {
    store.select(id)
    return
  }
  router.replace({ query: queryWith({ node: String(id) }) })
}

function closeNode() {
  if (!route.query.node) {
    store.clearSelection()
    return
  }
  router.replace({ query: queryWith({ node: null }) })
}

function openFirstMatch() {
  const first = store.filteredPillars.flatMap((p) => p.nodes)[0]
  if (first) openNode(first.id)
}

// ?node= → store (deep links, Back/Forward, and our own replace()).
watch(
  () => route.query.node,
  (node) => {
    const id = Array.isArray(node) ? node[0] : node
    if (!id) {
      store.clearSelection()
      return
    }
    if (String(store.selectedNodeId) !== String(id) || !store.nodeDetails[String(id)]) store.select(String(id))
    if (!skills.catalogue.length && !skills.loading) skills.fetchCatalogue()
  },
  { immediate: true },
)

onMounted(() => {
  store.fetchMap()
})
</script>
