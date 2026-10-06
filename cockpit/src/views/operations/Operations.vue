<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }" data-testid="operations-hub">
    <div>
      <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Operations</h1>
      <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }">
        People and purchasing for this tenant.
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
    </div>
  </div>
</template>
