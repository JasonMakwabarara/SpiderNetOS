<template>
  <div class="px-6 py-7 max-w-6xl mx-auto" data-testid="skill-card-page">
    <div class="mb-6">
      <div class="sn-eyebrow">Build · Skills · Card</div>
      <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);" data-testid="skill-card-title">{{ title }}</h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">
        The full-screen skill card — what it is, breaks into, builds on, replaces, pipeline and your role — arrives in the next slice.
      </p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()

const title = computed(() => {
  const slug = String(route.params.slug || 'skill')
  return slug.replace(/[-_]+/g, ' ').replace(/^\w/, (c) => c.toUpperCase())
})
</script>
