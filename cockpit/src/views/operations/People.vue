<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }" data-testid="ops-people">
    <router-link to="/operations" class="text-sm" :style="{ color: 'var(--charge-vivid)' }">Operations</router-link>
    <div class="flex items-center justify-between gap-4">
      <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">People</h1>
      <button class="dct-btn-primary px-4 py-2 text-sm" data-testid="employee-add-toggle" @click="showForm = !showForm">Add employee</button>
    </div>

    <form v-if="showForm" class="dct-card p-4 grid gap-3 md:grid-cols-2" data-testid="employee-form" @submit.prevent="create">
      <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">
        First name *
        <input v-model="form.first_name" required class="w-full px-3 py-2 rounded border" :style="field" data-testid="employee-first-name" />
      </label>
      <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">
        Surname *
        <input v-model="form.surname" required class="w-full px-3 py-2 rounded border" :style="field" data-testid="employee-surname" />
      </label>
      <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">
        Position *
        <input v-model="form.position_title" required class="w-full px-3 py-2 rounded border" :style="field" data-testid="employee-position" @blur="onPositionBlur" />
      </label>
      <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">
        Department
        <select v-model="form.department_id" class="w-full px-3 py-2 rounded border" :style="field" data-testid="employee-department">
          <option value="">None</option>
          <option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option>
        </select>
      </label>
      <div class="text-sm md:col-span-2" :style="{ color: 'var(--text-secondary)' }" data-testid="employee-number-preview">
        Next employee number: approximately {{ preview || '…' }}
        <span class="block text-xs" :style="{ color: 'var(--text-muted)' }">Final number is assigned when the employee is saved.</span>
      </div>
      <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">
        Start date
        <input v-model="form.start_date" type="date" class="w-full px-3 py-2 rounded border" :style="field" data-testid="employee-start-date" />
      </label>
      <div class="md:col-span-2 space-y-1">
        <div class="flex items-center justify-between">
          <span class="text-sm" :style="{ color: 'var(--text-secondary)' }">Job description</span>
          <button type="button" class="text-xs" :style="{ color: 'var(--charge-vivid)' }" data-testid="job-description-regenerate" @click="regenerate">Regenerate suggestion</button>
        </div>
        <textarea v-model="form.job_description" rows="4" class="w-full px-3 py-2 rounded border" :style="field" data-testid="employee-job-description" @input="onDescriptionInput" />
        <p class="text-xs" :style="{ color: 'var(--text-muted)' }">Suggested from position. Editable.</p>
      </div>
      <div class="flex gap-2">
        <button class="dct-btn-primary px-4 py-2 text-sm" data-testid="employee-create">Add employee</button>
        <button type="button" class="px-4 py-2 text-sm" @click="showForm = false">Cancel</button>
      </div>
    </form>

    <section class="dct-card p-4 space-y-2" data-testid="payroll-run">
      <p class="text-sm font-medium" :style="{ color: 'var(--text-primary)' }">Payroll</p>
      <p v-if="payrollError" class="text-sm" data-testid="payroll-error" :style="{ color: 'var(--text-secondary)' }">{{ payrollError }}</p>
      <p v-if="payroll" class="text-sm" data-testid="payroll-status" :style="{ color: 'var(--text-secondary)' }">{{ payroll.status }}</p>
      <form class="flex flex-wrap gap-2" @submit.prevent="draftPayroll">
        <input v-model="payrollForm.period_start" type="date" required class="px-3 py-2 rounded border" :style="field" data-testid="payroll-start" />
        <input v-model="payrollForm.period_end" type="date" required class="px-3 py-2 rounded border" :style="field" data-testid="payroll-end" />
        <button class="dct-btn-primary px-3 py-2 text-sm" data-testid="payroll-draft" :disabled="payrollBusy">Draft payroll</button>
        <button type="button" class="px-3 py-2 text-sm rounded border" :style="field" data-testid="payroll-post" :disabled="payrollBusy || !payroll || payroll.status !== 'draft'" @click="postPayroll">Post</button>
      </form>
    </section>

    <p v-if="message" class="text-sm" :style="{ color: 'var(--text-secondary)' }">{{ message }}</p>

    <div class="flex flex-wrap gap-3">
      <input v-model="filters.q" placeholder="Search employees..." class="px-3 py-2 rounded border" :style="field" data-testid="employee-search" @input="load" />
      <select v-model="filters.department_id" class="px-3 py-2 rounded border" :style="field" data-testid="employee-department-filter" @change="load">
        <option value="">All departments</option>
        <option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option>
      </select>
      <select v-model="filters.status" class="px-3 py-2 rounded border" :style="field" data-testid="employee-status-filter" @change="load">
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
        <option value="all">All</option>
      </select>
    </div>

    <div class="dct-card overflow-hidden" :style="{ background: 'var(--surface-low)' }">
      <div class="grid grid-cols-4 gap-3 px-4 py-2 text-xs uppercase" :style="{ color: 'var(--text-muted)' }">
        <span>Employee</span><span>Position</span><span>Department</span><span>Status</span>
      </div>
      <router-link
        v-for="row in rows"
        :key="row.id"
        :to="`/operations/people/${row.id}`"
        class="grid grid-cols-4 gap-3 px-4 py-3 text-sm border-t"
        :style="{ color: 'var(--text-primary)', borderColor: 'var(--border)' }"
        :data-testid="`employee-row-${row.employee_number}`"
      >
        <span>{{ row.name }}<span class="block text-xs" :style="{ color: 'var(--text-muted)' }">{{ row.employee_number }}</span></span>
        <span>{{ row.position_title }}</span>
        <span>{{ row.department?.name || '—' }}</span>
        <span class="capitalize">{{ row.status }}</span>
      </router-link>
      <p v-if="!rows.length" class="px-4 py-6 text-sm" :style="{ color: 'var(--text-muted)' }">No employees match this register.</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../services/api.js'

