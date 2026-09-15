<template>
  <div class="px-6 py-7 max-w-6xl mx-auto" data-testid="agent-workspace-page">
    <div class="mb-6">
      <div class="sn-eyebrow">Build · Agents · Workspace</div>
      <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);" data-testid="agent-workspace-title">
        {{ title }} workspace
      </h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">
        Where this agent runs on the shared brain context — the workspace view lands in the next slice.
      </p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()

const title = computed(() => {
  const slug = String(route.params.slug || 'agent')
  return slug.replace(/[-_]+/g, ' ').replace(/^\w/, (c) => c.toUpperCase())
})
</script>
