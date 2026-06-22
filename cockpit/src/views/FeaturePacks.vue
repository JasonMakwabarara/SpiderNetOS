<template>
  <div class="px-6 py-7 max-w-6xl mx-auto" data-testid="feature-packs-page">
    <div class="mb-7">
      <div class="sn-eyebrow">Build · Vertical packs</div>
      <h1 class="text-2xl font-semibold tracking-tight mt-1" style="color: var(--text-primary);">
        Feature pack registry
      </h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">
        Start with one automation, then expand. Install vertical bundles — agents, flows, and operating rules — without custom engineering.
      </p>
      <p class="text-xs mt-2" style="color: var(--text-muted);">
        Platform subscription and AI usage are under
        <RouterLink to="/billing" class="underline" style="color: var(--accent);">Billing</RouterLink>.
        Business finance (invoices, ledger) is the separate <strong>Financial OS</strong> pack below.
      </p>
    </div>

    <div v-if="loading" class="text-sm" style="color: var(--text-muted);">Loading catalogue…</div>
    <div v-else-if="error" class="sn-card p-4 text-sm" style="color: var(--danger);">{{ error }}</div>

    <div v-else class="grid md:grid-cols-2 gap-4">
      <div v-for="pack in catalogue" :key="pack.pack_id" class="sn-card p-5 flex flex-col">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0 flex-1">
            <h3 class="font-medium" style="color: var(--text-primary);">{{ pack.display_name }}</h3>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ pack.description }}</p>
            <div class="mono text-xs mt-2" style="color: var(--text-muted);">
              {{ pack.vertical }} · v{{ pack.version }}
              <span v-if="pack.agent_count"> · {{ pack.agent_count }} agents</span>
              <span v-if="pack.flow_count"> · {{ pack.flow_count }} flows</span>
            </div>
          </div>
          <span class="sn-pill sn-pill-success shrink-0" v-if="isInstalled(pack.pack_id)">Installed</span>
          <span class="sn-pill shrink-0" v-else>Available</span>
        </div>

        <ul v-if="pack.customer_outcomes?.length" class="mt-4 space-y-1.5 text-sm" style="color: var(--text-secondary);">
          <li v-for="(outcome, i) in pack.customer_outcomes" :key="i" class="flex gap-2">
            <span style="color: var(--accent);">✓</span>
            <span>{{ outcome }}</span>
          </li>
        </ul>

        <div class="mt-4 flex flex-wrap gap-2">
          <button
            v-if="!isInstalled(pack.pack_id)"
            type="button"
            class="sn-btn-primary text-sm px-4 py-2 rounded-lg disabled:opacity-50"
            :disabled="installing === pack.pack_id"
            @click="installPack(pack)"
          >
            {{ installing === pack.pack_id ? 'Installing…' : 'Install pack' }}
          </button>
          <RouterLink
            v-else
            :to="pack.entry_path || '/feature-packs'"
            class="sn-btn-primary text-sm px-4 py-2 rounded-lg inline-block text-center"
          >
            Open {{ pack.display_name }}
          </RouterLink>
        </div>
        <p v-if="installMessage[pack.pack_id]" class="text-xs mt-2" style="color: var(--accent);">
          {{ installMessage[pack.pack_id] }}
        </p>
      </div>
    </div>

    <div v-if="installed.length" class="mt-8">
      <h2 class="font-medium mb-3" style="color: var(--text-primary);">Installed on this tenant</h2>
      <div class="grid md:grid-cols-2 gap-4">
        <div v-for="pack in installed" :key="pack.id" class="sn-card p-4 text-sm flex justify-between items-center">
          <div>
            <div style="color: var(--text-primary);">{{ pack.display_name }}</div>
            <div class="mono text-xs mt-1" style="color: var(--text-muted);">{{ pack.status }} · {{ pack.pack_id }}</div>
          </div>
          <RouterLink v-if="pack.entry_path" :to="pack.entry_path" class="text-xs underline" style="color: var(--accent);">Open</RouterLink>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api.js'

const router = useRouter()
const loading = ref(true)
const error = ref(null)
const catalogue = ref([])
const installed = ref([])
const installing = ref(null)
const installMessage = ref({})

function isInstalled(packId) {
  return installed.value.some((p) => p.pack_id === packId)
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const [cat, inst] = await Promise.all([
      api.get('/api/feature-packs/catalogue'),
      api.get('/api/feature-packs'),
    ])
    catalogue.value = cat.data?.data || cat.data || []
    installed.value = inst.data?.data || inst.data || []
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to load feature packs.'
  } finally {
    loading.value = false
  }
}

async function installPack(pack) {
  installing.value = pack.pack_id
  installMessage.value[pack.pack_id] = ''
  try {
    const { data } = await api.post(`/api/feature-packs/${pack.pack_id}/install`)
    const result = data?.data || data
    installMessage.value[pack.pack_id] = `Installed — ${result.agents_provisioned ?? 0} agent(s) ready.`
    await load()
    if (result.entry_path) {
      setTimeout(() => router.push(result.entry_path), 800)
    }
  } catch (e) {
    installMessage.value[pack.pack_id] = e.response?.data?.message || 'Install failed.'
  } finally {
    installing.value = null
  }
}

onMounted(load)
</script>

<style scoped>
.sn-eyebrow {
  font-family: 'JetBrains Mono', ui-monospace, monospace;
  font-size: 11px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: var(--accent);
}
.sn-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 12px;
}
.sn-btn-primary {
  background: linear-gradient(135deg, #00E5C8, #087D6E);
  color: #05070A;
  font-weight: 600;
}
</style>
