<template>
  <div class="min-h-[70vh] flex items-center justify-center p-6">
    <div class="max-w-md text-center">
      <p class="text-5xl font-bold text-amber-500 mb-2">403</p>
      <h1 class="text-2xl font-semibold mb-2">Access restricted</h1>
      <p class="text-sm text-gray-500 mb-4">
        You don’t have permission for this area yet.
        <span v-if="requiredRoles">Required role: <code>{{ requiredRoles }}</code>.</span>
        <span v-if="requiredCapability">Missing capability: <code>{{ requiredCapability }}</code>.</span>
      </p>
      <div class="flex justify-center gap-2">
        <RouterLink to="/" class="px-4 py-2 rounded bg-indigo-600 text-white text-sm">Go to dashboard</RouterLink>
        <button class="px-4 py-2 rounded border text-sm" @click="$router.back()">Back</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const requiredRoles      = computed(() => route.query.required_roles || '')
const requiredCapability = computed(() => route.query.required_capability || '')
</script>
