<template>
  <div class="p-6 space-y-6 max-w-3xl" data-testid="weft-settings">
    <header>
      <h1 class="text-2xl font-heading font-semibold" :style="{ color: 'var(--text-primary)' }">Settings</h1>
      <p class="text-sm mt-1" :style="{ color: 'var(--text-muted)' }">
        Appearance is shared with your account. Midnight Harbour and Copper Ledger remember their own light or dark mode. Solarpunk stays sunlit.
      </p>
    </header>

    <section class="sn-card p-4 space-y-3">
      <h2 class="text-sm font-semibold" :style="{ color: 'var(--text-primary)' }">Role</h2>
      <div class="flex items-center gap-3">
        <RoleBadge :role="auth.role" />
        <span class="text-sm" :style="{ color: 'var(--text-secondary)' }">{{ auth.user?.email }}</span>
      </div>
    </section>

    <section class="sn-card p-4">
      <AppearancePicker variant="settings" />
    </section>
  </div>
</template>

<script setup>
import AppearancePicker from '../../components/AppearancePicker.vue'
import RoleBadge from '../../components/security/RoleBadge.vue'
import { useAuthStore } from '../../stores/auth.js'

const auth = useAuthStore()
</script>
