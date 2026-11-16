<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <WeftSubnav />
    <router-link v-if="!route.path.startsWith('/weft')" to="/operations" class="text-sm" :style="{ color: 'var(--charge-vivid)' }">Operations</router-link>
    <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Attendance</h1>

    <section v-if="isWeftApp" class="dct-card p-4 space-y-3" data-testid="weft-sites">
      <h2 class="font-semibold" :style="{ color: 'var(--text-primary)' }">Jetson sites</h2>
      <form class="flex gap-2" @submit.prevent="createSite">
        <input v-model="siteName" required placeholder="Site name" class="px-3 py-2 rounded border" :style="field" data-testid="site-name" />
        <button class="dct-btn-primary px-4 py-2 text-sm" data-testid="site-create">Add site</button>
      </form>
      <p v-if="newSecret" class="text-sm" data-testid="site-secret" :style="{ color: 'var(--text-secondary)' }">
        Site secret, shown once: {{ newSecret }}
      </p>
      <p v-for="site in report.sites || []" :key="site.id" class="text-sm" data-testid="site-sync" :style="{ color: 'var(--text-primary)' }">
        {{ site.name }} · last sync {{ site.last_synced_at || 'never' }}
      </p>
    </section>

    <form v-if="isWeftApp" class="dct-card p-4 grid gap-3 md:grid-cols-3" data-testid="jetson-sync-form" @submit.prevent="syncJetson">
      <input v-model="syncForm.site_id" required placeholder="Site id" class="px-3 py-2 rounded border" :style="field" data-testid="sync-site-id" />
      <input v-model="syncForm.secret" required placeholder="Site secret" class="px-3 py-2 rounded border" :style="field" data-testid="sync-secret" />
      <input v-model="syncForm.jetson_person_id" required placeholder="Jetson person id" class="px-3 py-2 rounded border" :style="field" data-testid="sync-person-id" />
      <input v-model="syncForm.first_name" required placeholder="First name" class="px-3 py-2 rounded border" :style="field" data-testid="sync-first-name" />
      <input v-model="syncForm.surname" required placeholder="Surname" class="px-3 py-2 rounded border" :style="field" data-testid="sync-surname" />
      <input v-model="syncForm.external_device_id" required placeholder="Device id" class="px-3 py-2 rounded border" :style="field" data-testid="sync-device-id" />
      <button class="dct-btn-primary px-4 py-2 text-sm" data-testid="sync-submit">Sync register</button>
    </form>

    <form class="dct-card p-4 grid gap-3 md:grid-cols-4" @submit.prevent="create">
      <input v-model="form.employee_id" required placeholder="Employee id" class="px-3 py-2 rounded border" :style="field" data-testid="manual-employee-id" />
      <select v-model="form.type" class="px-3 py-2 rounded border" :style="field">
        <option value="in">Clock in</option>
        <option value="out">Clock out</option>
      </select>
      <input v-model="form.recorded_at" type="datetime-local" class="px-3 py-2 rounded border" :style="field" data-testid="manual-recorded-at" />
      <button class="dct-btn-primary px-4 py-2 text-sm" data-testid="manual-clock">Record</button>
    </form>
    <p v-if="message" class="text-sm" data-testid="attendance-message">{{ message }}</p>

    <section class="space-y-2" data-testid="attendance-report">
      <h2 class="font-semibold" :style="{ color: 'var(--text-primary)' }">Who is in</h2>
      <p v-for="row in report.present || []" :key="row.employee_id" class="text-sm" :style="{ color: 'var(--text-primary)' }">
        {{ row.name }} · {{ row.employee_number }}
      </p>
      <p v-if="!(report.present || []).length" class="text-sm" :style="{ color: 'var(--text-muted)' }">Nobody is currently clocked in.</p>
      <h2 class="font-semibold pt-2" :style="{ color: 'var(--text-primary)' }">Hours</h2>
      <p v-for="row in report.totals || []" :key="row.employee_id" class="text-sm" data-testid="hours-row" :style="{ color: 'var(--text-primary)' }">
        {{ row.name || row.employee_id }} · {{ row.payable_minutes }} minutes · overtime {{ row.overtime_minutes }} · unpaired {{ row.missing_out }}
      </p>
    </section>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import WeftSubnav from '../../components/weft/WeftSubnav.vue'
import { useProduct } from '../../composables/useProduct.js'
import api from '../../services/api.js'

const route = useRoute()
const { isWeftApp } = useProduct()
const field = { background: 'var(--surface-low)', color: 'var(--text-primary)', borderColor: 'var(--border)' }
const report = ref({})
const message = ref('')
const siteName = ref('')
const newSecret = ref('')
const form = ref({ employee_id: '', type: 'in', recorded_at: '' })
const syncForm = ref({
  site_id: '',
  secret: '',
  jetson_person_id: '',
  first_name: '',
  surname: '',
  external_device_id: '',
})

async function load() {
  const today = new Date().toISOString().slice(0, 10)
  try {
    report.value = (await api.get('/api/enterprise/attendance/report', { params: { from: today, to: today } })).data.data || {}
  } catch {
    report.value = { present: [], totals: [], sites: [] }
  }
}

async function createSite() {
  const res = await api.post('/api/enterprise/attendance/sites', { name: siteName.value })
  newSecret.value = res.data.secret
  syncForm.value.site_id = res.data.data.id
  syncForm.value.secret = res.data.secret
  siteName.value = ''
  await load()
}

async function syncJetson() {
  message.value = ''
  try {
    await api.post('/api/enterprise/attendance/sync', {
      caught_up: true,
      people: [{
        jetson_person_id: syncForm.value.jetson_person_id,
        first_name: syncForm.value.first_name,
        surname: syncForm.value.surname,
      }],
      devices: [{
        external_device_id: syncForm.value.external_device_id,
        jetson_person_id: syncForm.value.jetson_person_id,
      }],
    }, {
      headers: {
        'X-Jetson-Site': syncForm.value.site_id,
        'X-Jetson-Secret': syncForm.value.secret,
      },
    })
    message.value = 'Register synced.'
    await load()
  } catch (err) {
    message.value = err.response?.data?.message || 'Could not sync the register.'
  }
}

async function create() {
  message.value = ''
  try {
    await api.post('/api/enterprise/clock-events', {
      employee_id: form.value.employee_id,
      type: form.value.type,
      recorded_at: form.value.recorded_at || null,
    })
    message.value = 'Clock event recorded.'
    await load()
  } catch (err) {
    message.value = err.response?.data?.message || 'Could not record the clock event.'
  }
}

onMounted(load)
</script>
