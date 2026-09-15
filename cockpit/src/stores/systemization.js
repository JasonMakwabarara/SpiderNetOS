/**
 * Systemization Pinia store — the systems map (functions → processes),
 * founder load and the delegation "snowball". Extracted from
 * views/operate/SystemsMap.vue so the business map's node drawer can share
 * the same state and actions. Endpoints are unchanged.
 *
 * Every mutating action returns { success, error } like stores/funnel.js.
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '../services/api.js'

const EMPTY_LOAD = { total_processes: 0, founder_owned: 0, delegated: 0, automated: 0, founder_owned_pct: 0 }

export const useSystemizationStore = defineStore('systemization', () => {
  const systems = ref([])
  const founderLoad = ref({ ...EMPTY_LOAD })
  const snowball = ref({ queue: [], next_action: '' })
  const busyProcess = ref(null)
  const loading = ref(false)
  const error = ref(null)

  const processes = computed(() => systems.value.flatMap((s) => s.processes || []))
  const processById = computed(() => Object.fromEntries(processes.value.map((p) => [p.id, p])))

  function fail(err, fallback) {
    error.value = err?.response?.data?.message || fallback
    return { success: false, error: error.value }
  }

  async function fetchMap() {
    loading.value = true
    try {
      const { data } = await api.get('/api/systemization/map')
      systems.value = data?.data?.systems || []
      founderLoad.value = data?.data?.founder_load || founderLoad.value
      return { success: true }
    } catch (err) {
      return fail(err, 'Could not load the systems map.')
    } finally {
      loading.value = false
    }
  }

  async function fetchSnowball() {
    try {
      const { data } = await api.get('/api/systemization/snowball')
      snowball.value = data?.data || snowball.value
      return { success: true }
    } catch (err) {
      return fail(err, 'Could not load the delegation queue.')
    }
  }

  async function refresh() {
    const [map, snow] = await Promise.all([fetchMap(), fetchSnowball()])
    return map.success && snow.success ? { success: true } : { success: false, error: error.value }
  }

  async function bootstrap() {
    error.value = null
    try {
      await api.post('/api/systemization/bootstrap')
      await refresh()
      return { success: true }
    } catch (err) {
      return fail(err, 'Bootstrap failed. Please retry.')
    }
  }

  async function addProcess(systemId, name) {
    const trimmed = String(name || '').trim()
    if (!trimmed) return { success: false, error: 'Give the task a name.' }
    error.value = null
    try {
      const { data } = await api.post(`/api/systemization/systems/${systemId}/processes`, { name: trimmed })
      await refresh()
      return { success: true, process: data?.data }
    } catch (err) {
      return fail(err, 'Could not add the task.')
    }
  }

  async function automate(processId, schedule = 'daily_morning') {
    busyProcess.value = processId
    error.value = null
    try {
      await api.post(`/api/systemization/processes/${processId}/automate`, { schedule })
      await refresh()
      return { success: true }
    } catch (err) {
      return fail(err, 'Could not compile the runbook.')
    } finally {
      busyProcess.value = null
    }
  }

  async function runNow(processId) {
    busyProcess.value = processId
    error.value = null
    try {
      await api.post(`/api/systemization/processes/${processId}/run`)
      await refresh()
      return { success: true }
    } catch (err) {
      return fail(err, 'The run could not be started.')
    } finally {
      busyProcess.value = null
    }
  }

  async function resolveEscalation(processId, answer) {
    const trimmed = String(answer || '').trim()
    if (!trimmed) return { success: false, error: 'An answer is required to resolve the escalation.' }
    busyProcess.value = processId
    error.value = null
    try {
      await api.post(`/api/systemization/processes/${processId}/resolve-escalation`, { answer: trimmed })
      await refresh()
      return { success: true }
    } catch (err) {
      return fail(err, 'The escalation could not be resolved.')
    } finally {
      busyProcess.value = null
    }
  }

  return {
    systems, founderLoad, snowball, busyProcess, loading, error,
    processes, processById,
    fetchMap, fetchSnowball, refresh, bootstrap, addProcess, automate, runNow, resolveEscalation,
  }
})
