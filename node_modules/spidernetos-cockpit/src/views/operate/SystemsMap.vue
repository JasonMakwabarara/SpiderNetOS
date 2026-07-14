<template>
  <div class="px-6 py-7 max-w-6xl mx-auto">
    <div class="mb-6">
      <div class="sn-eyebrow">Operate · Systems Map</div>
      <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Your business, mapped</h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">
        Every process gets a goal and exactly one owner. Delegate smallest-first until nothing needs you day-to-day.
      </p>
    </div>

    <!-- Founder load -->
    <div class="grid md:grid-cols-4 gap-4 mb-6">
      <div class="sn-card p-4">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Processes</p>
        <p class="text-2xl font-bold mt-1" style="color: var(--text-primary);">{{ load.total_processes }}</p>
      </div>
      <div class="sn-card p-4">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Still on you</p>
        <p class="text-2xl font-bold mt-1" style="color: #FFAA00;">{{ load.founder_owned }} ({{ load.founder_owned_pct }}%)</p>
      </div>
      <div class="sn-card p-4">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Delegated</p>
        <p class="text-2xl font-bold mt-1" style="color: var(--accent);">{{ load.delegated }}</p>
      </div>
      <div class="sn-card p-4">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Automated</p>
        <p class="text-2xl font-bold mt-1" style="color: var(--tealime-vivid, #3ddc97);">{{ load.automated }}</p>
      </div>
    </div>

    <!-- Snowball -->
    <div class="sn-card p-5 mb-6 space-y-3">
      <h2 class="font-medium" style="color: var(--text-primary);">Snowball — delegate next</h2>
      <p class="text-sm" style="color: var(--text-secondary);">{{ snowball.next_action }}</p>
      <ul v-if="snowball.queue.length" class="space-y-2">
        <li
          v-for="item in snowball.queue.slice(0, 5)"
          :key="item.id"
          class="flex items-center justify-between text-sm rounded-lg px-3 py-2"
          style="background: var(--surface-low);"
        >
          <span style="color: var(--text-primary);">
            {{ item.name }}
            <span class="text-xs ml-2" style="color: var(--text-muted);">{{ item.function }} · effort {{ item.effort_size }}/5</span>
          </span>
          <span class="text-xs" :style="{ color: item.has_published_sop ? 'var(--accent)' : '#FFAA00' }">
            {{ item.has_published_sop ? 'SOP ready — hand it off' : 'Needs SOP first' }}
          </span>
        </li>
      </ul>
    </div>

    <!-- Bootstrap empty state -->
    <div v-if="!systems.length" class="sn-card p-6 text-center space-y-3">
      <p class="text-sm" style="color: var(--text-secondary);">
        No systems mapped yet. Start with the six core functions every business runs on.
      </p>
      <button
        type="button"
        class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold disabled:opacity-50"
        :disabled="busy"
        @click="bootstrap"
      >
        {{ busy ? 'Creating…' : 'Create my systems map' }}
      </button>
    </div>

    <!-- Map by function -->
    <div v-else class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div v-for="system in systems" :key="system.id" class="sn-card p-4 space-y-3">
        <div>
          <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">{{ system.function }}</p>
          <h3 class="font-medium" style="color: var(--text-primary);">{{ system.name }}</h3>
          <p class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ system.goal }}</p>
        </div>

        <ul class="space-y-1.5">
          <li
            v-for="p in system.processes"
            :key="p.id"
            class="text-sm rounded px-2 py-1.5 space-y-1"
            style="background: var(--surface-low);"
          >
            <div class="flex items-center justify-between">
              <span style="color: var(--text-primary);">
                <span
                  v-if="p.flow_id"
                  class="inline-block size-2 rounded-full mr-1.5"
                  :style="{ background: runDot(p) }"
                  :title="p.last_run_status ? `Last run: ${p.last_run_status}` : 'Not run yet'"
                />{{ p.name }}
              </span>
              <span
                class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded"
                :style="ownerBadge(p.owner_type)"
              >
                {{ p.owner_type === 'founder' ? 'you' : p.owner_type }}
              </span>
            </div>

            <div class="flex items-center gap-2">
              <button
                v-if="p.has_published_sop && !p.flow_id"
                type="button"
                class="text-[11px] underline"
                style="color: var(--accent);"
                :disabled="busyProcess === p.id"
                @click="automate(p)"
              >
                {{ busyProcess === p.id ? 'Compiling…' : 'Automate' }}
              </button>
              <button
                v-if="p.flow_id && !p.needs_attention"
                type="button"
                class="text-[11px] underline"
                style="color: var(--accent);"
                :disabled="busyProcess === p.id"
                @click="runNow(p)"
              >
                {{ busyProcess === p.id ? 'Running…' : 'Run now' }}
              </button>
              <button
                v-if="p.needs_attention"
                type="button"
                class="text-[11px] underline font-semibold"
                style="color: #FFAA00;"
                @click="answerEscalation(p)"
              >
                Stuck — answer &amp; fix SOP
              </button>
              <span v-if="p.schedule_cron" class="text-[10px]" style="color: var(--text-muted);">
                {{ p.schedule_cron.replace('_', ' ') }}
              </span>
            </div>
          </li>
        </ul>

        <form class="flex gap-2" @submit.prevent="addProcess(system)">
          <input
            v-model="drafts[system.id]"
            type="text"
            placeholder="Add a task…"
            class="flex-1 text-sm rounded-lg px-2 py-1.5 border"
            style="background: var(--surface-low); border-color: var(--border); color: var(--text-primary);"
          />
          <button type="submit" class="sn-btn px-3 py-1.5 rounded-lg text-xs font-semibold">Add</button>
        </form>
      </div>
    </div>

    <p v-if="error" class="text-sm mt-4" style="color: var(--dusk-vivid, #ff6b6b);">{{ error }}</p>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import api from '../../services/api.js'

