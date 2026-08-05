<template>
  <!-- Public route (no shell, no auth) -->
  <RouterView v-if="route.meta.public" />

  <!-- Unauthenticated: full-bleed hero -->
  <div
    v-else-if="!authStore.isAuthenticated"
    class="min-h-screen"
    style="background: var(--gradient-hero);"
  >
    <RouterView />
  </div>

  <!-- Authenticated shell -->
  <div v-else class="min-h-screen flex flex-col" style="background: var(--bg);">
    <ImpersonationBanner />

    <!-- Top bar -->
    <header
      class="flex items-center justify-between px-4 h-12 border-b sticky top-0 z-30"
      style="background: rgba(10,13,18,0.85); border-color: var(--border); backdrop-filter: blur(14px);"
      data-testid="app-top-bar"
    >
      <div class="flex items-center gap-4 min-w-0">
        <div class="flex items-center gap-2.5 shrink-0">
          <!-- SpiderNet logo mark — matches landing/marketing surface -->
          <svg width="26" height="26" viewBox="0 0 32 32" fill="none" aria-hidden="true">
            <defs>
              <linearGradient id="sn-lg-topbar" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#FF6B2C"/>
                <stop offset="1" stop-color="#00D6C9"/>
              </linearGradient>
            </defs>
            <circle cx="16" cy="16" r="3" fill="url(#sn-lg-topbar)"/>
            <g stroke="url(#sn-lg-topbar)" stroke-width="1.4" stroke-linecap="round" opacity="0.9">
              <line x1="16" y1="16" x2="6" y2="6"/>
              <line x1="16" y1="16" x2="26" y2="6"/>
              <line x1="16" y1="16" x2="6" y2="26"/>
              <line x1="16" y1="16" x2="26" y2="26"/>
              <line x1="16" y1="16" x2="16" y2="2"/>
              <line x1="16" y1="16" x2="16" y2="30"/>
              <line x1="16" y1="16" x2="2" y2="16"/>
              <line x1="16" y1="16" x2="30" y2="16"/>
            </g>
            <g fill="#F4F7FB">
              <circle cx="6" cy="6" r="1.4"/><circle cx="26" cy="6" r="1.4"/>
              <circle cx="6" cy="26" r="1.4"/><circle cx="26" cy="26" r="1.4"/>
              <circle cx="16" cy="2" r="1.2"/><circle cx="16" cy="30" r="1.2"/>
              <circle cx="2" cy="16" r="1.2"/><circle cx="30" cy="16" r="1.2"/>
            </g>
          </svg>
          <span class="font-heading font-semibold tracking-tight text-[15px]" style="color: var(--text-primary);">
            Spider<span style="color: var(--accent-warm);">Net</span>OS
          </span>
        </div>

        <!-- Tenant switcher -->
        <div class="h-5 w-px" style="background: var(--border);"></div>
        <button
          class="flex items-center gap-2 px-2 py-1 rounded-md text-sm hover:bg-ink-700"
          style="color: var(--text-secondary);"
          data-testid="tenant-switcher-button"
          @click="showTenantMenu = !showTenantMenu"
        >
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l7-4 7 4v14M9 9h1m4 0h1m-6 4h1m4 0h1m-6 4h1m4 0h1"/>
          </svg>
          <span class="font-medium" style="color: var(--text-primary);">{{ authStore.tenant?.name || 'Acme Ops' }}</span>
          <svg class="w-3 h-3" viewBox="0 0 20 20" fill="currentColor"><path d="M5 7l5 5 5-5H5z"/></svg>
        </button>

        <!-- Environment badge -->
        <span class="sn-pill" :class="envPillClass" data-testid="env-badge">
          <span class="w-1.5 h-1.5 rounded-full" :style="`background: ${envColor};`"></span>
          {{ envLabel }}
        </span>

        <!-- Breadcrumbs on deep routes -->
        <nav v-if="breadcrumbs.length > 1" aria-label="Breadcrumb" class="hidden md:flex items-center gap-1.5 ml-2 text-xs truncate">
          <template v-for="(crumb, i) in breadcrumbs" :key="crumb.path">
            <span v-if="i > 0" style="color: var(--text-muted);">/</span>
            <RouterLink
              :to="crumb.path"
              class="hover:text-white truncate"
              :style="i === breadcrumbs.length - 1 ? 'color: var(--text-primary);' : 'color: var(--text-muted);'"
            >{{ crumb.label }}</RouterLink>
          </template>
        </nav>
      </div>

      <div class="flex items-center gap-3 shrink-0">
        <!-- WS status -->
        <span class="flex items-center gap-1.5 text-xs" style="color: var(--text-muted);" data-testid="ws-status">
          <span class="w-1.5 h-1.5 rounded-full" :class="wsConnected ? 'sn-dot-live' : 'sn-dot-off'"></span>
          <span :style="wsConnected ? 'color: var(--accent);' : ''">{{ wsConnected ? 'Live' : 'Offline' }}</span>
        </span>

        <!-- Command palette trigger -->
        <button
          class="hidden md:flex items-center gap-2 px-2.5 py-1 rounded-md text-xs hover:border-cyan-500"
          style="background: var(--bg-elevated); border: 1px solid var(--border); color: var(--text-secondary);"
          data-testid="command-palette-trigger"
          @click="openCommandBar"
          title="Open command palette"
        >
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="7" />
            <path stroke-linecap="round" d="M21 21l-4.3-4.3"/>
          </svg>
          <span>Jump to…</span>
          <span class="sn-kbd">⌘K</span>
        </button>

        <!-- Budget alert -->
        <RouterLink v-if="usageStore.isNearLimit" to="/usage" class="sn-pill sn-pill-warn" data-testid="budget-alert">
          Budget
        </RouterLink>

        <!-- Help -->
        <a href="#" class="p-1.5 rounded-md hover:bg-ink-700" style="color: var(--text-muted);" aria-label="Help" data-testid="help-link">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M9.1 9a3 3 0 115.8 1c0 2-3 2-3 4M12 17h.01"/>
          </svg>
        </a>

        <!-- User menu -->
        <div class="relative">
          <button
            class="flex items-center gap-2 px-1.5 py-1 rounded-md hover:bg-ink-700"
            data-testid="user-menu-button"
            @click="showUserMenu = !showUserMenu"
          >
            <div
              class="w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-semibold"
              style="background: var(--accent-weak); color: var(--accent); border: 1px solid rgba(0,229,200,0.30);"
            >{{ userInitials }}</div>
            <span class="text-sm hidden sm:inline" style="color: var(--text-primary);">{{ authStore.user?.name }}</span>
            <svg class="w-3 h-3 hidden sm:inline" viewBox="0 0 20 20" fill="currentColor" style="color: var(--text-muted);">
              <path d="M5 7l5 5 5-5H5z"/>
            </svg>
          </button>

          <div
            v-if="showUserMenu"
            class="absolute right-0 top-full mt-1 w-64 rounded-lg sn-panel py-1.5 z-40"
            data-testid="user-menu-dropdown"
          >
            <div class="px-3 py-2 border-b" style="border-color: var(--border);">
              <div class="text-xs" style="color: var(--text-muted);">Signed in as</div>
              <div class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ authStore.user?.email }}</div>
              <div class="mt-1 flex items-center gap-2">
                <RoleBadge :role="authStore.role" />
              </div>
            </div>

            <!-- Role switcher (demo) -->
            <div class="px-3 py-2 border-b" style="border-color: var(--border);">
              <div class="text-[10px] tracking-widest uppercase font-semibold mb-1.5" style="color: var(--text-muted);">Demo: Switch Role</div>
              <div class="grid grid-cols-3 gap-1" data-testid="role-switcher">
                <button
                  v-for="r in ['user','admin','super_admin']"
                  :key="r"
                  class="px-2 py-1 text-[11px] rounded-md transition-colors"
                  :class="authStore.role === r ? 'text-white' : 'hover:text-white'"
                  :style="authStore.role === r
                    ? 'background: var(--accent-weak); color: var(--accent); border:1px solid rgba(0,229,200,0.30);'
                    : 'background: var(--bg-elevated); color: var(--text-secondary); border:1px solid var(--border);'"
                  :data-testid="`role-switch-${r}`"
                  @click="switchTo(r)"
                >{{ r === 'super_admin' ? 'Super' : r.charAt(0).toUpperCase() + r.slice(1) }}</button>
              </div>
            </div>

            <RouterLink
              v-for="item in userMenuItems" :key="item.path" :to="item.path"
              class="block px-3 py-1.5 text-sm hover:bg-ink-700"
              style="color: var(--text-secondary);"
              :data-testid="`user-menu-${item.key}`"
              @click="showUserMenu = false"
            >{{ item.label }}</RouterLink>

            <div class="border-t mt-1 pt-1" style="border-color: var(--border);">
              <button
                class="w-full text-left px-3 py-1.5 text-sm hover:bg-ink-700"
                style="color: var(--danger);"
                data-testid="logout-button"
                @click="logout"
              >Sign out</button>
            </div>
          </div>
        </div>
      </div>
    </header>

    <div class="flex flex-1 min-h-0">
      <!-- Sidebar -->
      <aside
        class="w-56 shrink-0 flex flex-col border-r"
        style="background: var(--bg-subtle); border-color: var(--border);"
        data-testid="app-sidebar"
      >
        <!-- Workspace pivot -->
        <div v-if="hasWorkspaceChoice" class="px-3 pt-3">
          <div class="sn-nav-group-label pt-0">Workspace</div>
          <div class="flex rounded-md overflow-hidden border" style="border-color: var(--border);">
            <button
              v-for="ws in workspaceChoices" :key="ws.value"
              class="flex-1 px-2 py-1 text-xs font-medium transition-colors"
              :class="currentWorkspace === ws.value ? 'text-white' : ''"
              :style="currentWorkspace === ws.value
                ? 'background: var(--accent-weak); color: var(--accent);'
                : 'background: transparent; color: var(--text-muted);'"
              :data-testid="`workspace-pivot-${ws.key}`"
              @click="switchWorkspace(ws.value)"
            >{{ ws.label }}</button>
          </div>
        </div>

        <nav class="flex-1 px-3 pt-2 pb-3 space-y-0.5 overflow-y-auto" :aria-label="`${workspaceLabel} navigation`">
          <template v-for="group in visibleNavigation" :key="group.label">
            <div class="sn-nav-group-label">{{ group.label }}</div>
            <RouterLink
              v-for="item in group.items" :key="item.path" :to="item.path"
              class="sn-nav-link"
              :class="{ active: isActiveRoute(item.path, item.exact) }"
              :data-testid="`nav-${item.key}`"
            >
              <span v-html="item.icon" class="shrink-0 opacity-80" />
              <span class="truncate">{{ item.name }}</span>
              <span v-if="item.badge" class="ml-auto sn-pill sn-pill-accent text-[10px]">{{ item.badge }}</span>
            </RouterLink>
          </template>
        </nav>

        <div class="px-3 py-3 border-t text-[11px] space-y-1" style="border-color: var(--border); color: var(--text-muted);">
          <div class="flex items-center justify-between">
            <span>Automation</span>
            <span class="sn-pill" :class="autoPillClass">{{ authStore.automationLevel }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span>Plan</span>
            <span>{{ authStore.tenant?.plan || 'Ops Pro' }}</span>
          </div>
        </div>
      </aside>

      <!-- Main -->
      <main class="flex-1 flex flex-col min-w-0">
        <div class="flex-1 overflow-auto" style="background: var(--bg);">
          <RouterView v-slot="{ Component }">
            <transition name="sn-fade" mode="out-in">
              <component :is="Component" />
            </transition>
          </RouterView>
        </div>
      </main>
    </div>

    <CommandBar ref="cmdBarRef" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth.js'
import { useUsageStore } from './stores/usage.js'
import { useWebSocket } from './composables/useWebSocket.js'
import { useAgentsStore } from './stores/agents.js'
import { useFlowsStore } from './stores/flows.js'
import CommandBar from './components/CommandBar.vue'
import RoleBadge from './components/security/RoleBadge.vue'
import ImpersonationBanner from './components/impersonation/ImpersonationBanner.vue'
import { useApprovalsStore } from './stores/approvals.js'
import { useTracesStore } from './stores/traces.js'
import { useAtlasStore } from './stores/atlas.js'
import { useExpensesStore } from './stores/expenses.js'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const usageStore = useUsageStore()
const agentsStore = useAgentsStore()
const flowsStore = useFlowsStore()
const approvalsStore = useApprovalsStore()
const tracesStore = useTracesStore()
const atlasStore = useAtlasStore()
const expensesStore = useExpensesStore()

const showUserMenu = ref(false)
const showTenantMenu = ref(false)
const cmdBarRef = ref(null)

// Env badge
const envLabel = computed(() => import.meta.env.MODE === 'production' ? 'prod' : 'local')
const envColor = computed(() => envLabel.value === 'prod' ? '#22D39B' : '#F5A524')
const envPillClass = computed(() => envLabel.value === 'prod' ? 'sn-pill-success' : 'sn-pill-warn')

// Icons (consistent stroke)
const ic = {
  dashboard: '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>',
  atlas:    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5M4 19l2-2h13a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v13z"/></svg>',
  agents:   '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 20a6 6 0 0112 0"/></svg>',
  flows:    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="5" cy="6" r="2"/><circle cx="19" cy="6" r="2"/><circle cx="12" cy="18" r="2"/><path stroke-linecap="round" d="M7 6h10M6 8l5 8M18 8l-5 8"/></svg>',
  approvals:'<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
  traces:   '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M3 19h18M7 19V9m5 10V5m5 14v-7"/></svg>',
  usage:    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 15l4-4 3 3 5-6"/></svg>',
  memory:   '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="12" rx="2"/><path stroke-linecap="round" d="M7 10h2m2 0h2m2 0h2M7 14h2m2 0h2m2 0h2"/></svg>',
  intel:    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3v4m6-4v4M5 7h14M5 21h14M7 7v14m10-14v14M11 11h2m-2 4h2"/></svg>',
  settings: '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/></svg>',
  billing:  '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="12" rx="2"/><path stroke-linecap="round" d="M3 10h18M7 15h4"/></svg>',
  users:    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2m18 0v-2a4 4 0 00-3-3.9M14 4a4 4 0 110 8M10 8a4 4 0 11-8 0 4 4 0 018 0z"/></svg>',
  audit:    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5V3h6v2M9 12h6m-6 4h4"/></svg>',
  copy:     '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h10M4 18h16"/></svg>',
  flags:    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M5 21V5l7 3 7-3v10l-7 3-7-3"/></svg>',
  rollout:  '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12"/></svg>',
  ste:      '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path stroke-linecap="round" d="M8.5 6h7M6 8.5v7M18 8.5v7M8.5 18h7"/></svg>',
  overview: '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M3 12h18M12 3c2.5 3 2.5 15 0 18M12 3c-2.5 3-2.5 15 0 18"/></svg>',
  connectors: '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M7 7V3M17 7V3M5 7h14v4a7 7 0 01-14 0V7zM12 18v3"/></svg>',
  aios:     '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/></svg>',
  shield:   '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l8 4v5c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V7l8-4z"/></svg>',
  comms:    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M8 12h8M8 16h5M4 6h16v12H4z"/></svg>',
  packs:    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 3l8 4v10l-8 4-8-4V7l8-4z"/></svg>',
  outcomes: '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 19h16M8 17V7m4 10V5m4 12V9"/></svg>',
  finance:  '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="12" rx="2"/><path stroke-linecap="round" d="M3 10h18M7 15h4"/></svg>',
  sales:    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>',
  shield2:  '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M9 12l2 2 4-4m5-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
  firstwin: '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>',
  receipt:  '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 3h14v18l-2.5-1.5L14 21l-2-1.5L10 21l-2.5-1.5L5 21V3zM9 8h6m-6 4h6m-6 4h3"/></svg>',
}

// Grouped nav
const userNav = {
  label: 'Operate', items: [
    { key: 'dashboard', name: 'Dashboard', path: '/', exact: true, icon: ic.dashboard },
    { key: 'atlas', name: 'Atlas', path: '/atlas', icon: ic.atlas, badge: null },
    { key: 'first-win', name: 'First win', path: '/operate/first-win', icon: ic.firstwin },
    { key: 'communications', name: 'Communications', path: '/communications', icon: ic.comms },
    { key: 'approvals', name: 'Approvals', path: '/approvals', icon: ic.approvals },
    { key: 'traces', name: 'Traces', path: '/traces', icon: ic.traces },
  ],
}
const packsNav = {
  label: 'Packs', items: [
    { key: 'financial', name: 'Financial OS', path: '/financial', exact: true, icon: ic.finance },
    { key: 'sales', name: 'Sales & CRM', path: '/sales', icon: ic.sales },
    { key: 'compliance', name: 'Compliance Radar', path: '/compliance', icon: ic.shield2 },
    { key: 'feature-packs', name: 'All packs', path: '/feature-packs', icon: ic.packs },
  ],
}
const spendNav = {
  label: 'Spend', items: [
    { key: 'expenses', name: 'Expenses', path: '/financial/expenses', icon: ic.receipt, capability: 'expenses.submit' },
  ],
}
const buildNav = {
  label: 'Build', items: [
    { key: 'agents', name: 'Agents', path: '/agents', icon: ic.agents },
    { key: 'flows', name: 'Flows', path: '/flows', icon: ic.flows },
    { key: 'memory', name: 'Memory', path: '/memory', icon: ic.memory },
    { key: 'feature-packs', name: 'Feature packs', path: '/feature-packs', icon: ic.packs },
    { key: 'connectors', name: 'Connectors', path: '/connectors', icon: ic.connectors },
  ],
}
const observeNav = {
  label: 'Observe', items: [
    { key: 'outcomes', name: 'Weekly review', path: '/outcomes', icon: ic.outcomes },
    { key: 'usage', name: 'Usage', path: '/usage', icon: ic.usage },
    { key: 'intelligence', name: 'Intelligence', path: '/intelligence', icon: ic.intel },
  ],
}
const tenantNav = {
  label: 'Tenant', items: [
    { key: 'settings', name: 'Settings', path: '/settings', icon: ic.settings },
    { key: 'billing', name: 'Billing', path: '/billing', icon: ic.billing },
  ],
}

const enterpriseNav = {
  label: 'Enterprise', items: [
    { key: 'ent-connectors', name: 'Connectors', path: '/enterprise/connectors', icon: ic.connectors },
    { key: 'ent-aios', name: 'AIOS Downloads', path: '/enterprise/aios', icon: ic.aios },
    { key: 'ent-trust', name: 'Audit & Trust', path: '/enterprise/trust', icon: ic.shield },
  ],
}

const adminNav = [
  { label: 'Admin', items: [
    { key: 'admin-overview', name: 'Overview', path: '/admin', exact: true, icon: ic.overview },
    { key: 'admin-users', name: 'Users', path: '/admin/users', icon: ic.users },
    { key: 'admin-budget', name: 'Budget', path: '/admin/budget', icon: ic.billing },
    { key: 'admin-copy', name: 'Copy Lab', path: '/admin/copy', icon: ic.copy },
    { key: 'admin-audit', name: 'Audit', path: '/admin/audit', icon: ic.audit },
  ]},
]

const platformNav = [
  { label: 'Platform', items: [
    { key: 'pf-overview', name: 'Overview', path: '/platform', exact: true, icon: ic.overview },
    { key: 'pf-flags', name: 'Feature Flags', path: '/platform/feature-flags', icon: ic.flags },
    { key: 'pf-rollout', name: 'Rollouts', path: '/platform/rollouts/usage-v2', icon: ic.rollout },
    { key: 'pf-ste', name: 'State Engine', path: '/platform/ste', icon: ic.ste },
  ]},
]

const currentWorkspace = computed(() => {
  if (route.path.startsWith('/platform')) return '/platform'
  if (route.path.startsWith('/admin')) return '/admin'
  return '/'
})

const workspaceLabel = computed(() => ({
  '/platform': 'Platform', '/admin': 'Admin', '/': 'Workspace',
}[currentWorkspace.value] || 'Workspace'))

const workspaceChoices = computed(() => {
  const out = [{ key: 'user', value: '/', label: 'User' }]
  if (authStore.isAdmin) out.push({ key: 'admin', value: '/admin', label: 'Admin' })
  if (authStore.isSuperAdmin) out.push({ key: 'platform', value: '/platform', label: 'Platform' })
  return out
})

const hasWorkspaceChoice = computed(() => workspaceChoices.value.length > 1)

const visibleNavigation = computed(() => {
  const groups = (() => {
    if (currentWorkspace.value === '/platform') return platformNav
    if (currentWorkspace.value === '/admin') return adminNav
    return [userNav, packsNav, spendNav, buildNav, observeNav, enterpriseNav, tenantNav]
  })()
  // Per-item capability filtering — items without a capability always show.
  return groups
    .map((group) => ({
      ...group,
      items: group.items.filter((i) => !i.capability || authStore.has(i.capability)),
    }))
    .filter((group) => group.items.length)
})

function switchWorkspace(path) { router.push(path) }

function isActiveRoute(path, exact = false) {
  if (exact) return route.path === path
  return route.path === path || route.path.startsWith(path + '/')
}

const userInitials = computed(() => {
  const name = authStore.user?.name || 'SN'
  return name.split(' ').map((n) => n[0]).join('').toUpperCase().slice(0, 2)
})

const autoPillClass = computed(() => {
  const l = authStore.automationLevel
  if (l === 'autonomous') return 'sn-pill-accent'
  if (l === 'manual') return 'sn-pill-warn'
  return 'sn-pill'
})

const { isConnected: wsConnected } = useWebSocket(authStore, agentsStore, flowsStore, usageStore, approvalsStore, tracesStore, atlasStore, expensesStore)

function openCommandBar() {
  cmdBarRef.value?.open?.()
}

function logout() {
  authStore.logout()
  router.push('/login')
}

function switchTo(r) {
  authStore.switchRole(r)
  showUserMenu.value = false
}

const userMenuItems = [
  { key: 'settings', path: '/settings', label: 'Settings' },
  { key: 'automation', path: '/settings/automation-level', label: 'Automation Level' },
  { key: 'usage', path: '/usage', label: 'Usage' },
  { key: 'billing', path: '/billing', label: 'Billing' },
]

const BREADCRUMB_MAP = {
  '/atlas': ['Atlas'],
  '/agents': ['Agents'],
  '/agents/new': ['Agents', 'New'],
  '/agents/builder': ['Agents', 'Builder'],
  '/flows': ['Flows'],
  '/flows/new': ['Flows', 'New'],
  '/approvals': ['Approvals'],
  '/traces': ['Traces'],
  '/intelligence': ['Intelligence'],
  '/memory': ['Memory'],
  '/usage': ['Usage'],
  '/settings': ['Settings'],
  '/settings/automation-level': ['Settings', 'Automation Level'],
  '/settings/usage': ['Settings', 'Usage'],
  '/billing': ['Billing'],
  '/financial': ['Financial OS'],
  '/financial/expenses': ['Financial OS', 'Expenses'],
  '/financial/expenses/new': ['Financial OS', 'Expenses', 'New'],
  '/financial/ledger': ['Financial OS', 'Ledger'],
  '/financial/invoices': ['Financial OS', 'Invoices'],
  '/financial/payments': ['Financial OS', 'Payments'],
  '/financial/portfolios': ['Financial OS', 'Portfolios'],
  '/sales': ['Sales & CRM'],
  '/compliance': ['Compliance Radar'],
  '/operate/first-win': ['First win'],
  '/feature-packs': ['Feature packs'],
  '/admin': ['Admin'],
  '/admin/users': ['Admin', 'Users'],
  '/admin/audit': ['Admin', 'Audit'],
  '/admin/copy': ['Admin', 'Copy'],
  '/admin/budget': ['Admin', 'Budget'],
  '/platform': ['Platform'],
  '/platform/feature-flags': ['Platform', 'Feature Flags'],
  '/platform/rollouts/usage-v2': ['Platform', 'Rollouts', 'Usage v2'],
  '/platform/ste': ['Platform', 'State Engine'],
}

const breadcrumbs = computed(() => {
  const base = [{ path: '/', label: 'SpiderNet' }]
  // best prefix match
  const keys = Object.keys(BREADCRUMB_MAP)
    .filter((p) => route.path === p || route.path.startsWith(p + '/'))
    .sort((a, b) => b.length - a.length)
  if (!keys.length) return base
  const labels = BREADCRUMB_MAP[keys[0]]
  let acc = ''
  const segs = labels.map((label, idx) => {
    if (idx === 0) {
      // Root label path
      const first = keys[0].split('/').filter(Boolean)[0] || ''
      acc = '/' + first
    } else {
      const piece = keys[0].split('/').filter(Boolean)[idx] || ''
      acc += '/' + piece
    }
    return { path: acc, label }
  })
  return [...base, ...segs]
})

// Close menus on route change
watch(() => route.path, () => {
  showUserMenu.value = false
  showTenantMenu.value = false
})

onMounted(() => {
  if (authStore.isAuthenticated) {
    usageStore.fetchBudget()
    usageStore.fetchCurrentSpend()
  }
})
</script>

<style scoped>
.sn-fade-enter-active, .sn-fade-leave-active { transition: opacity 180ms ease, transform 180ms ease; }
.sn-fade-enter-from { opacity: 0; transform: translateY(3px); }
.sn-fade-leave-to   { opacity: 0; transform: translateY(-3px); }
</style>
