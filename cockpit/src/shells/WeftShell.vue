<template>
  <div class="min-h-screen flex flex-col" style="background: var(--bg);" data-testid="weft-shell">
    <ImpersonationBanner />
    <header
      class="sn-shell-header flex items-center justify-between px-4 h-12 border-b sticky top-0 z-30"
      data-testid="weft-top-bar"
    >
      <div class="flex items-center gap-3 min-w-0">
        <span class="font-heading font-semibold tracking-tight text-[15px]" style="color: var(--text-primary);">Weft HR</span>
        <span class="h-5 w-px" style="background: var(--border);"></span>
        <span class="text-sm truncate" style="color: var(--text-secondary);">{{ auth.tenant?.name || 'Workspace' }}</span>
      </div>
      <div class="relative">
        <button
          class="flex items-center gap-2 px-1.5 py-1 rounded-md"
          data-testid="weft-user-menu"
          @click="open = !open"
        >
          <span
            class="w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-semibold"
            style="background: var(--accent-weak); color: var(--accent); border: 1px solid var(--color-border-strong);"
          >{{ initials }}</span>
          <span class="text-sm hidden sm:inline" style="color: var(--text-primary);">{{ auth.user?.name }}</span>
        </button>
        <div v-if="open" class="absolute right-0 top-full mt-1 w-72 rounded-lg sn-panel py-1.5 z-40" data-testid="weft-user-menu-panel">
          <div class="px-3 py-2 border-b" style="border-color: var(--border);">
            <div class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ auth.user?.email }}</div>
            <div class="mt-1"><RoleBadge :role="auth.role" /></div>
          </div>
          <div class="px-3 py-2 border-b" style="border-color: var(--border);">
            <div class="text-[10px] tracking-widest uppercase font-semibold mb-1.5" style="color: var(--text-muted);">Role</div>
            <div class="grid grid-cols-3 gap-1">
              <button
                v-for="r in roles"
                :key="r.value"
                type="button"
                class="px-2 py-1 text-[11px] rounded-md"
                :style="auth.role === r.value
                  ? 'background: var(--accent-weak); color: var(--accent); border:1px solid var(--color-border-strong);'
                  : 'background: var(--bg-elevated); color: var(--text-secondary); border:1px solid var(--border);'"
                @click="auth.switchRole(r.value); open = false"
              >{{ r.label }}</button>
            </div>
          </div>
          <RouterLink to="/weft/settings" class="block px-3 py-1.5 text-sm" style="color: var(--text-secondary);" @click="open = false">Settings</RouterLink>
          <div class="px-3 py-2 border-b" style="border-color: var(--border);">
            <AppearancePicker />
          </div>
          <button type="button" class="w-full text-left px-3 py-1.5 text-sm" style="color: var(--danger);" @click="logout">Sign out</button>
        </div>
      </div>
    </header>

    <div class="flex flex-1 min-h-0">
      <aside
        class="sn-rail shrink-0 flex flex-col border-r"
        :style="{ width: 'var(--sidebar-width)', background: 'var(--color-bg-shell)', borderColor: 'var(--color-border-default)' }"
        data-testid="weft-sidebar"
      >
        <nav class="flex-1 px-3 pt-3 pb-3 space-y-0.5 overflow-y-auto" aria-label="Weft HR">
          <div class="sn-nav-group-label pt-0">Weft HR</div>
          <RouterLink
            v-for="item in items"
            :key="item.to"
            :to="item.to"
            class="sn-nav-link"
            :class="{ active: route.path === item.to || route.path.startsWith(item.to + '/') }"
            :data-testid="`weft-nav-${item.key}`"
          >{{ item.label }}</RouterLink>
        </nav>
      </aside>
      <main class="sn-canvas flex-1 min-w-0 overflow-auto relative">
        <div class="sn-atmosphere" aria-hidden="true"></div>
        <div class="relative z-[1]">
          <RouterView />
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppearancePicker from '../components/AppearancePicker.vue'
import RoleBadge from '../components/security/RoleBadge.vue'
import ImpersonationBanner from '../components/impersonation/ImpersonationBanner.vue'
import { useAuthStore } from '../stores/auth.js'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const open = ref(false)
const roles = [
  { value: 'user', label: 'User' },
  { value: 'admin', label: 'Admin' },
  { value: 'super_admin', label: 'Super' },
]

const items = computed(() => {
  const mine = { key: 'me', label: 'My record', to: '/weft/me' }
  const settings = { key: 'settings', label: 'Settings', to: '/weft/settings' }
  if (!['admin', 'super_admin'].includes(auth.role)) return [mine, settings]
  return [
    { key: 'people', label: 'People', to: '/weft/people' },
    { key: 'attendance', label: 'Attendance', to: '/weft/attendance' },
    { key: 'leave', label: 'Leave', to: '/weft/leave' },
    { key: 'payroll', label: 'Payroll', to: '/weft/payroll' },
    mine,
    settings,
  ]
})

const initials = computed(() => {
  const name = auth.user?.name || 'WH'
  return name.split(' ').map((part) => part[0]).join('').toUpperCase().slice(0, 2)
})

function logout() {
  auth.logout()
  router.push('/login')
}

watch(() => route.path, () => {
  open.value = false
})
</script>
