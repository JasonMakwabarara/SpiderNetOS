<template>
  <div v-if="!authStore.isAuthenticated" class="min-h-screen bg-gradient-hero">
    <RouterView />
  </div>

  <div v-else class="min-h-screen flex flex-col" style="background: var(--bg);">
    <!-- Global impersonation banner (super admin only) -->
    <ImpersonationBanner />

    <div class="flex flex-1 min-h-0">
      <!-- Sidebar: role-aware -->
      <aside class="w-64 flex flex-col border-r" style="background: var(--bg-card); border-color: var(--border);">
        <!-- Logo + role badge -->
        <div class="p-4 border-b" style="border-color: var(--border);">
          <div class="flex items-center space-x-2">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center bg-gradient-brand">
              <svg class="w-5 h-5" style="color: #1A0A1E;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
            </div>
            <span class="font-semibold font-heading dct-grad-text text-lg">SpiderNet</span>
          </div>
          <div class="mt-2 flex items-center gap-2">
            <RoleBadge :role="authStore.role" />
            <span class="text-xs truncate" style="color: var(--text-muted);">{{ authStore.tenant?.name }}</span>
          </div>
        </div>

        <!-- Workspace switcher (super admin pivot) -->
        <div v-if="authStore.isSuperAdmin" class="px-3 pt-3">
          <label for="ws-switch" class="sr-only">Workspace</label>
          <select
            id="ws-switch"
            class="w-full px-2 py-1.5 text-sm border rounded"
            :value="currentWorkspace"
            @change="switchWorkspace($event.target.value)"
          >
            <option value="/">User workspace</option>
            <option value="/admin">Admin workspace</option>
            <option value="/platform">Platform workspace</option>
          </select>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 p-3 space-y-0.5 overflow-y-auto" :aria-label="`${workspaceLabel} navigation`">
          <RouterLink
            v-for="item in visibleNavigation"
            :key="item.path"
            :to="item.path"
            class="dct-nav-link"
            :class="{ 'active': isActiveRoute(item.path, item.exact) }"
          >
            <span v-html="item.icon" />
            <span class="text-sm font-medium">{{ item.name }}</span>
          </RouterLink>
        </nav>

        <!-- User section -->
        <div class="p-4 border-t" style="border-color: var(--border);">
          <div class="flex items-center space-x-3">
            <div
              class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold"
              style="background: color-mix(in srgb, #FF6EB4 18%, transparent); color: #FF6EB4;"
            >
              {{ userInitials }}
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ authStore.user?.name }}</p>
              <p class="text-xs truncate" style="color: var(--text-muted);">{{ authStore.user?.email }}</p>
            </div>
            <button
              @click="logout"
              class="p-1.5 rounded-lg transition-colors"
              style="color: var(--text-muted);"
              aria-label="Logout"
              title="Logout"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
              </svg>
            </button>
          </div>
        </div>
      </aside>

      <!-- Main -->
      <main class="flex-1 flex flex-col overflow-hidden">
        <header class="px-6 py-3 flex items-center justify-between border-b" style="background: var(--bg-card); border-color: var(--border);">
          <div class="flex items-center space-x-4">
            <h2 class="text-sm font-heading font-semibold" style="color: var(--text-secondary);">
              {{ currentPageTitle }}
            </h2>
          </div>
          <div class="flex items-center space-x-4">
            <span class="flex items-center space-x-1.5 text-sm">
              <span
                class="w-2 h-2 rounded-full"
                :style="wsConnected ? 'background: #00E5C8; box-shadow: 0 0 6px #00E5C8;' : 'background: #9A7FA0;'"
              />
              <span :style="wsConnected ? 'color: #00E5C8;' : 'color: var(--text-muted);'">
                {{ wsConnected ? 'Live' : 'Offline' }}
              </span>
            </span>

            <span v-if="usageStore.isNearLimit" class="dct-pill-pink">Budget Alert</span>
          </div>
        </header>

        <div class="flex-1 overflow-auto" style="background: var(--bg);">
          <RouterView />
        </div>
      </main>
    </div>

    <CommandBar />
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth.js'
import { useUsageStore } from './stores/usage.js'
import { useWebSocket } from './composables/useWebSocket.js'
import { useAgentsStore } from './stores/agents.js'
import { useFlowsStore } from './stores/flows.js'
import CommandBar          from './components/CommandBar.vue'
import RoleBadge           from './components/security/RoleBadge.vue'
import ImpersonationBanner from './components/impersonation/ImpersonationBanner.vue'

