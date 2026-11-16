<template>
  <nav v-if="embedded" class="flex flex-wrap items-center gap-3 text-sm" data-testid="weft-nav">
    <span class="font-semibold" :style="{ color: 'var(--text-primary)' }">Weft HR</span>
    <router-link
      v-for="item in items"
      :key="item.to"
      :to="item.to"
      :data-testid="`weft-nav-${item.key}`"
      :style="{ color: route.path.startsWith(item.to) ? 'var(--charge-vivid)' : 'var(--text-secondary)' }"
    >{{ item.label }}</router-link>
  </nav>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useProduct } from '../../composables/useProduct.js'

const route = useRoute()
const { isWeftApp } = useProduct()
const embedded = computed(() => route.path.startsWith('/weft') && !isWeftApp.value)
const items = [
  { key: 'people', label: 'People', to: '/weft/people' },
  { key: 'attendance', label: 'Attendance', to: '/weft/attendance' },
  { key: 'leave', label: 'Leave', to: '/weft/leave' },
  { key: 'payroll', label: 'Payroll', to: '/weft/payroll' },
  { key: 'me', label: 'My record', to: '/weft/me' },
]
</script>
