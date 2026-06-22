<template>
  <div class="min-h-screen flex items-center justify-center px-6" data-testid="login-redirect">
    <p class="text-sm" style="color: var(--text-secondary);">Redirecting to Cockpit sign-in…</p>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()

function landingSignInUrl() {
  const raw = typeof route.query.return_to === 'string' ? route.query.return_to : ''
  const hashPath = raw && raw.startsWith('/') ? raw : '#/'
  const returnTo = hashPath.startsWith('/cockpit')
    ? hashPath
    : `/cockpit/#${hashPath.replace(/^\//, '')}`
  return `/sign-in?return_to=${encodeURIComponent(returnTo)}`
}

onMounted(() => {
  window.location.replace(landingSignInUrl())
})
</script>