const route       = useRoute()
const router      = useRouter()
const authStore   = useAuthStore()
const usageStore  = useUsageStore()
const agentsStore = useAgentsStore()
const flowsStore  = useFlowsStore()

// ---------------------------------------------------------------------------
// Role-aware navigation models
// ---------------------------------------------------------------------------

const userNav = [
  {
    name: 'Dashboard', path: '/', exact: true,
    icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" /></svg>',
  },
  { name: 'Atlas',        path: '/atlas',        icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" /></svg>' },
  { name: 'Agents',       path: '/agents',       icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>' },
  { name: 'Flows',        path: '/flows',        icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" /></svg>' },
  { name: 'Approvals',    path: '/approvals',    icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>' },
  { name: 'Traces',       path: '/traces',       icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10" /></svg>' },
  { name: 'Usage',        path: '/usage',        icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3v18h18M8 17l4-4 4 4 4-8" /></svg>' },
  { name: 'Memory',       path: '/memory',       icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>' },
  { name: 'Settings',     path: '/settings',     icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /></svg>' },
]

const adminNav = [
  { name: 'Overview',  path: '/admin',         exact: true, icon: userNav[0].icon, capability: 'tenant.view' },
  { name: 'Users',     path: '/admin/users',   icon: userNav[2].icon, capability: 'users.manage' },
  { name: 'Budget',    path: '/admin/budget',  icon: userNav[6].icon, capability: 'budget.edit' },
  { name: 'Copy',      path: '/admin/copy',    icon: userNav[1].icon, capability: 'copy.manage' },
  { name: 'Audit',     path: '/admin/audit',   icon: userNav[5].icon, capability: 'audit.view' },
]

const platformNav = [
  { name: 'Overview',       path: '/platform',                    exact: true, icon: userNav[0].icon },
  { name: 'Feature Flags',  path: '/platform/feature-flags',      icon: userNav[8].icon, capability: 'flag.write' },
  { name: 'Rollout v2',     path: '/platform/rollouts/usage-v2',  icon: userNav[6].icon, capability: 'rollout.cutover' },
  { name: 'STE',            path: '/platform/ste',                icon: userNav[5].icon, capability: 'ste.view' },
]

// ---------------------------------------------------------------------------
// Derived navigation per active workspace
// ---------------------------------------------------------------------------

const currentWorkspace = computed(() => {
  if (route.path.startsWith('/platform')) return '/platform'
  if (route.path.startsWith('/admin'))    return '/admin'
  return '/'
})

const workspaceLabel = computed(() => ({
  '/platform': 'Platform',
  '/admin':    'Admin',
  '/':         'Workspace',
}[currentWorkspace.value] || 'Workspace'))

const visibleNavigation = computed(() => {
  let nav = userNav
  if (currentWorkspace.value === '/admin')    nav = adminNav
  if (currentWorkspace.value === '/platform') nav = platformNav
  return nav.filter((item) => !item.capability || authStore.has(item.capability))
})

function switchWorkspace(path) {
  router.push(path)
}

// ---------------------------------------------------------------------------
// Header title
// ---------------------------------------------------------------------------

const currentPageTitle = computed(() => {
  const all  = [...userNav, ...adminNav, ...platformNav]
  const item = all.find((n) => isActiveRoute(n.path, n.exact))
  return item?.name || 'SpiderNet OS'
})

function isActiveRoute(path, exact = false) {
  if (exact) return route.path === path
  return route.path === path || route.path.startsWith(path + '/')
}

const userInitials = computed(() => {
  const name = authStore.user?.name || ''
  return name.split(' ').map((n) => n[0]).join('').toUpperCase().slice(0, 2)
})

const { isConnected: wsConnected } = useWebSocket(authStore, agentsStore, flowsStore, usageStore)

function logout() {
  authStore.logout()
  router.push('/login')
}

onMounted(() => {
  if (authStore.isAuthenticated) {
    usageStore.fetchBudget()
    usageStore.fetchCurrentSpend()
  }
})
</script>
