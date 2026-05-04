<template>
  <Teleport to="body">
    <Transition name="cmdbar">
      <div
        v-if="visible"
        class="fixed inset-0 z-[9999] flex items-start justify-center pt-[14vh] px-4"
        style="background: rgba(5,7,10,0.55); backdrop-filter: blur(8px);"
        data-testid="command-palette"
        @click.self="close"
      >
        <div
          ref="panelRef"
          class="w-full max-w-xl sn-panel overflow-hidden"
          style="border-color: var(--border-active); box-shadow: 0 24px 60px rgba(0,0,0,0.55), 0 0 0 1px rgba(0,229,200,0.18);"
          role="dialog"
          aria-modal="true"
          aria-label="Command palette"
          @keydown.escape.stop="close"
        >
          <!-- Input row -->
          <div class="flex items-center gap-3 px-4 py-3 border-b" style="border-color: var(--border);">
            <svg class="w-4 h-4 shrink-0" style="color: var(--accent);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="M21 21l-4.3-4.3"/>
            </svg>
            <input
              ref="inputRef"
              v-model="query"
              type="text"
              placeholder="Jump to a route, run a command, or search…"
              class="flex-1 bg-transparent border-0 px-0 py-0 text-[15px] outline-none focus:outline-none focus:ring-0 focus:border-0"
              style="color: var(--text-primary); box-shadow: none;"
              autocomplete="off"
              spellcheck="false"
              data-testid="command-palette-input"
              @input="selectedIndex = 0"
              @keydown.enter.prevent="executeSelected"
              @keydown.arrow-down.prevent="moveSelection(1)"
              @keydown.arrow-up.prevent="moveSelection(-1)"
            />
            <span class="sn-kbd shrink-0">esc</span>
          </div>

          <!-- Results -->
          <div class="max-h-[420px] overflow-y-auto" data-testid="command-palette-results">
            <template v-for="(group, gi) in groupedResults" :key="group.label">
              <div
                v-if="group.items.length"
                class="sn-nav-group-label"
                style="padding: 0.6rem 0.9rem 0.25rem;"
              >{{ group.label }} <span class="opacity-60">· {{ group.items.length }}</span></div>
              <button
                v-for="(item, idx) in group.items" :key="item.id"
                class="w-full text-left flex items-center gap-3 px-4 py-2 transition-colors"
                :class="absoluteIndex(gi, idx) === selectedIndex ? 'is-active' : ''"
                :style="absoluteIndex(gi, idx) === selectedIndex
                  ? 'background: var(--accent-weak); color: var(--accent);'
                  : 'color: var(--text-secondary);'"
                :data-testid="`command-item-${item.id}`"
                @mouseenter="selectedIndex = absoluteIndex(gi, idx)"
                @click="executeItem(item)"
              >
                <span class="w-5 h-5 shrink-0 grid place-items-center" style="color: var(--text-muted);" v-html="item.icon"></span>
                <span class="flex-1 truncate text-sm">
                  <span style="color: var(--text-primary);">{{ item.label }}</span>
                  <span v-if="item.hint" class="ml-2 text-xs" style="color: var(--text-muted);">{{ item.hint }}</span>
                </span>
                <span v-if="item.shortcut" class="sn-kbd shrink-0">{{ item.shortcut }}</span>
                <span v-else-if="item.path" class="sn-kbd shrink-0 opacity-70">→</span>
              </button>
            </template>

            <div
              v-if="!totalResults"
              class="px-4 py-8 text-center text-sm"
              style="color: var(--text-muted);"
            >
              No matches for <code class="mono" style="color: var(--text-secondary);">{{ query }}</code>
            </div>
          </div>

          <!-- Footer -->
          <div class="px-4 py-2 flex items-center justify-between border-t text-[11px]"
               style="border-color: var(--border); color: var(--text-muted); background: rgba(0,0,0,0.25);">
            <span class="flex items-center gap-2">
              <span class="sn-kbd">↑↓</span> navigate
              <span class="sn-kbd">↵</span> open
              <span class="sn-kbd">esc</span> close
              <button class="sn-kbd hover:text-white transition-colors" data-testid="command-palette-cheat" @click="openCheat">?</button> shortcuts
            </span>
            <span>SpiderNetOS · ⌘K</span>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Keyboard cheat sheet -->
    <Transition name="cmdbar">
      <div
        v-if="cheatVisible"
        class="fixed inset-0 z-[9999] flex items-center justify-center px-4"
        style="background: rgba(5,7,10,0.65); backdrop-filter: blur(6px);"
        role="dialog"
        aria-modal="true"
        aria-label="Keyboard shortcuts"
        data-testid="cheat-sheet"
        @click.self="closeCheat"
        @keydown.escape="closeCheat"
      >
        <div class="w-full max-w-2xl sn-panel overflow-hidden" style="border-color: var(--border-active);">
          <header class="px-5 py-4 border-b flex items-center justify-between" style="border-color: var(--border);">
            <div>
              <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">Cockpit</div>
              <h3 class="font-heading font-semibold text-[16px] mt-0.5" style="color: var(--text-primary);">
                Keyboard shortcuts &amp; slash commands
              </h3>
            </div>
            <button class="sn-btn" data-testid="cheat-close" @click="closeCheat">
              <span class="sn-kbd">esc</span>
            </button>
          </header>

          <div class="px-5 py-4 grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-1">
            <div>
              <div class="text-[10px] tracking-widest uppercase font-semibold mb-2 mt-1"
                   style="color: var(--text-muted);">Navigation</div>
              <div v-for="row in CHEATS.nav" :key="row.label"
                   class="flex items-center justify-between py-1 text-sm" style="color: var(--text-secondary);">
                <span>{{ row.label }}</span>
                <span class="flex gap-1"><span v-for="k in row.keys" :key="k" class="sn-kbd">{{ k }}</span></span>
              </div>
            </div>
            <div>
              <div class="text-[10px] tracking-widest uppercase font-semibold mb-2 mt-1"
                   style="color: var(--text-muted);">Palette</div>
              <div v-for="row in CHEATS.palette" :key="row.label"
                   class="flex items-center justify-between py-1 text-sm" style="color: var(--text-secondary);">
                <span>{{ row.label }}</span>
                <span class="flex gap-1"><span v-for="k in row.keys" :key="k" class="sn-kbd">{{ k }}</span></span>
              </div>
            </div>

            <div class="md:col-span-2 mt-3">
              <div class="text-[10px] tracking-widest uppercase font-semibold mb-2"
                   style="color: var(--text-muted);">Atlas slash commands</div>
              <ul class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1.5 text-sm">
                <li v-for="cmd in CHEATS.slash" :key="cmd.cmd" class="flex items-baseline gap-3">
                  <code class="mono text-[12px] px-1.5 py-0.5 rounded" style="background: var(--bg-elevated); color: var(--accent); border: 1px solid var(--border);">{{ cmd.cmd }}</code>
                  <span style="color: var(--text-secondary);">{{ cmd.label }}</span>
                </li>
              </ul>
            </div>
          </div>

          <footer class="px-5 py-3 border-t text-[11px]"
                  style="border-color: var(--border); background: rgba(0,0,0,0.25); color: var(--text-muted);">
            Press <span class="sn-kbd">?</span> any time to open this list.
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth.js'
import { useAtlasStore } from '../stores/atlas.js'
import api from '../services/api.js'