const router = useRouter()
const field = { background: 'var(--surface-low)', color: 'var(--text-primary)', borderColor: 'var(--border)' }
const rows = ref([])
const departments = ref([])
const message = ref('')
const preview = ref('')
const showForm = ref(true)
const suggestion = ref('')
const descriptionSource = ref('suggested')
const form = ref({ first_name: '', surname: '', position_title: '', department_id: '', start_date: '', job_description: '' })
const filters = ref({ q: '', department_id: '', status: 'active' })
const payroll = ref(null)
const payrollError = ref('')
const payrollBusy = ref(false)
const payrollForm = ref({ period_start: '', period_end: '', currency: 'USD' })

async function load() {
  const res = await api.get('/api/enterprise/employees', { params: filters.value })
  rows.value = res.data.data || []
}

async function loadPreview() {
  const res = await api.get('/api/enterprise/hr/settings')
  preview.value = res.data.data?.next_employee_number || ''
}

async function fetchSuggestion() {
  if (!form.value.position_title.trim()) return
  const res = await api.post('/api/enterprise/employees/job-description-suggestion', {
    position_title: form.value.position_title,
  })
  suggestion.value = res.data.data?.job_description || ''
}

async function onPositionBlur() {
  await fetchSuggestion()
  if (descriptionSource.value !== 'edited') {
    form.value.job_description = suggestion.value
    descriptionSource.value = 'suggested'
  }
}

function onDescriptionInput() {
  descriptionSource.value = form.value.job_description === suggestion.value ? 'suggested' : 'edited'
}

async function regenerate() {
  await fetchSuggestion()
  form.value.job_description = suggestion.value
  descriptionSource.value = 'suggested'
}

async function draftPayroll() {
  if (payrollBusy.value) return
  payrollBusy.value = true
  payrollError.value = ''
  try {
    const res = await api.post('/api/enterprise/payroll-runs', { ...payrollForm.value, currency: 'USD' })
    payroll.value = res.data.data
  } catch (err) {
    payrollError.value = err.response?.data?.message || 'Could not draft payroll.'
  } finally {
    payrollBusy.value = false
  }
}

async function postPayroll() {
  if (payrollBusy.value || !payroll.value) return
  payrollBusy.value = true
  payrollError.value = ''
  const id = payroll.value.id
  try {
    const res = await api.post(`/api/enterprise/payroll-runs/${id}/post`)
    payroll.value = res.data.data
  } catch (err) {
    // A lost or unreadable response can follow a successful post, so trust the stored run.
    try {
      const fresh = await api.get(`/api/enterprise/payroll-runs/${id}`)
      payroll.value = fresh.data.data
    } catch {
      // Keep the original error below.
    }
    if (payroll.value?.status !== 'posted') {
      payrollError.value = err.response?.data?.message || 'Could not post payroll.'
    }
  } finally {
    payrollBusy.value = false
  }
}

async function create() {
  message.value = ''
  try {
    const payload = {
      first_name: form.value.first_name,
      surname: form.value.surname,
      position_title: form.value.position_title,
      job_description: form.value.job_description || null,
      department_id: form.value.department_id || null,
      start_date: form.value.start_date || null,
    }
    const res = await api.post('/api/enterprise/employees', payload)
    const created = res.data.data
    await router.push({ path: `/operations/people/${created.id}`, query: { created: created.employee_number } })
  } catch (err) {
    message.value = err.response?.data?.message || 'Could not create the employee.'
  }
}

onMounted(async () => {
  departments.value = (await api.get('/api/enterprise/departments')).data.data || []
  await Promise.all([load(), loadPreview()])
})
</script>
