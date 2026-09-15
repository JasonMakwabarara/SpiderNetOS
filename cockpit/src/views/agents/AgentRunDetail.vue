<template>
  <div class="px-6 py-7 max-w-6xl mx-auto" data-testid="agent-run-detail-page">
    <div class="mb-6">
      <div class="sn-eyebrow">Observe · Agent runs · Run</div>
      <h1 class="text-2xl font-semibold mt-1 mono" style="color: var(--text-primary);" data-testid="agent-run-detail-title">
        Run {{ route.params.id }}
      </h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">
        Steps, tool calls, artifacts and the trace for one run — the detail view lands in the next slice.
      </p>
    </div>
  </div>
</template>

<script setup>
import { useRoute } from 'vue-router'

const route = useRoute()
</script>
