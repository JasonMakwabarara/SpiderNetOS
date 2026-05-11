<template>
  <div class="financial-portfolios p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Portfolios</h1>
        <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }">Investment portfolio management and trading</p>
      </div>
      <button @click="showNew = true" class="dct-btn-primary px-4 py-2 text-sm">+ New Portfolio</button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div v-for="pf in portfolios" :key="pf.id" class="dct-card p-5 space-y-3" :style="{ background: 'var(--surface-low)' }">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">{{ pf.name }}</h3>
          <span :class="riskPill(pf.risk_level)">{{ pf.risk_level }}</span>
        </div>
        <p class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">${{ formatNumber(pf.total_value) }}</p>
        <div class="flex gap-4">
          <p class="text-sm" :style="{ color: parseFloat(pf.unrealized_pnl) >= 0 ? 'var(--charge-vivid)' : 'var(--dusk-vivid)' }">
            Unrealized: {{ parseFloat(pf.unrealized_pnl) >= 0 ? '+' : '' }}${{ formatNumber(pf.unrealized_pnl) }}
          </p>
          <p class="text-sm" :style="{ color: parseFloat(pf.realized_pnl) >= 0 ? 'var(--charge-vivid)' : 'var(--dusk-vivid)' }">
            Realized: {{ parseFloat(pf.realized_pnl) >= 0 ? '+' : '' }}${{ formatNumber(pf.realized_pnl) }}
          </p>
        </div>
        <router-link :to="`/financial/portfolios/${pf.id}`" class="text-sm font-medium" :style="{ color: 'var(--charge-vivid)' }">View Details →</router-link>
      </div>
    </div>

    <div v-if="showNew" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="dct-card p-6 w-full max-w-md space-y-4" :style="{ background: 'var(--bg)' }">
        <h3 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">New Portfolio</h3>
        <input v-model="form.name" placeholder="Portfolio name" class="w-full px-3 py-2 rounded-lg border" :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }" />
        <select v-model="form.risk_level" class="w-full px-3 py-2 rounded-lg border" :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }">
          <option value="low">Low Risk</option>
          <option value="medium">Medium Risk</option>
          <option value="high">High Risk</option>
          <option value="aggressive">Aggressive</option>
        </select>
        <div class="flex gap-3 justify-end">
          <button @click="showNew = false" class="px-4 py-2 rounded-lg text-sm" :style="{ color: 'var(--text-secondary)', background: 'var(--surface-low)' }">Cancel</button>
          <button @click="createPortfolio" class="dct-btn-primary px-4 py-2 text-sm">Create</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../services/api.js'

const portfolios = ref([])
const showNew = ref(false)
const form = ref({ name: '', risk_level: 'medium' })

function formatNumber(n) { return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }
function riskPill(r) { return `px-2 py-0.5 rounded-full text-xs font-semibold ${r === 'low' ? 'dct-pill-lime' : r === 'medium' ? 'dct-pill-cyan' : r === 'high' ? 'dct-pill-pink' : 'dct-pill-pink'}` }

async function createPortfolio() {
  await api.post('/api/financial/portfolios', form.value)
  showNew.value = false
  form.value = { name: '', risk_level: 'medium' }
  loadPortfolios()
}

async function loadPortfolios() {
  const res = await api.get('/api/financial/portfolios')
  portfolios.value = res.data.data?.data || []
}

onMounted(() => { loadPortfolios() })
</script>
