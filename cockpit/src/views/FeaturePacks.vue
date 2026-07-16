<template>
  <div class="px-6 py-7 max-w-6xl mx-auto" data-testid="feature-packs-page">
    <div class="mb-7">
      <div class="sn-eyebrow">Build · Vertical packs</div>
      <h1 class="text-2xl font-semibold tracking-tight mt-1" style="color: var(--text-primary);">
        Feature pack registry
      </h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">
        Packs grow with your business — outcomes and recommendations adapt from your industry, usage, and feedback.
      </p>
      <p v-if="profilePct > 0" class="text-xs mt-2" style="color: var(--text-muted);">
        Business profile {{ profilePct }}% complete — Atlas uses this to tailor packs.
      </p>
    </div>

    <div v-if="recommendations.length" class="mb-6 sn-card p-4 border" style="border-color: var(--accent);">
      <h2 class="text-sm font-semibold mb-2" style="color: var(--accent);">Recommended for you</h2>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="rec in recommendations"
          :key="rec.pack_id"
          type="button"
          class="text-left text-sm px-3 py-2 rounded-lg border"
          style="border-color: var(--border); color: var(--text-primary); background: var(--surface-low);"
          @click="scrollToPack(rec.pack_id)"
        >
          <span class="font-medium">{{ rec.display_name }}</span>
          <span class="block text-xs mt-0.5" style="color: var(--text-muted);">{{ rec.growth_reason }}</span>
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-sm" style="color: var(--text-muted);">Loading catalogue…</div>
    <div v-else-if="error" class="sn-card p-4 text-sm" style="color: var(--danger);">{{ error }}</div>

    <div v-else class="grid md:grid-cols-2 gap-4">
      <div
        v-for="pack in catalogue"
        :key="pack.pack_id"
        :id="'pack-' + pack.pack_id"
        class="sn-card p-5 flex flex-col"
        :class="{ 'ring-1': pack.recommended }"
        :style="pack.recommended ? { ringColor: 'var(--accent)' } : {}"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2 flex-wrap">
              <h3 class="font-medium" style="color: var(--text-primary);">{{ pack.display_name }}</h3>
              <span v-if="pack.recommended" class="sn-pill shrink-0" style="background: var(--accent); color: #05070A;">Recommended</span>
            </div>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ pack.description }}</p>
            <p v-if="pack.growth_reason" class="text-xs mt-2" style="color: var(--text-muted);">{{ pack.growth_reason }}</p>
            <div class="mono text-xs mt-2" style="color: var(--text-muted);">
              {{ pack.vertical }} · v{{ pack.version }}
              <span v-if="pack.relevance_score != null"> · fit {{ pack.relevance_score }}%</span>
            </div>
          </div>
          <span class="sn-pill sn-pill-success shrink-0" v-if="isInstalled(pack.pack_id)">Installed</span>
          <span class="sn-pill shrink-0" v-else-if="pack.pricing && !pack.entitled">{{ formatPrice(pack.pricing) }}</span>
          <span class="sn-pill shrink-0" v-else>Available</span>
        </div>

        <ul v-if="pack.customer_outcomes?.length" class="mt-4 space-y-1.5 text-sm" style="color: var(--text-secondary);">
          <li v-for="(outcome, i) in pack.customer_outcomes" :key="i" class="flex gap-2 items-start justify-between">
            <span class="flex gap-2 min-w-0">
              <span style="color: var(--accent);">✓</span>
              <span>{{ outcome }}</span>
            </span>
            <span class="flex gap-1 shrink-0 ml-2">
              <button type="button" class="text-xs opacity-60 hover:opacity-100" title="Helpful" @click="sendFeedback(pack.pack_id, 'positive', outcome)">👍</button>
              <button type="button" class="text-xs opacity-60 hover:opacity-100" title="Not for us" @click="sendFeedback(pack.pack_id, 'negative', outcome)">👎</button>
            </span>
          </li>
        </ul>

        <div class="mt-4 flex flex-wrap gap-2">
          <button
            v-if="!isInstalled(pack.pack_id) && pack.pricing && !pack.entitled"
            type="button"
            class="sn-btn-primary text-sm px-4 py-2 rounded-lg disabled:opacity-50"
            :disabled="installing === pack.pack_id"
            @click="checkoutPack(pack)"
          >
            {{ installing === pack.pack_id ? 'Starting checkout…' : `Buy ${formatPrice(pack.pricing)}` }}
          </button>
          <button
            v-else-if="!isInstalled(pack.pack_id)"
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
        <p v-if="feedbackMessage[pack.pack_id]" class="text-xs mt-1" style="color: var(--text-muted);">
          {{ feedbackMessage[pack.pack_id] }}
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api.js'

