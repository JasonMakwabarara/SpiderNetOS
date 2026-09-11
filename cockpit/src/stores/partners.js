/**
 * Partner outreach (affiliate recruitment) Pinia store.
 *
 * Prospects imported from Affonso Finder exports, the operator DM queue and
 * the tenant's outreach settings, all via /api/sales/partners/*.
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../services/api.js'

export const PROSPECT_STATUSES = [
  'new', 'needs_email', 'ready', 'invited', 'nudged', 'last_called', 'retired',
  'dm_drafted', 'dm_sent', 'replied', 'negotiating', 'handoff',
  'signed_up', 'declined', 'unsubscribed', 'bounced',
]

export const usePartnersStore = defineStore('partners', () => {
  const prospects = ref([])
  const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
  const summary = ref({ total: 0, by_status: {}, with_email: 0, dm_queue: 0, needs_human: 0 })
  const dmQueue = ref([])
  const settings = ref(null)
  const settingsMeta = ref({ mailbox_connected: false, flags: {} })
  const loading = ref(false)
  const error = ref(null)

  function fail(err, fallback) {
    error.value = err?.response?.data?.message || fallback
    throw err
  }

  async function fetchProspects(filters = {}) {
    loading.value = true
    error.value = null
    try {
      const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v != null))
      const { data } = await api.get('/api/sales/partners', { params: { per_page: 50, ...params } })
      prospects.value = data?.data?.data || []
      pagination.value = {
        current_page: data?.data?.current_page || 1,
        last_page: data?.data?.last_page || 1,
        total: data?.data?.total || 0,
      }
      if (data?.summary) summary.value = data.summary
    } catch (err) {
      fail(err, 'Failed to load prospects')
    } finally {
      loading.value = false
    }
  }

  async function fetchProspect(id) {
    const { data } = await api.get(`/api/sales/partners/${id}`)
    return data
  }

  async function updateProspect(id, payload) {
    try {
      const { data } = await api.patch(`/api/sales/partners/${id}`, payload)
      const i = prospects.value.findIndex((p) => p.id === id)
      if (i >= 0) prospects.value[i] = data.data
      return data.data
    } catch (err) {
      fail(err, 'Update failed')
    }
  }

  async function importCsv(file, { dryRun = false, source = 'csv' } = {}) {
    const form = new FormData()
    form.append('file', file)
    form.append('source', source)
    form.append('dry_run', dryRun ? '1' : '0')
    try {
      const { data } = await api.post('/api/sales/partners/import', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      if (data?.summary) summary.value = data.summary
      return data.data
    } catch (err) {
      fail(err, 'Import failed')
    }
  }

  async function fetchDmQueue() {
    const { data } = await api.get('/api/sales/partners/dm-queue')
    dmQueue.value = data?.data || []
    return dmQueue.value
  }

  async function markDmSent(messageId) {
    await api.post(`/api/sales/partners/messages/${messageId}/mark-sent`)
    dmQueue.value = dmQueue.value.filter((m) => m.message_id !== messageId)
  }

  async function dmReply(prospectId, body) {
    const { data } = await api.post(`/api/sales/partners/${prospectId}/dm-reply`, { body })
    return data
  }

  async function action(id, verb) {
    const { data } = await api.post(`/api/sales/partners/${id}/${verb}`)
    const i = prospects.value.findIndex((p) => p.id === id)
    if (i >= 0) prospects.value[i] = { ...prospects.value[i], ...data.data }
    return data.data
  }

  async function fetchSettings() {
    const { data } = await api.get('/api/sales/partners/settings')
    settings.value = data?.data || null
    settingsMeta.value = { mailbox_connected: !!data?.mailbox_connected, flags: data?.flags || {} }
    return settings.value
  }

  async function saveSettings(patch) {
    try {
      const { data } = await api.put('/api/sales/partners/settings', patch)
      settings.value = data?.data || settings.value
      return settings.value
    } catch (err) {
      fail(err, 'Could not save settings')
    }
  }

  async function runTick({ dryRun = true } = {}) {
    const { data } = await api.post('/api/sales/partners/run', { dry_run: dryRun })
    return data?.data
  }

  return {
    prospects, pagination, summary, dmQueue, settings, settingsMeta, loading, error,
    fetchProspects, fetchProspect, updateProspect, importCsv,
    fetchDmQueue, markDmSent, dmReply, action,
    fetchSettings, saveSettings, runTick,
  }
})