const router = useRouter()
const authStore = useAuthStore()
const atlasStore = useAtlasStore()

const visible = ref(false)
const cheatVisible = ref(false)
const query = ref('')
const selectedIndex = ref(0)
const inputRef = ref(null)
const panelRef = ref(null)

// Live lanes — fetched lazily on open
const recentTraces = ref([])
const recentApprovals = ref([])
const lanesLoaded = ref(false)

// ── Index ──────────────────────────────────────────────────────────
// Routes the cockpit can navigate to + actions Atlas can execute.
// Static index avoids surprising users with stale router state and
// keeps keyboard latency essentially zero.

const ICONS = {
  arrow:    '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>',
  cmd:      '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6V4m0 16v-2m6-12V4m0 16v-2m-9-9H4m16 0h-2m-9 6H4m16 0h-2"/></svg>',
  bolt:     '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 2L3 14h7l-1 8 11-14h-7l0-6z"/></svg>',
  user:     '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="3"/><path stroke-linecap="round" d="M5 21a7 7 0 0114 0"/></svg>',
}

const ROUTES = [
  { id: 'r-dashboard',  label: 'Dashboard',           hint: 'Overview & ops feed', path: '/' },
  { id: 'r-atlas',      label: 'Atlas',               hint: 'Command surface',     path: '/atlas' },
  { id: 'r-approvals',  label: 'Approvals',           hint: 'Review queue',        path: '/approvals' },
  { id: 'r-traces',     label: 'Traces',              hint: 'Execution history',   path: '/traces' },
  { id: 'r-agents',     label: 'Agents',              hint: 'Manage AI workers',   path: '/agents' },
  { id: 'r-flows',      label: 'Flows',               hint: 'DAG library',         path: '/flows' },
  { id: 'r-flow-new',   label: 'New flow',            hint: 'Open builder',        path: '/flows/new' },
  { id: 'r-memory',     label: 'Memory',              hint: 'Knowledge & facts',   path: '/memory' },
  { id: 'r-intel',      label: 'Intelligence',        hint: 'Workers & jobs',      path: '/intelligence' },
  { id: 'r-usage',      label: 'Usage',               hint: 'Cost & budgets',      path: '/usage' },
  { id: 'r-settings',   label: 'Settings',            hint: 'Tenant settings',     path: '/settings' },
  { id: 'r-automation', label: 'Automation level',    hint: 'Manual / Assisted / Autonomous', path: '/settings/automation-level' },
  { id: 'r-billing',    label: 'Billing',             hint: 'Plan & invoices',     path: '/billing' },
  { id: 'r-onboarding', label: 'Onboarding wizard',   hint: 'First-run flow',      path: '/onboarding' },
]