const router = useRouter()
const route = useRoute()
const loading = ref(true)
const error = ref(null)
const catalogue = ref([])
const installed = ref([])
const recommendations = ref([])
const profilePct = ref(0)
const installing = ref(null)
const installMessage = ref({})
const feedbackMessage = ref({})
const viewedPacks = new Set()

function isInstalled(packId) {
  return installed.value.some((p) => p.pack_id === packId)
}

function scrollToPack(packId) {
  document.getElementById('pack-' + packId)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

async function recordView(packId) {
  if (!packId || viewedPacks.has(packId)) return
  viewedPacks.add(packId)
  try {
    await api.post('/api/feature-packs/signals', {
      signal_type: 'pack_view',
      pack_id: packId,
    })
  } catch (_) {
    viewedPacks.delete(packId)
  }
}

async function sendFeedback(packId, sentiment, outcome) {
  feedbackMessage.value[packId] = 'Thanks — we will tailor packs to your feedback.'
  try {
    await api.post('/api/feature-packs/feedback', {
      pack_id: packId,
      sentiment,
      outcome,
      note: sentiment === 'negative' ? `Not relevant: ${outcome}` : undefined,
    })
    await load()
  } catch (_) {
    feedbackMessage.value[packId] = 'Could not save feedback.'
  }
}

async function load({ recordViews = false } = {}) {
  loading.value = true
  error.value = null
  try {
    const [cat, inst, rec] = await Promise.all([
      api.get('/api/feature-packs/catalogue'),
      api.get('/api/feature-packs'),
      api.get('/api/feature-packs/recommendations'),
    ])
    catalogue.value = cat.data?.data || cat.data || []
    profilePct.value = cat.data?.meta?.profile_pct ?? 0
    installed.value = inst.data?.data || inst.data || []
    recommendations.value = rec.data?.data || rec.data || []
    if (recordViews) {
      catalogue.value.forEach((p) => recordView(p.pack_id))
    }
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

function formatPrice(pricing) {
  if (!pricing) return ''
  const amount = Number(pricing.amount || 0)
  const currency = pricing.currency || 'USD'
  return `${amount % 1 === 0 ? amount : amount.toFixed(2)} ${currency}`
}

async function checkoutPack(pack) {
  installing.value = pack.pack_id
  installMessage.value[pack.pack_id] = ''
  try {
    const { data } = await api.post(`/api/feature-packs/${pack.pack_id}/checkout`)
    const checkoutUrl = data?.data?.checkout_url
    if (checkoutUrl) {
      window.location.href = checkoutUrl
    } else {
      installMessage.value[pack.pack_id] = 'Could not start checkout — try again shortly.'
      installing.value = null
    }
  } catch (e) {
    installMessage.value[pack.pack_id] = e.response?.data?.message || 'Could not start checkout.'
    installing.value = null
  }
}

async function handlePurchaseReturn() {
  const { purchase, pack: packId } = route.query
  if (purchase === 'cancelled') {
    installMessage.value[packId] = 'Checkout cancelled — no charge was made.'
  } else if (purchase === 'success' && packId) {
    installMessage.value[packId] = 'Payment received — activating your pack…'
    await load()
    try {
      const { data } = await api.post(`/api/feature-packs/${packId}/install`)
      const result = data?.data || data
      installMessage.value[packId] = `Installed — ${result.agents_provisioned ?? 0} agent(s) ready.`
      await load()
      if (result.entry_path) {
        setTimeout(() => router.push(result.entry_path), 800)
      }
    } catch (e) {
      // Webhook may not have landed yet — the pack still shows as
      // purchasable and the owner can retry the install button.
      installMessage.value[packId] = e.response?.data?.message || 'Payment received — finishing setup, refresh in a moment.'
    }
  }
}

onMounted(async () => {
  await load({ recordViews: true })
  if (route.query.purchase) {
    await handlePurchaseReturn()
  }
})
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
.sn-pill {
  font-size: 10px;
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--surface-low);
  color: var(--text-secondary);
}
.sn-pill-success {
  background: rgba(0, 229, 200, 0.15);
  color: var(--accent);
}
</style>
