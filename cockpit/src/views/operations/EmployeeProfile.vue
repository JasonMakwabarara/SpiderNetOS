<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }" data-testid="employee-profile">
    <router-link to="/operations/people" class="text-sm" :style="{ color: 'var(--charge-vivid)' }">People</router-link>
    <p v-if="createdNotice" class="text-sm" data-testid="employee-created-notice" :style="{ color: 'var(--text-secondary)' }">
      Employee {{ createdNotice }} created.
    </p>
    <div v-if="employee">
      <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">{{ employee.name }}</h1>
      <p class="text-sm" :style="{ color: 'var(--text-muted)' }">{{ employee.employee_number }} · {{ employee.status }}</p>
    </div>
    <p v-if="message" class="text-sm" :style="{ color: 'var(--text-secondary)' }">{{ message }}</p>

    <div class="flex gap-2 text-sm">
      <button v-for="tab in tabs" :key="tab" class="px-3 py-1 rounded" :data-testid="`tab-${tab}`" :style="active === tab ? activeTab : idleTab" @click="active = tab">{{ tab }}</button>
    </div>

    <section v-if="active === 'overview' && employee" class="dct-card p-4 space-y-3" :style="{ background: 'var(--surface-low)' }">
      <form class="grid gap-3 md:grid-cols-2" @submit.prevent="save">
        <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">First name
          <input v-model="edit.first_name" required class="w-full px-3 py-2 rounded border" :style="field" data-testid="profile-first-name" />
        </label>
        <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">Surname
          <input v-model="edit.surname" required class="w-full px-3 py-2 rounded border" :style="field" data-testid="profile-surname" />
        </label>
        <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">Position
          <input v-model="edit.position_title" required class="w-full px-3 py-2 rounded border" :style="field" data-testid="profile-position" />
        </label>
        <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">Department
          <select v-model="edit.department_id" class="w-full px-3 py-2 rounded border" :style="field">
            <option value="">None</option>
            <option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option>
          </select>
        </label>
        <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">Start date
          <input v-model="edit.start_date" type="date" class="w-full px-3 py-2 rounded border" :style="field" />
        </label>
        <p class="text-sm" :style="{ color: 'var(--text-secondary)' }">Employee number<br><strong :style="{ color: 'var(--text-primary)' }">{{ employee.employee_number }}</strong></p>
        <label class="text-sm space-y-1 md:col-span-2" :style="{ color: 'var(--text-secondary)' }">Job description
          <textarea v-model="edit.job_description" rows="4" class="w-full px-3 py-2 rounded border" :style="field" data-testid="profile-job-description" />
        </label>
        <button class="dct-btn-primary px-4 py-2 text-sm w-fit" data-testid="employee-save">Save</button>
      </form>
      <form v-if="employee.status === 'active'" class="grid gap-3 md:grid-cols-3 border-t pt-3" @submit.prevent="deactivate">
        <input v-model="deactivation.inactive_from" type="date" class="px-3 py-2 rounded border" :style="field" data-testid="deactivate-date" />
        <input v-model="deactivation.reason" placeholder="Reason" class="px-3 py-2 rounded border" :style="field" data-testid="deactivate-reason" />
        <button class="px-4 py-2 text-sm" data-testid="employee-deactivate">Deactivate employee</button>
      </form>
    </section>

    <section v-else-if="active === 'attendance'" class="space-y-2" data-testid="panel-attendance">
      <p class="text-sm" :style="{ color: 'var(--text-muted)' }">Hours and punctuality are not calculated yet. These are the recorded clock events.</p>
      <p v-for="event in attendance" :key="event.id" class="text-sm" :style="{ color: 'var(--text-primary)' }">
        {{ formatWhen(event.recorded_at) }} {{ event.type.toUpperCase() }}
      </p>
      <p v-if="!attendance.length" class="text-sm" :style="{ color: 'var(--text-muted)' }">No clock events recorded.</p>
    </section>

    <section v-else-if="active === 'leave'" data-testid="panel-leave">
      <p class="text-sm" :style="{ color: 'var(--text-muted)' }">Not yet available. Leave requests, balances and approvals will be available in the HR workflow expansion.</p>
    </section>

    <section v-else-if="active === 'assets'" class="space-y-2" data-testid="panel-assets">
      <p v-if="employee?.status === 'inactive' && assets.length" class="text-sm" :style="{ color: 'var(--text-secondary)' }">
        This inactive employee still has assigned assets.
      </p>
      <p v-for="row in assets" :key="row.id" class="text-sm" :style="{ color: 'var(--text-primary)' }">
        {{ row.asset?.tag }} · {{ row.asset?.name }}
      </p>
      <p v-if="!assets.length" class="text-sm" :style="{ color: 'var(--text-muted)' }">No assets currently assigned.</p>
    </section>

    <section v-else class="space-y-3" data-testid="panel-activity">
      <button class="text-sm" :style="{ color: 'var(--charge-vivid)' }" data-testid="activity-export" @click="exportCsv">Export CSV</button>
      <div v-for="row in activity" :key="row.id" class="text-sm" :style="{ color: 'var(--text-primary)' }">
        <span :style="{ color: 'var(--text-muted)' }">{{ formatWhen(row.created_at) }}</span>
        {{ row.event }}<span v-if="row.field"> · {{ row.field }}</span>
        <span v-if="row.previous_value || row.new_value"> · {{ row.previous_value || '—' }} → {{ row.new_value || '—' }}</span>
      </div>
    </section>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../services/api.js'

