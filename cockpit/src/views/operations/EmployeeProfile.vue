<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }" data-testid="employee-profile">
    <WeftSubnav />
    <router-link v-if="!isWeft" to="/operations/people" class="text-sm" :style="{ color: 'var(--charge-vivid)' }">People</router-link>
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
        <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">Employment
          <select v-model="edit.employment_type" class="w-full px-3 py-2 rounded border" :style="field" data-testid="profile-employment-type">
            <option value="permanent">Permanent</option>
            <option value="casual">Casual</option>
          </select>
        </label>
        <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">Pay basis
          <select v-model="edit.pay_basis" class="w-full px-3 py-2 rounded border" :style="field" data-testid="profile-pay-basis">
            <option value="monthly">Monthly</option>
            <option value="daily">Daily</option>
            <option value="hourly">Hourly</option>
          </select>
        </label>
        <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">Pay rate
          <input v-model="edit.pay_rate" type="number" min="0" step="0.01" class="w-full px-3 py-2 rounded border" :style="field" data-testid="profile-pay-rate" />
        </label>
        <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">Currency
          <input v-model="edit.pay_currency" class="w-full px-3 py-2 rounded border" :style="field" data-testid="profile-pay-currency" />
        </label>
        <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">National ID
          <input v-model="edit.national_id" class="w-full px-3 py-2 rounded border" :style="field" data-testid="profile-national-id" />
        </label>
        <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">NSSA number
          <input v-model="edit.nssa_number" class="w-full px-3 py-2 rounded border" :style="field" data-testid="profile-nssa" />
        </label>
        <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">Reports to
          <select v-model="edit.reports_to" class="w-full px-3 py-2 rounded border" :style="field" data-testid="profile-reports-to">
            <option value="">None</option>
            <option v-for="person in people" :key="person.id" :value="person.id">{{ person.name }}</option>
          </select>
        </label>
        <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">Linked user id
          <input v-model="edit.user_id" class="w-full px-3 py-2 rounded border" :style="field" data-testid="profile-user-id" />
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
      <p class="text-sm" :style="{ color: 'var(--text-muted)' }">Recorded clock events. Payable hours are on the Weft attendance report.</p>
      <p v-for="event in attendance" :key="event.id" class="text-sm" :style="{ color: 'var(--text-primary)' }">
        {{ formatWhen(event.recorded_at) }} {{ event.type.toUpperCase() }}
      </p>
      <p v-if="!attendance.length" class="text-sm" :style="{ color: 'var(--text-muted)' }">No clock events recorded.</p>
    </section>

    <section v-else-if="active === 'leave'" class="space-y-3" data-testid="panel-leave">
      <form class="dct-card p-4 grid gap-3 md:grid-cols-2" @submit.prevent="requestLeave">
        <select v-model="leaveForm.leave_type_id" required class="px-3 py-2 rounded border" :style="field" data-testid="profile-leave-type">
          <option value="">Leave type</option>
          <option v-for="type in leaveTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
        </select>
        <input v-model="leaveForm.starts_on" type="date" required class="px-3 py-2 rounded border" :style="field" data-testid="profile-leave-start" />
        <input v-model="leaveForm.ends_on" type="date" required class="px-3 py-2 rounded border" :style="field" data-testid="profile-leave-end" />
        <button class="dct-btn-primary px-4 py-2 text-sm" data-testid="profile-leave-submit">Request leave</button>
      </form>
      <p v-for="row in leave" :key="row.id" class="text-sm" :style="{ color: 'var(--text-primary)' }">
        {{ row.leave_type?.name || row.leaveType?.name }} · {{ row.starts_on }} to {{ row.ends_on }} · {{ row.status }}
      </p>
      <p v-if="!leave.length" class="text-sm" :style="{ color: 'var(--text-muted)' }">No leave requests yet.</p>
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
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import WeftSubnav from '../../components/weft/WeftSubnav.vue'
import api from '../../services/api.js'

const route = useRoute()
const isWeft = computed(() => route.path.startsWith('/weft'))
const field = { background: 'var(--bg)', color: 'var(--text-primary)', borderColor: 'var(--border)' }
const activeTab = { background: 'var(--accent-weak)', color: 'var(--accent)' }
const idleTab = { color: 'var(--text-muted)' }
const tabs = ['overview', 'attendance', 'leave', 'assets', 'activity']
const active = ref('overview')
const employee = ref(null)
const attendance = ref([])
const leave = ref([])
const leaveTypes = ref([])
const people = ref([])
const assets = ref([])
const activity = ref([])
const departments = ref([])
const message = ref('')
const createdNotice = ref(route.query.created || '')
const edit = ref({})
const deactivation = ref({ inactive_from: '', reason: '' })
const leaveForm = ref({ leave_type_id: '', starts_on: '', ends_on: '' })

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
    employment_type: row.employment_type || 'permanent',
    pay_basis: row.pay_basis || 'monthly',
    pay_rate: row.pay_rate || '0.00',
    pay_currency: row.pay_currency || 'USD',
    national_id: row.national_id || '',
    nssa_number: row.nssa_number || '',
    reports_to: row.reports_to || '',
    user_id: row.user_id || '',
  }
}

async function load() {
  const res = await api.get(`/api/enterprise/employees/${route.params.id}`)
  employee.value = res.data.data
  attendance.value = res.data.attendance || []
  leave.value = res.data.leave || []
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
      employment_type: edit.value.employment_type,
      pay_basis: edit.value.pay_basis,
      pay_rate: edit.value.pay_rate || 0,
      pay_currency: edit.value.pay_currency,
      national_id: edit.value.national_id || null,
      nssa_number: edit.value.nssa_number || null,
      reports_to: edit.value.reports_to || null,
      user_id: edit.value.user_id || null,
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

async function requestLeave() {
  message.value = ''
  try {
    await api.post('/api/enterprise/leave/requests', {
      employee_id: route.params.id,
      leave_type_id: leaveForm.value.leave_type_id,
      starts_on: leaveForm.value.starts_on,
      ends_on: leaveForm.value.ends_on,
    })
    await load()
    message.value = 'Leave requested.'
  } catch (err) {
    message.value = err.response?.data?.message || 'Could not request leave.'
  }
}

onMounted(async () => {
  departments.value = (await api.get('/api/enterprise/departments')).data.data || []
  people.value = (await api.get('/api/enterprise/employees', { params: { status: 'all' } })).data.data || []
  leaveTypes.value = (await api.get('/api/enterprise/leave/types')).data.data || []
  await load()
})
</script>
