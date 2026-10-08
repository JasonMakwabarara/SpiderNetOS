<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }" data-testid="operations-hub">
    <div>
      <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Operations</h1>
      <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }">
        People, purchasing, attendance and assets for this tenant.
      </p>
    </div>
    <div class="grid gap-4 md:grid-cols-2 max-w-3xl">
      <router-link
        to="/operations/people"
        class="dct-card p-5 block"
        :style="{ background: 'var(--surface-low)' }"
        data-testid="ops-card-employees"
      >
        <p class="text-sm font-medium" :style="{ color: 'var(--text-secondary)' }">People</p>
        <p class="text-xs mt-1" :style="{ color: 'var(--text-muted)' }">Employees, positions, and the change log.</p>
      </router-link>
      <router-link
        to="/operations/purchasing"
        class="dct-card p-5 block"
        :style="{ background: 'var(--surface-low)' }"
        data-testid="ops-card-purchasing"
      >
        <p class="text-sm font-medium" :style="{ color: 'var(--text-secondary)' }">Purchasing</p>
        <p class="text-xs mt-1" :style="{ color: 'var(--text-muted)' }">Requisitions approved for procurement, then purchase orders.</p>
      </router-link>
      <router-link
        to="/operations/attendance"
        class="dct-card p-5 block"
        :style="{ background: 'var(--surface-low)' }"
        data-testid="ops-card-attendance"
      >
        <p class="text-sm font-medium" :style="{ color: 'var(--text-secondary)' }">Attendance</p>
        <p class="text-xs mt-1" :style="{ color: 'var(--text-muted)' }">Clock-in and clock-out events.</p>
      </router-link>
      <router-link
        to="/operations/assets"
        class="dct-card p-5 block"
        :style="{ background: 'var(--surface-low)' }"
        data-testid="ops-card-assets"
      >
        <p class="text-sm font-medium" :style="{ color: 'var(--text-secondary)' }">Assets</p>
        <p class="text-xs mt-1" :style="{ color: 'var(--text-muted)' }">The asset register and who holds what.</p>
      </router-link>
    </div>
  </div>
</template>
