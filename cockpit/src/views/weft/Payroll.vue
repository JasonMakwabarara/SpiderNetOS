<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }" data-testid="weft-payroll">
    <WeftSubnav />
    <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Payroll</h1>
    <p class="text-sm" :style="{ color: 'var(--text-muted)' }">Sample statutory rates are editable. They are not a statement of current law.</p>

    <form class="dct-card p-4 grid gap-3 md:grid-cols-3" @submit.prevent="saveRule">
      <input v-model="rule.name" required placeholder="Rule name" class="px-3 py-2 rounded border" :style="field" data-testid="rule-name" />
      <input v-model="rule.currency" required class="px-3 py-2 rounded border" :style="field" data-testid="rule-currency" />
      <input v-model="rule.effective_from" type="date" required class="px-3 py-2 rounded border" :style="field" data-testid="rule-effective" />
      <label class="text-sm" :style="{ color: 'var(--text-secondary)' }">PAYE rate above the first band
        <input v-model.number="rule.paye_rate" type="number" min="0" max="1" step="0.01" class="w-full px-3 py-2 rounded border" :style="field" data-testid="rule-paye" />
      </label>
      <label class="text-sm" :style="{ color: 'var(--text-secondary)' }">AIDS levy on PAYE
        <input v-model.number="rule.aids_levy_rate" type="number" min="0" max="1" step="0.01" class="w-full px-3 py-2 rounded border" :style="field" />
      </label>
      <label class="text-sm" :style="{ color: 'var(--text-secondary)' }">NSSA ceiling
        <input v-model.number="rule.nssa_ceiling" type="number" min="0" step="0.01" class="w-full px-3 py-2 rounded border" :style="field" />
      </label>
      <button class="dct-btn-primary px-4 py-2 text-sm" data-testid="rule-save">Save rule</button>
    </form>

    <form class="dct-card p-4 grid gap-3 md:grid-cols-4" @submit.prevent="openRun">
      <input v-model="runForm.period_start" type="date" required class="px-3 py-2 rounded border" :style="field" data-testid="pay-start" />
      <input v-model="runForm.period_end" type="date" required class="px-3 py-2 rounded border" :style="field" data-testid="pay-end" />
      <input v-model="runForm.currency" required class="px-3 py-2 rounded border" :style="field" data-testid="pay-currency" />
      <button class="dct-btn-primary px-4 py-2 text-sm" data-testid="pay-open">Open pay run</button>
    </form>
    <p v-if="message" class="text-sm" data-testid="payroll-message">{{ message }}</p>

    <article v-if="current" class="dct-card p-4 space-y-3" data-testid="pay-run">
      <p class="text-sm" :style="{ color: 'var(--text-primary)' }">{{ current.status }} · {{ current.currency }}</p>
      <template v-if="isWeftApp">
        <p v-for="site in current.unsynced_sites || []" :key="site.id" class="text-sm" data-testid="unsynced-site" :style="{ color: 'var(--text-secondary)' }">
          {{ site.name }} has not synced since the period ended. Last sync {{ site.last_synced_at || 'never' }}.
        </p>
      </template>
      <div class="flex flex-wrap gap-2">
        <button class="dct-btn-primary px-3 py-1 text-sm" data-testid="pay-calculate" @click="act('calculate')">Calculate</button>
        <button class="px-3 py-1 text-sm" data-testid="pay-submit" @click="act('submit')">Submit</button>
        <button class="px-3 py-1 text-sm" data-testid="pay-approve" @click="act('approve')">Approve</button>
        <button class="px-3 py-1 text-sm" data-testid="pay-post" @click="act('post')">Post</button>
      </div>
      <p v-for="slip in current.payslips || []" :key="slip.id" class="text-sm" data-testid="payslip" :style="{ color: 'var(--text-primary)' }">
        {{ slip.employee?.name }} · gross {{ slip.gross }} · net {{ slip.net }}
      </p>
    </article>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import WeftSubnav from '../../components/weft/WeftSubnav.vue'
import { useProduct } from '../../composables/useProduct.js'
import api from '../../services/api.js'

const { isWeftApp } = useProduct()

const field = { background: 'var(--surface-low)', color: 'var(--text-primary)', borderColor: 'var(--border)' }
const message = ref('')
const current = ref(null)
const rule = ref({
  name: 'Sample Zimbabwe',
  currency: 'USD',
  effective_from: '2026-01-01',
  paye_rate: 0.2,
  aids_levy_rate: 0.03,
  nssa_ceiling: 700,
})
const runForm = ref({ period_start: '', period_end: '', currency: 'USD' })

async function saveRule() {
  message.value = ''
  try {
    await api.post('/api/enterprise/payroll/rules', {
      name: rule.value.name,
      currency: rule.value.currency,
      effective_from: rule.value.effective_from,
      paye_bands: [
        { up_to: 300, rate: 0 },
        { up_to: null, rate: rule.value.paye_rate },
      ],
      aids_levy_rate: rule.value.aids_levy_rate,
      nssa_employee_rate: 0.045,
      nssa_employer_rate: 0.045,
      nssa_ceiling: rule.value.nssa_ceiling,
      nec_rate: 0,
      union_dues: 0,
    })
    message.value = 'Statutory rule saved.'
  } catch (err) {
    message.value = err.response?.data?.message || 'Could not save the rule.'
  }
}

async function openRun() {
  message.value = ''
  try {
    const res = await api.post('/api/enterprise/payroll/runs', runForm.value)
    current.value = res.data.data
  } catch (err) {
    message.value = err.response?.data?.message || 'Could not open the pay run.'
  }
}

async function act(step) {
  message.value = ''
  try {
    const res = await api.post(`/api/enterprise/payroll/runs/${current.value.id}/${step}`)
    current.value = res.data.data
  } catch (err) {
    message.value = err.response?.data?.message || 'Could not update the pay run.'
  }
}

onMounted(async () => {
  const rows = (await api.get('/api/enterprise/payroll/runs')).data.data || []
  if (rows[0]) {
    current.value = (await api.get(`/api/enterprise/payroll/runs/${rows[0].id}`)).data.data
  }
})
</script>
