<template>
  <div class="p-6">
    <h1 class="text-2xl font-bold mb-6">?? Analytics Dashboard</h1>
    
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
      <div class="bg-white rounded-lg shadow p-4">
        <p class="text-sm text-gray-500">Total Agents</p>
        <p class="text-2xl font-bold">{{ analytics.total_agents || 0 }}</p>
      </div>
      <div class="bg-white rounded-lg shadow p-4">
        <p class="text-sm text-gray-500">Active Agents</p>
        <p class="text-2xl font-bold text-green-600">{{ analytics.active_agents || 0 }}</p>
      </div>
      <div class="bg-white rounded-lg shadow p-4">
        <p class="text-sm text-gray-500">Total Flows</p>
        <p class="text-2xl font-bold">{{ analytics.total_flows || 0 }}</p>
      </div>
      <div class="bg-white rounded-lg shadow p-4">
        <p class="text-sm text-gray-500">Published Flows</p>
        <p class="text-2xl font-bold text-blue-600">{{ analytics.published_flows || 0 }}</p>
      </div>
    </div>

    <div class="flex gap-2 mb-6">
      <button @click="refreshData" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">?? Refresh</button>
      <button @click="generateReport" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">?? Generate Report</button>
    </div>

    <div v-if="reportMessage" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
      {{ reportMessage }}
    </div>

    <div class="bg-white rounded-lg shadow p-6">
      <div v-if="loading" class="text-center py-8">Loading...</div>
      <div v-else>
        <p class="text-sm text-gray-500">Last Updated: {{ lastUpdated || 'N/A' }}</p>
      </div>
    </div>
  </div>
</template>

<script>
import api from '../services/api.js'

export default {
  data() {
    return {
      analytics: {},
      loading: false,
      lastUpdated: null,
      reportMessage: ''
    }
  },
  mounted() {
    this.fetchAnalytics()
  },
  methods: {
    async fetchAnalytics() {
      this.loading = true
      try {
        const { data } = await api.get('/analytics/agents')
        this.analytics = data
        this.lastUpdated = new Date().toLocaleString()
      } catch (error) {
        console.error('Error fetching analytics:', error)
      } finally {
        this.loading = false
      }
    },
    async refreshData() {
      await this.fetchAnalytics()
      alert('? Data refreshed!')
    },
    async generateReport() {
      try {
        const { data } = await api.post('/analytics/generate-report')
        this.reportMessage = '? ' + data.message
        setTimeout(() => { this.reportMessage = '' }, 5000)
      } catch (error) {
        alert('? Failed to generate report: ' + error.message)
      }
    }
  }
}
</script>