const ADMIN_ROUTES = [
  { id: 'r-admin',         label: 'Admin overview', hint: 'Tenant admin',  path: '/admin' },
  { id: 'r-admin-users',   label: 'Admin · Users',  hint: 'Invite / manage',path: '/admin/users' },
  { id: 'r-admin-budget',  label: 'Admin · Budget', hint: 'Caps & alerts',  path: '/admin/budget' },
  { id: 'r-admin-copy',    label: 'Admin · Copy lab', hint: 'Bandit experiments', path: '/admin/copy' },
  { id: 'r-admin-audit',   label: 'Admin · Audit',  hint: 'Audit log',      path: '/admin/audit' },
]

const PLATFORM_ROUTES = [
  { id: 'r-pf',          label: 'Platform overview',  hint: 'Tenants & rollouts', path: '/platform' },
  { id: 'r-pf-flags',    label: 'Feature flags',      hint: 'Toggle matrix',     path: '/platform/feature-flags' },
  { id: 'r-pf-rollout',  label: 'Rollout · usage v2', hint: 'Cutover controls',   path: '/platform/rollouts/usage-v2' },
  { id: 'r-pf-ste',      label: 'State Transition Engine', hint: 'Tenant projections', path: '/platform/ste' },
]

const ACTIONS = [
  { id: 'a-new-flow',       label: 'Create new flow',                hint: 'Action',  shortcut: 'N F', run: () => router.push('/flows/new') },
  { id: 'a-new-agent',      label: 'Create new agent',               hint: 'Action',  shortcut: 'N A', run: () => router.push('/agents/new') },
  { id: 'a-atlas-status',   label: 'Atlas: weekly outcomes',         hint: 'Atlas',   run: () => askAtlas('/status weekly') },
  { id: 'a-atlas-approvals',label: 'Atlas: pending approvals',       hint: 'Atlas',   run: () => askAtlas('/approvals pending') },
  { id: 'a-atlas-anomalies',label: 'Atlas: budget anomalies',        hint: 'Atlas',   run: () => askAtlas('/usage anomalies') },
  { id: 'a-atlas-trace',    label: 'Atlas: latest trace',            hint: 'Atlas',   run: () => askAtlas('/trace latest') },
  { id: 'a-promote',        label: 'Promote agent → autonomous',     hint: 'Atlas',   run: () => askAtlas('/agents promote ag_1') },
  { id: 'a-logout',         label: 'Sign out',                       hint: 'Account', run: () => { authStore.logout(); router.push('/login') } },
  { id: 'a-toggle-role-admin', label: 'Switch role → admin',         hint: 'Demo',    run: () => authStore.switchRole('admin') },
  { id: 'a-toggle-role-super', label: 'Switch role → super_admin',   hint: 'Demo',    run: () => authStore.switchRole('super_admin') },
  { id: 'a-toggle-role-user',  label: 'Switch role → user',          hint: 'Demo',    run: () => authStore.switchRole('user') },
]

function askAtlas(cmd) {
  router.push('/atlas').then(() => atlasStore.sendMessage?.(cmd).catch(() => {}))
}

