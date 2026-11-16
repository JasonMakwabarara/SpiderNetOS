<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }" data-testid="weft-me">
    <WeftSubnav />
    <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">My record</h1>
    <p v-if="loaded && !record" class="text-sm" :style="{ color: 'var(--text-muted)' }">No employee record is linked to this login.</p>
    <template v-if="record">
      <p class="text-sm" :style="{ color: 'var(--text-primary)' }">{{ record.employee.name }} · {{ record.employee.employee_number }}</p>
      <section>
        <h2 class="font-semibold" :style="{ color: 'var(--text-primary)' }">Attendance</h2>
        <p v-for="event in record.attendance" :key="event.id" class="text-sm" :style="{ color: 'var(--text-secondary)' }">
          {{ event.recorded_at }} {{ event.type }}
        </p>
      </section>
      <section>
        <h2 class="font-semibold" :style="{ color: 'var(--text-primary)' }">Leave</h2>
        <p v-for="row in record.leave" :key="row.id" class="text-sm" :style="{ color: 'var(--text-secondary)' }">
          {{ row.starts_on }} · {{ row.status }}
        </p>
      </section>
      <section>
        <h2 class="font-semibold" :style="{ color: 'var(--text-primary)' }">Payslips</h2>
        <p v-for="slip in record.payslips" :key="slip.id" class="text-sm" data-testid="my-payslip" :style="{ color: 'var(--text-secondary)' }">
          Net {{ slip.net }}
        </p>
      </section>
    </template>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import WeftSubnav from '../../components/weft/WeftSubnav.vue'
import api from '../../services/api.js'

const record = ref(null)
const loaded = ref(false)

onMounted(async () => {
  record.value = (await api.get('/api/enterprise/weft/me')).data.data
  loaded.value = true
})
</script>
