<template>
  <div class="analytics-page">
    <div class="page-header">
      <h1>📊 Analytics Dashboard</h1>
      <p>Real-time insights into your AI agents and system performance</p>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
      <div class="stat-card" v-for="(stat, key) in metrics" :key="key">
        <span class="stat-label">{{ formatLabel(key) }}</span>
        <span class="stat-value">{{ stat }}</span>
      </div>
    </div>

    <!-- Realtime Updates -->
    <div class="realtime-section">
      <div class="section-header">
        <h3>🔴 Live Updates</h3>
        <span class="live-badge">● Live</span>
      </div>
      <div class="realtime-grid">
        <div class="realtime-item">
          <span class="label">Active Sessions</span>
          <span class="value">{{ realtime.active_sessions }}</span>
        </div>
        <div class="realtime-item">
          <span class="label">Latest Agent</span>
          <span class="value">{{ realtime.latest_agent || 'None' }}</span>
        </div>
        <div class="realtime-item">
          <span class="label">Last Updated</span>
          <span class="value">{{ formatTime(realtime.timestamp) }}</span>
        </div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
      <h3>⚡ Quick Actions</h3>
      <div class="action-buttons">
        <button @click="refreshData" class="btn-primary">🔄 Refresh</button>
        <button @click="generateReport" class="btn-success">📄 Generate Report</button>
      </div>
    </div>
  </div>
</template>

<script>
import api from '../services/api'

export default {
  data() {
    return {
      metrics: {},
      realtime: {},
      interval: null
    }
  },
  mounted() {
    this.fetchMetrics()
    this.fetchRealtime()
    this.interval = setInterval(this.fetchRealtime, 10000)
  },
  beforeUnmount() {
    if (this.interval) clearInterval(this.interval)
  },
  methods: {
    formatLabel(key) {
      return key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
    },
    formatTime(timestamp) {
      if (!timestamp) return 'N/A'
      return new Date(timestamp).toLocaleTimeString()
    },
    async fetchMetrics() {
      try {
        const { data } = await api.get('/analytics/agents')
        this.metrics = data
      } catch (e) {
        console.error('Failed to fetch metrics:', e)
      }
    },
    async fetchRealtime() {
      try {
        const { data } = await api.get('/analytics/realtime')
        this.realtime = data
      } catch (e) {
        console.error('Failed to fetch realtime:', e)
      }
    },
    async refreshData() {
      await this.fetchMetrics()
      await this.fetchRealtime()
    },
    async generateReport() {
      try {
        const { data } = await api.post('/reports/generate', { title: 'Daily Report' })
        alert('✅ Report generated! ID: ' + data.id)
      } catch (e) {
        alert('❌ Failed to generate report: ' + e.message)
      }
    }
  }
}
</script>

<style scoped>
.analytics-page { padding: 2rem; max-width: 1400px; margin: 0 auto; }
.page-header { margin-bottom: 2rem; }
.page-header h1 { font-size: 2rem; font-weight: 700; background: linear-gradient(135deg, #3b82f6, #8b5cf6); -webkit-background-clip: text; background-clip: text; color: transparent; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
.stat-card { background: var(--bg-card); padding: 1.5rem; border-radius: 16px; border: 1px solid var(--border); transition: transform 0.2s; }
.stat-card:hover { transform: translateY(-4px); }
.stat-label { display: block; font-size: 0.85rem; color: var(--text-secondary); text-transform: capitalize; margin-bottom: 0.5rem; }
.stat-value { font-size: 2rem; font-weight: 700; color: var(--text-primary); }
.realtime-section { background: var(--bg-card); padding: 1.5rem; border-radius: 16px; border: 1px solid var(--border); margin-bottom: 2rem; }
.section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.live-badge { color: #22c55e; font-size: 0.8rem; font-weight: 600; animation: pulse 2s infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
.realtime-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
.realtime-item { display: flex; flex-direction: column; }
.realtime-item .label { font-size: 0.8rem; color: var(--text-secondary); }
.realtime-item .value { font-size: 1.2rem; font-weight: 600; }
.quick-actions { background: var(--bg-card); padding: 1.5rem; border-radius: 16px; border: 1px solid var(--border); }
.action-buttons { display: flex; gap: 1rem; margin-top: 1rem; flex-wrap: wrap; }
.btn-primary { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border: none; padding: 0.6rem 1.5rem; border-radius: 12px; cursor: pointer; font-weight: 600; transition: all 0.2s; }
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }
.btn-success { background: linear-gradient(135deg, #22c55e, #16a34a); color: white; border: none; padding: 0.6rem 1.5rem; border-radius: 12px; cursor: pointer; font-weight: 600; transition: all 0.2s; }
.btn-success:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(34,197,94,0.3); }
</style>
