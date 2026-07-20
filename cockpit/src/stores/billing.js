import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../services/api.js'

/**
 * Platform billing (SpiderNetOS charging the tenant): plan catalog,
 * subscription summary, invoices, and subscribe/cancel actions backed by the
 * Dodo-powered /api/billing/* endpoints.
 */
export const useBillingStore = defineStore('billing', () => {
  const plans = ref([])
  const summary = ref(null)
  const invoices = ref([])
  const isLoading = ref(false)
  const error = ref(null)

  async function fetchAll() {
    isLoading.value = true
    error.value = null
    try {
      const [p, s, i] = await Promise.allSettled([
        api.get('/api/billing/plans'),
        api.get('/api/billing/summary'),
        api.get('/api/billing/invoices'),
      ])
      if (p.status === 'fulfilled') plans.value = p.value.data?.data || []
      if (s.status === 'fulfilled') summary.value = s.value.data?.data || null
      if (i.status === 'fulfilled') invoices.value = i.value.data?.data || []
    } finally {
      isLoading.value = false
    }
  }

  /** Returns { checkout_url } for a new subscription, or { changed:true } for an in-place plan switch. */
  async function subscribe(planId) {
    const { data } = await api.post('/api/billing/subscribe', { plan_id: planId })
    return data.data
  }

  async function cancel() {
    const { data } = await api.post('/api/billing/cancel')
    return data.data
  }

  return { plans, summary, invoices, isLoading, error, fetchAll, subscribe, cancel }
})