// Cheat-sheet content (kept in lockstep with onGlobalKeydown + ACTIONS).
const CHEATS = {
  nav: [
    { label: 'Open command palette',   keys: ['⌘', 'K'] },
    { label: 'Open command palette',   keys: ['Ctrl', 'K'] },
    { label: 'Open command palette',   keys: ['/'] },
    { label: 'Show this help',         keys: ['?'] },
    { label: 'Close any modal',        keys: ['esc'] },
    { label: 'Move selection',         keys: ['↑', '↓'] },
    { label: 'Open / run',             keys: ['↵'] },
  ],
  palette: [
    { label: 'Jump to Atlas',          keys: ['G', 'A'] },
    { label: 'Jump to Approvals',      keys: ['G', 'P'] },
    { label: 'Jump to Traces',         keys: ['G', 'T'] },
    { label: 'New flow',               keys: ['N', 'F'] },
    { label: 'New agent',              keys: ['N', 'A'] },
    { label: 'Switch role',            keys: ['R'] },
    { label: 'Sign out',               keys: ['Q'] },
  ],
  slash: [
    { cmd: '/status weekly',           label: 'Summarize the last 7 days of outcomes' },
    { cmd: '/approvals pending',       label: 'Show all pending approvals' },
    { cmd: '/usage anomalies',         label: 'Surface budget anomalies' },
    { cmd: '/trace latest',            label: 'Open the most recent trace' },
    { cmd: '/agents promote <id>',     label: 'Promote agent to autonomous mode' },
    { cmd: '/flows publish <slug>',    label: 'Publish a draft flow (queues approval)' },
    { cmd: '/budget set <usd>',        label: 'Set monthly budget cap' },
    { cmd: '/memory search <q>',       label: 'Search tenant memory' },
  ],
}

// Role-aware index assembly
const allRoutes = computed(() => {
  const out = [...ROUTES]
  if (authStore.isAdmin) out.push(...ADMIN_ROUTES)
  if (authStore.isSuperAdmin) out.push(...PLATFORM_ROUTES)
  return out
})

// ── Fuzzy scoring ──────────────────────────────────────────────────
// Returns Infinity if the query doesn't match (subsequence on label or hint).
// Lower score = better.
function score(item, q) {
  if (!q) return 0
  const hay = `${item.label} ${item.hint || ''} ${item.path || ''}`.toLowerCase()
  const needle = q.toLowerCase()
  if (hay.startsWith(needle)) return 0
  if (hay.includes(needle)) return 10
  // Subsequence
  let i = 0, lastIdx = -1, gaps = 0
  for (let h = 0; h < hay.length && i < needle.length; h++) {
    if (hay[h] === needle[i]) {
      if (lastIdx >= 0) gaps += h - lastIdx - 1
      lastIdx = h
      i++
    }
  }
  if (i === needle.length) return 30 + gaps + (hay.length - needle.length) * 0.05
  return Infinity
}

const matchedRoutes = computed(() => {
  const q = query.value.trim()
  return allRoutes.value
    .map((r) => ({ ...r, _s: score(r, q), kind: 'route', icon: ICONS.arrow }))
    .filter((r) => r._s !== Infinity)
    .sort((a, b) => a._s - b._s)
    .slice(0, 8)
})

const matchedActions = computed(() => {
  const q = query.value.trim()
  return ACTIONS
    .map((a) => ({ ...a, _s: score(a, q), kind: 'action', icon: ICONS.bolt }))
    .filter((a) => a._s !== Infinity)
    .sort((a, b) => a._s - b._s)
    .slice(0, 6)
})

const groupedResults = computed(() => {
  const q = query.value.trim()
  if (!q) {
    // Empty state: surface live lanes + Quick jumps + Common actions.
    const groups = []
    if (recentApprovals.value.length) {
      groups.push({
        label: 'Live · pending approvals',
        items: recentApprovals.value.slice(0, 4).map((a) => ({
          id: `live-apr-${a.id}`,
          label: a.title || a.resource_name || a.id,
          hint: `${a.risk || 'low'} risk · ${a.requested_by || 'Atlas'}`,
          path: '/approvals',
          kind: 'route',
          icon: ICONS.bolt,
        })),
      })
    }
    if (recentTraces.value.length) {
      groups.push({
        label: 'Live · recent traces',
        items: recentTraces.value.slice(0, 4).map((t) => ({
          id: `live-trc-${t.id}`,
          label: t.subject || t.id,
          hint: `${t.kind} · ${t.status} · ${t.actor || ''}`,
          path: '/traces',
          kind: 'route',
          icon: ICONS.arrow,
        })),
      })
    }
    groups.push(
      { label: 'Quick jumps',    items: allRoutes.value.slice(0, 6).map((r) => ({ ...r, kind: 'route', icon: ICONS.arrow })) },
      { label: 'Common actions', items: ACTIONS.slice(0, 4).map((a) => ({ ...a, kind: 'action', icon: ICONS.bolt })) },
    )
    return groups
  }
  return [
    { label: 'Routes',  items: matchedRoutes.value },
    { label: 'Actions', items: matchedActions.value },
  ]
})

