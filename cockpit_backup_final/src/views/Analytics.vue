<template>
  <div class="analytics-page">
    <h1>📊 Analytics Dashboard</h1>
    <p>Real-time insights into your AI agents and system performance</p>
    <div class="stats-grid">
      <div class="stat-card" v-for="(stat, key) in metrics" :key="key">
        <span class="stat-label">{{ formatLabel(key) }}</span>
        <span class="stat-value">{{ stat }}</span>
      </div>
    </div>
    <div class="realtime-section">
      <h3>🔴 Live Updates</h3>
      <div class="realtime-grid">
        <div><span class="label">Active Sessions</span><span class="value">{{ realtime.active_sessions }}</span></div>
        <div><span class="label">Latest Agent</span><span class="value">{{ realtime.latest_agent || 'None' }}</span></div>
        <div><span class="label">Last Updated</span><span class="value">{{ formatTime(realtime.timestamp) }}</span></div>
      </div>
    </div>
    <button @click="refreshData" class="btn-primary">🔄 Refresh</button>
    <button @click="generateReport" class="btn-success">📄 Generate Report</button>
  </div>
</template>
<script>
import api from '../services/api'
export default {
  data() { return { metrics: {}, realtime: {}, interval: null } },
  mounted() {
    this.fetchMetrics(); this.fetchRealtime();
    this.interval = setInterval(this.fetchRealtime, 10000);
  },
  beforeUnmount() { if (this.interval) clearInterval(this.interval); },
  methods: {
    formatLabel(key) { return key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()); },
    formatTime(ts) { return ts ? new Date(ts).toLocaleTimeString() : 'N/A'; },
    async fetchMetrics() { try { const { data } = await api.get('/analytics/agents'); this.metrics = data; } catch(e) { console.error(e); } },
    async fetchRealtime() { try { const { data } = await api.get('/analytics/realtime'); this.realtime = data; } catch(e) { console.error(e); } },
    async refreshData() { await this.fetchMetrics(); await this.fetchRealtime(); },
    async generateReport() { try { const { data } = await api.post('/reports/generate', { title: 'Daily Report' }); alert('✅ Report generated! ID: ' + data.id); } catch(e) { alert('❌ Failed: ' + e.message); } }
  }
}
</script>
<style scoped>
.analytics-page { padding: 2rem; max-width: 1400px; margin: 0 auto; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin: 1.5rem 0; }
.stat-card { background: var(--bg-card); padding: 1.5rem; border-radius: 16px; border: 1px solid var(--border); }
.stat-label { display: block; font-size: 0.85rem; color: var(--text-secondary); text-transform: capitalize; }
.stat-value { font-size: 2rem; font-weight: 700; }
.realtime-section { background: var(--bg-card); padding: 1.5rem; border-radius: 16px; border: 1px solid var(--border); margin-bottom: 1.5rem; }
.realtime-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-top: 1rem; }
.realtime-grid .label { display: block; font-size: 0.8rem; color: var(--text-secondary); }
.realtime-grid .value { font-size: 1.2rem; font-weight: 600; }
.btn-primary { background: #3b82f6; color: white; border: none; padding: 0.6rem 1.5rem; border-radius: 12px; cursor: pointer; font-weight: 600; }
.btn-success { background: #22c55e; color: white; border: none; padding: 0.6rem 1.5rem; border-radius: 12px; cursor: pointer; font-weight: 600; margin-left: 0.5rem; }
</style>