const route = useRoute()
const field = { background: 'var(--bg)', color: 'var(--text-primary)', borderColor: 'var(--border)' }
const activeTab = { background: 'var(--accent-weak)', color: 'var(--accent)' }
const idleTab = { color: 'var(--text-muted)' }
const tabs = ['overview', 'attendance', 'leave', 'assets', 'activity']
const active = ref('overview')
const employee = ref(null)
const attendance = ref([])
const assets = ref([])
const activity = ref([])
const departments = ref([])
const message = ref('')
const createdNotice = ref(route.query.created || '')
const edit = ref({})
const deactivation = ref({ inactive_from: '', reason: '' })

function formatWhen(value) {
  if (!value) return ''
  return new Date(value).toLocaleString()
}

function fillEdit(row) {
  edit.value = {
    first_name: row.first_name,
    surname: row.surname,
    position_title: row.position_title,
    department_id: row.department_id || '',
    start_date: row.start_date ? String(row.start_date).slice(0, 10) : '',
    job_description: row.job_description || '',
  }
}

async function load() {
  const res = await api.get(`/api/enterprise/employees/${route.params.id}`)
  employee.value = res.data.data
  attendance.value = res.data.attendance || []
  assets.value = res.data.assets || []
  activity.value = res.data.activity || []
  fillEdit(employee.value)
}

async function save() {
  message.value = ''
  try {
    await api.patch(`/api/enterprise/employees/${route.params.id}`, {
      ...edit.value,
      department_id: edit.value.department_id || null,
      start_date: edit.value.start_date || null,
    })
    await load()
    message.value = 'Employee updated.'
  } catch (err) {
    message.value = err.response?.data?.message || 'Could not update the employee.'
  }
}

async function deactivate() {
  message.value = ''
  try {
    await api.post(`/api/enterprise/employees/${route.params.id}/deactivate`, {
      inactive_from: deactivation.value.inactive_from || null,
      reason: deactivation.value.reason || null,
    })
    await load()
  } catch (err) {
    message.value = err.response?.data?.message || 'Could not deactivate the employee.'
  }
}

async function exportCsv() {
  const res = await api.get(`/api/enterprise/employees/${route.params.id}/audit.csv`, { responseType: 'blob' })
  const url = URL.createObjectURL(res.data)
  const link = document.createElement('a')
  link.href = url
  link.download = `${employee.value?.employee_number || 'employee'}-audit.csv`
  link.click()
  URL.revokeObjectURL(url)
}

onMounted(async () => {
  departments.value = (await api.get('/api/enterprise/departments')).data.data || []
  await load()
})
</script>
