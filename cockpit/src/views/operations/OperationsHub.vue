<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }" data-testid="operations-hub">
    <div>
      <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Operations</h1>
      <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }">
        People, purchasing, assets, and finance operations for this tenant.
      </p>
    </div>

    <p v-if="error" class="text-sm" :style="{ color: 'var(--dusk-vivid)' }">{{ error }}</p>

    <section v-for="group in visibleGroups" :key="group.title" class="space-y-3">
      <h2 class="text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">{{ group.title }}</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <router-link
          v-for="card in group.cards"
          :key="card.to"
          :to="card.to"
          class="dct-card p-5 block"
          :style="{ background: 'var(--surface-low)' }"
          :data-testid="`ops-card-${card.key}`"
        >
          <p class="text-sm font-medium" :style="{ color: 'var(--text-secondary)' }">{{ card.label }}</p>
          <p class="text-2xl font-bold mt-2" :style="{ color: 'var(--text-primary)' }">{{ counts[card.count] ?? '—' }}</p>
          <p class="text-xs mt-1" :style="{ color: 'var(--text-muted)' }">{{ card.hint }}</p>
        </router-link>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../services/api.js'
import { useAuthStore } from '../../stores/auth.js'

const auth = useAuthStore()
const counts = ref({})
const error = ref('')
const visibleGroups = computed(() => groups
  .map((group) => ({
    ...group,
    cards: group.cards.filter((card) => card.key !== 'employees' || auth.isAdmin),
  }))
  .filter((group) => group.cards.length))

const groups = [
  {
    title: 'People',
    cards: [
      { key: 'employees', label: 'Employees', to: '/operations/people', count: 'employees_active', hint: 'Active employees' },
      { key: 'attendance', label: 'Attendance', to: '/operations/attendance', count: 'clock_events', hint: 'Clock events recorded' },
    ],
  },
  {
    title: 'Purchasing',
    cards: [
      { key: 'requisitions', label: 'Requisitions', to: '/operations/requisitions', count: 'requisitions_submitted', hint: 'Awaiting approval' },
      { key: 'procurement', label: 'Purchase orders', to: '/operations/procurement', count: 'purchase_orders_open', hint: 'Draft orders' },
    ],
  },
  {
    title: 'Assets',
    cards: [
      { key: 'assets', label: 'Asset register', to: '/operations/assets', count: 'assets_unassigned', hint: 'Unassigned assets' },
    ],
  },
  {
    title: 'Finance operations',
    cards: [
      { key: 'billing', label: 'Credit notes', to: '/operations/billing', count: 'invoices_open', hint: 'Open invoices' },
      { key: 'cash', label: 'Cash', to: '/operations/cash', count: 'cashbooks', hint: 'Cashbooks' },
      { key: 'fiscal', label: 'Fiscalisation', to: '/operations/fiscal', count: 'fiscal_pending', hint: 'Pending fiscalisation' },
    ],
  },
]

onMounted(async () => {
  try {
    const res = await api.get('/api/enterprise/overview')
    counts.value = res.data.data || {}
  } catch (err) {
    error.value = err.response?.data?.message || 'Counts are unavailable until the API is signed in.'
  }
})
</script>
