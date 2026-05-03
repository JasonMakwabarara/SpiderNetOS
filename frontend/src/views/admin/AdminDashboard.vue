<template>
  <div class="p-6 space-y-6">
    <header class="flex items-start justify-between">
      <div>
        <p class="text-xs uppercase tracking-wider text-gray-500">Admin workspace</p>
        <h1 class="text-2xl font-bold">Tenant overview</h1>
        <p class="text-sm text-gray-600 mt-1">
          <template v-if="authStore.tenant?.name">
            {{ authStore.tenant.name }}
          </template>
        </p>
      </div>
      <RoleBadge :role="authStore.role" />
    </header>

    <!-- Headline KPIs -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
      <div class="bg-white p-4 rounded-lg shadow-sm border">
        <p class="text-xs text-gray-500">Users</p>
        <p class="text-2xl font-bold">{{ kpis.users }}</p>
      </div>
      <div class="bg-white p-4 rounded-lg shadow-sm border">
        <p class="text-xs text-gray-500">Admins</p>
        <p class="text-2xl font-bold">{{ kpis.admins }}</p>
      </div>
      <div class="bg-white p-4 rounded-lg shadow-sm border">
        <p class="text-xs text-gray-500">Month-to-date</p>
        <p class="text-2xl font-bold">${{ kpis.spendMtd.toFixed(2) }}</p>
      </div>
      <div class="bg-white p-4 rounded-lg shadow-sm border">
        <p class="text-xs text-gray-500">Alerts open</p>
        <p class="text-2xl font-bold">{{ kpis.alerts }}</p>
      </div>
      <div class="bg-white p-4 rounded-lg shadow-sm border">
        <p class="text-xs text-gray-500">Success rate</p>
        <p class="text-2xl font-bold">{{ kpis.successRate }}%</p>
      </div>
    </div>

    <!-- Recent audit -->
    <section class="bg-white rounded-lg shadow-sm border">
      <div class="flex items-center justify-between p-4 border-b">
        <h2 class="font-semibold text-sm">Recent audit events</h2>
        <RouterLink to="/admin/audit" class="text-xs text-indigo-600">View all →</RouterLink>
      </div>
      <EmptyState v-if="!recentAudit.length" title="Clean slate" description="No admin activity recorded yet." />
      <ul v-else class="divide-y">
        <li v-for="e in recentAudit" :key="e.id" class="p-3 flex items-center gap-3 text-sm">
          <span class="font-mono text-xs text-gray-500">{{ formatTime(e.occurred_at) }}</span>
          <span class="font-medium">{{ e.actor_email }}</span>
          <span class="text-gray-500">{{ e.event_type }}</span>
          <span class="ml-auto text-xs text-gray-400">{{ e.target_id }}</span>
        </li>
      </ul>
    </section>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useAuthStore } from '../../stores/auth.js'
import RoleBadge  from '../../components/security/RoleBadge.vue'
import EmptyState from '../../components/data/EmptyState.vue'

const authStore = useAuthStore()

const kpis = ref({
  users:       0,
  admins:      0,
  spendMtd:    0,
  alerts:      0,
  successRate: 0,
})

const recentAudit = ref([])

function formatTime(iso) {
  try { return new Date(iso).toLocaleString() } catch { return iso }
}

onMounted(() => {
  // Best-effort KPI seeding — backend endpoint may not be live yet.
  // The view is resilient to empty data.
})
</script>
