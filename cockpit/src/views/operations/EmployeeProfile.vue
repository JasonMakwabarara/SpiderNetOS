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
      <form class="flex flex-wrap gap-2 border-t pt-3" @submit.prevent="saveContract">
        <input v-model="contract.starts_on" type="date" required class="px-3 py-2 rounded border" :style="field" data-testid="contract-start" />
        <input v-model="contract.pay_amount" type="number" min="0.01" step="0.01" required class="px-3 py-2 rounded border" :style="field" data-testid="contract-pay" />
        <select v-model="contract.pay_period" class="px-3 py-2 rounded border" :style="field" data-testid="contract-period">
          <option value="month">Month</option>
          <option value="hour">Hour</option>
        </select>
        <button class="px-3 py-2 text-sm rounded border" :style="field" data-testid="contract-save">Activate contract</button>
      </form>
      <p v-if="contractStatus" class="text-sm" data-testid="contract-status" :style="{ color: 'var(--text-secondary)' }">{{ contractStatus }}</p>
      <form v-if="employee.status === 'active'" class="grid gap-3 md:grid-cols-3 border-t pt-3" @submit.prevent="deactivate">
        <input v-model="deactivation.inactive_from" type="date" class="px-3 py-2 rounded border" :style="field" data-testid="deactivate-date" />
        <input v-model="deactivation.reason" placeholder="Reason" class="px-3 py-2 rounded border" :style="field" data-testid="deactivate-reason" />
        <button class="px-4 py-2 text-sm" data-testid="employee-deactivate">Deactivate employee</button>
      </form>
    </section>

    <section v-else-if="active === 'attendance'" class="space-y-3" data-testid="panel-attendance">
      <p v-if="workforceError" class="text-sm" data-testid="workforce-error" :style="{ color: 'var(--text-secondary)' }">{{ workforceError }}</p>
      <div class="flex flex-wrap gap-2">
        <button class="px-3 py-2 text-sm rounded border" :style="field" data-testid="clock-in" @click="clock('in')">Clock in</button>
        <button class="px-3 py-2 text-sm rounded border" :style="field" data-testid="clock-out" @click="clock('out')">Clock out</button>
        <input v-model="workDate" type="date" class="px-3 py-2 rounded border" :style="field" data-testid="close-date" />
        <button class="dct-btn-primary px-3 py-2 text-sm" data-testid="close-day" @click="closeDay">Close day</button>
        <button class="px-3 py-2 text-sm rounded border" :style="field" data-testid="shift-day" @click="useDayShift">Assign day shift</button>
      </div>
      <p v-for="day in attendanceDays" :key="day.id" class="text-sm" data-testid="attendance-day" :style="{ color: 'var(--text-primary)' }">
        {{ String(day.work_date).slice(0, 10) }} · {{ day.punctuality }} · {{ day.minutes }} minutes
      </p>
      <p v-for="event in attendance" :key="event.id" class="text-sm" :style="{ color: 'var(--text-muted)' }">
        {{ formatWhen(event.recorded_at) }} {{ event.type.toUpperCase() }}
      </p>
      <p v-if="!attendance.length" class="text-sm" :style="{ color: 'var(--text-muted)' }">No clock events recorded.</p>
    </section>

    <section v-else-if="active === 'leave'" class="space-y-3" data-testid="panel-leave">
      <p v-if="workforceError" class="text-sm" data-testid="workforce-error" :style="{ color: 'var(--text-secondary)' }">{{ workforceError }}</p>
      <form class="flex flex-wrap gap-2" @submit.prevent="requestLeave">
        <select v-model="leave.leave_type" class="px-3 py-2 rounded border" :style="field" data-testid="leave-type">
          <option value="annual">Annual</option>
          <option value="sick">Sick</option>
          <option value="unpaid">Unpaid</option>
        </select>
        <input v-model="leave.starts_on" type="date" required class="px-3 py-2 rounded border" :style="field" data-testid="leave-start" />
        <input v-model="leave.ends_on" type="date" required class="px-3 py-2 rounded border" :style="field" data-testid="leave-end" />
        <button class="dct-btn-primary px-3 py-2 text-sm" data-testid="leave-submit">Request leave</button>
      </form>
      <p v-for="row in leaveRows" :key="row.id" class="text-sm" data-testid="leave-row" :style="{ color: 'var(--text-primary)' }">
        {{ row.leave_type }} · {{ String(row.starts_on).slice(0, 10) }} · {{ row.status }}
      </p>
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
const attendanceDays = ref([])
const leaveRows = ref([])
const assets = ref([])
const workforceError = ref('')
const workDate = ref(new Date().toISOString().slice(0, 10))
const leave = ref({ leave_type: 'annual', starts_on: '', ends_on: '' })
const contract = ref({ starts_on: new Date().toISOString().slice(0, 10), pay_amount: 1, pay_period: 'month' })
const contractStatus = ref('')
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
  attendanceDays.value = res.data.attendance_days || []
  leaveRows.value = res.data.leave || []
  const openContract = (res.data.contracts || []).find(row => row.status === 'active')
  contractStatus.value = openContract ? `${openContract.contract_number} · ${openContract.status}` : ''
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

async function saveContract() {
  message.value = ''
  try {
    const created = await api.post(`/api/enterprise/employees/${route.params.id}/contracts`, {
      starts_on: contract.value.starts_on,
      pay_amount: Number(contract.value.pay_amount),
      currency: 'USD',
      pay_period: contract.value.pay_period,
    })
    await api.post(`/api/enterprise/contracts/${created.data.data.id}/activate`)
    await load()
  } catch (err) {
    message.value = err.response?.data?.message || 'Could not activate the contract.'
  }
}

async function useDayShift() {
  workforceError.value = ''
  try {
    const shift = await api.post('/api/enterprise/shifts', {
      name: 'Day',
      starts_at: '08:00',
      ends_at: '17:00',
      grace_minutes: 15,
    })
    await api.post(`/api/enterprise/employees/${route.params.id}/shift`, {
      shift_id: shift.data.data.id,
      effective_from: workDate.value,
    })
    await load()
  } catch (err) {
    workforceError.value = err.response?.data?.message || 'Could not assign the shift.'
  }
}

async function clock(type) {
  workforceError.value = ''
  try {
    await api.post('/api/enterprise/clock-events', { employee_id: route.params.id, type })
    await load()
  } catch (err) {
    workforceError.value = err.response?.data?.message || 'Could not record the clock event.'
  }
}

async function closeDay() {
  workforceError.value = ''
  try {
    await api.post(`/api/enterprise/employees/${route.params.id}/attendance-days`, { work_date: workDate.value })
    await load()
  } catch (err) {
    workforceError.value = err.response?.data?.message || 'Could not close the day.'
  }
}

async function requestLeave() {
  workforceError.value = ''
  try {
    const created = await api.post(`/api/enterprise/employees/${route.params.id}/leave`, leave.value)
    await api.post(`/api/enterprise/leave-requests/${created.data.data.id}/submit`)
    await load()
  } catch (err) {
    workforceError.value = err.response?.data?.message || 'Could not request leave.'
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