const systems = ref([])
const load = ref({ total_processes: 0, founder_owned: 0, delegated: 0, automated: 0, founder_owned_pct: 0 })
const snowball = ref({ queue: [], next_action: '' })
const drafts = reactive({})
const busy = ref(false)
const busyProcess = ref(null)
const error = ref('')

function ownerBadge(ownerType) {
  const palette = {
    founder: { background: 'color-mix(in srgb, #FFAA00 15%, transparent)', color: '#FFAA00' },
    team: { background: 'color-mix(in srgb, var(--accent) 15%, transparent)', color: 'var(--accent)' },
    agent: { background: 'color-mix(in srgb, #3ddc97 15%, transparent)', color: '#3ddc97' },
  }
  return palette[ownerType] || palette.founder
}

function runDot(p) {
  if (p.needs_attention) return '#ff6b6b'
  if (p.last_run_status === 'passed') return '#3ddc97'
  if (p.last_run_status === 'failed') return '#FFAA00'
  return 'var(--text-muted)'
}

async function automate(p) {
  busyProcess.value = p.id
  error.value = ''
  try {
    await api.post(`/api/systemization/processes/${p.id}/automate`, { schedule: 'daily_morning' })
    await refresh()
  } catch (err) {
    error.value = err.response?.data?.message || 'Could not compile the runbook.'
  } finally {
    busyProcess.value = null
  }
}

async function runNow(p) {
  busyProcess.value = p.id
  error.value = ''
  try {
    await api.post(`/api/systemization/processes/${p.id}/run`)
    await refresh()
  } catch (err) {
    error.value = err.response?.data?.message || 'The run could not be started.'
  } finally {
    busyProcess.value = null
  }
}

async function answerEscalation(p) {
  const answer = prompt(
    `"${p.name}" failed twice and its owner is stuck.\n\nWhat's the answer? It resolves the escalation AND becomes the next SOP revision, so this question never comes back.`,
  )
  if (!answer) return
  error.value = ''
  try {
    await api.post(`/api/systemization/processes/${p.id}/resolve-escalation`, { answer })
    await refresh()
  } catch (err) {
    error.value = err.response?.data?.message || 'The escalation could not be resolved.'
  }
}

async function refresh() {
  try {
    const [mapRes, snowRes] = await Promise.all([
      api.get('/api/systemization/map'),
      api.get('/api/systemization/snowball'),
    ])
    systems.value = mapRes.data.data?.systems || []
    load.value = mapRes.data.data?.founder_load || load.value
    snowball.value = snowRes.data.data || snowball.value
  } catch (err) {
    error.value = err.response?.data?.message || 'Could not load the systems map.'
  }
}

async function bootstrap() {
  busy.value = true
  error.value = ''
  try {
    await api.post('/api/systemization/bootstrap')
    await refresh()
  } catch (err) {
    error.value = err.response?.data?.message || 'Bootstrap failed. Please retry.'
  } finally {
    busy.value = false
  }
}

async function addProcess(system) {
  const name = (drafts[system.id] || '').trim()
  if (!name) return
  error.value = ''
  try {
    await api.post(`/api/systemization/systems/${system.id}/processes`, { name })
    drafts[system.id] = ''
    await refresh()
  } catch (err) {
    error.value = err.response?.data?.message || 'Could not add the task.'
  }
}

onMounted(refresh)
</script>
