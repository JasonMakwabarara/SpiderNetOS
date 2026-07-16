<template>
  <div class="reports-page">
    <div class="page-header"><h1>📄 Reports</h1><p>Generate and manage automated reports</p><button @click="generateReport" class="btn-primary">+ Generate New Report</button></div>
    <div class="reports-list">
      <div v-if="reports.length === 0" class="empty-state"><span>📄</span><p>No reports yet</p><button @click="generateReport" class="btn-outline">Generate your first report</button></div>
      <div v-for="report in reports" :key="report.id" class="report-card">
        <div class="report-info"><h4>{{ report.title }}</h4><span class="report-type">{{ report.type }}</span><span class="report-date">{{ formatDate(report.generated_at) }}</span></div>
        <div class="report-actions"><button @click="downloadReport(report.id)" class="btn-download">⬇ Download</button><button @click="viewReport(report)" class="btn-view">👁 View</button></div>
      </div>
    </div>
    <div v-if="showModal" class="modal" @click.self="showModal = false">
      <div class="modal-content"><div class="modal-header"><h2>{{ selectedReport?.title }}</h2><button class="modal-close" @click="showModal = false">✖</button></div>
      <div class="modal-body"><pre>{{ JSON.stringify(selectedReport?.data, null, 2) }}</pre></div>
      <div class="modal-footer"><button class="btn-secondary" @click="showModal = false">Close</button></div></div>
    </div>
  </div>
</template>
<script>
import api from '../services/api'
export default {
  data() { return { reports: [], showModal: false, selectedReport: null }; },
  mounted() { this.fetchReports(); },
  methods: {
    formatDate(date) { return date ? new Date(date).toLocaleString() : 'N/A'; },
    async fetchReports() { try { const { data } = await api.get('/reports'); this.reports = data; } catch(e) { console.error(e); } },
    async generateReport() { try { await api.post('/reports/generate', { title: 'Daily Report' }); await this.fetchReports(); alert('✅ Report generated!'); } catch(e) { alert('❌ Failed: ' + e.message); } },
    async downloadReport(id) { try { const response = await api.get(`/reports/${id}/download`); const blob = new Blob([response.data], { type: 'text/plain' }); const url = window.URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'report.txt'; a.click(); } catch(e) { alert('❌ Failed: ' + e.message); } },
    viewReport(report) { this.selectedReport = report; this.showModal = true; }
  }
}
</script>
<style scoped>
.reports-page { padding: 2rem; max-width: 1200px; margin: 0 auto; }
.page-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem; }
.btn-primary { background: #3b82f6; color: white; border: none; padding: 0.6rem 1.5rem; border-radius: 12px; cursor: pointer; font-weight: 600; }
.btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text-primary); padding: 0.6rem 1.5rem; border-radius: 12px; cursor: pointer; }
.report-card { background: var(--bg-card); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem; }
.report-info { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
.report-type { font-size: 0.7rem; padding: 0.2rem 0.8rem; border-radius: 20px; background: var(--border); text-transform: uppercase; }
.report-date { font-size: 0.85rem; color: var(--text-secondary); }
.btn-download { background: #22c55e; color: white; border: none; padding: 0.4rem 1rem; border-radius: 8px; cursor: pointer; }
.btn-view { background: #3b82f6; color: white; border: none; padding: 0.4rem 1rem; border-radius: 8px; cursor: pointer; }
.empty-state { text-align: center; padding: 4rem 0; }
.empty-state span { font-size: 4rem; display: block; margin-bottom: 1rem; }
.modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg); border-radius: 16px; max-width: 600px; width: 90%; max-height: 80vh; overflow: hidden; }
.modal-header, .modal-footer { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.modal-body { padding: 1.5rem; overflow-y: auto; max-height: 50vh; }
.modal-body pre { white-space: pre-wrap; word-wrap: break-word; margin: 0; }
.modal-footer { border-bottom: none; border-top: 1px solid var(--border); justify-content: flex-end; }
</style>