const totalResults = computed(() => groupedResults.value.reduce((n, g) => n + g.items.length, 0))

function absoluteIndex(groupIdx, itemIdx) {
  let acc = 0
  for (let i = 0; i < groupIdx; i++) acc += groupedResults.value[i].items.length
  return acc + itemIdx
}

function flat() {
  return groupedResults.value.flatMap((g) => g.items)
}

function moveSelection(delta) {
  const list = flat()
  if (!list.length) return
  selectedIndex.value = (selectedIndex.value + delta + list.length) % list.length
}

function executeSelected() {
  const list = flat()
  const item = list[selectedIndex.value]
  if (item) executeItem(item)
}

function executeItem(item) {
  if (item.kind === 'action' && typeof item.run === 'function') {
    item.run()
  } else if (item.path) {
    router.push(item.path)
  }
  close()
}

watch(query, () => { selectedIndex.value = 0 })

// ── Open / close + global hotkey ────────────────────────────────────

function open() {
  visible.value = true
  query.value = ''
  selectedIndex.value = 0
  if (!lanesLoaded.value) loadLiveLanes()
  nextTick(() => inputRef.value?.focus())
}

function close() {
  visible.value = false
  query.value = ''
}

async function loadLiveLanes() {
  try {
    const [tracesResp, approvalsResp] = await Promise.allSettled([
      api.get('/api/traces'),
      api.get('/api/approvals'),
    ])
    if (tracesResp.status === 'fulfilled') {
      recentTraces.value = (tracesResp.value.data?.data || []).slice(0, 6)
    }
    if (approvalsResp.status === 'fulfilled') {
      const all = approvalsResp.value.data?.data || []
      recentApprovals.value = all.filter((a) => a.status === 'pending').slice(0, 6)
    }
    lanesLoaded.value = true
  } catch { /* lanes are best-effort */ }
}

function openCheat() {
  cheatVisible.value = true
}

function closeCheat() {
  cheatVisible.value = false
}

function onGlobalKeydown(event) {
  // ⌘K / Ctrl+K — toggle the palette regardless of focus
  const isModK = (event.metaKey || event.ctrlKey) && (event.key === 'k' || event.key === 'K')
  if (isModK) {
    event.preventDefault()
    visible.value ? close() : open()
    return
  }

  const tag = event.target?.tagName?.toLowerCase()
  const editable = tag === 'input' || tag === 'textarea' || event.target?.isContentEditable

  // "/" opens palette when no input is focused
  if (event.key === '/' && !visible.value && !editable) {
    event.preventDefault()
    open()
    return
  }

  // "?" opens cheat sheet (Shift+/) when no input is focused
  if ((event.key === '?' || (event.shiftKey && event.key === '/')) && !editable) {
    if (visible.value) return
    event.preventDefault()
    cheatVisible.value ? closeCheat() : openCheat()
  }
}

onMounted(() => { document.addEventListener('keydown', onGlobalKeydown) })
onBeforeUnmount(() => { document.removeEventListener('keydown', onGlobalKeydown) })

defineExpose({ open, close, openCheat, closeCheat })
</script>

<style scoped>
.cmdbar-enter-active, .cmdbar-leave-active { transition: opacity 0.16s ease; }
.cmdbar-enter-from, .cmdbar-leave-to { opacity: 0; }
.cmdbar-enter-active > div, .cmdbar-leave-active > div {
  transition: transform 0.16s ease, opacity 0.16s ease;
}
.cmdbar-enter-from > div { transform: translateY(-6px); opacity: 0.6; }
.cmdbar-leave-to > div   { transform: translateY(-3px); opacity: 0.4; }
.is-active { color: var(--accent) !important; }
</style>
