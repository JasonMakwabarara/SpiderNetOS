<template>
  <div class="security-page">
    <h1>🔒 Security Settings</h1>
    <p>Manage 2FA, permissions, and view audit logs</p>
    <div class="security-grid">
      <div class="security-card">
        <h3>🔐 Two-Factor Authentication</h3>
        <p class="description">Add an extra layer of security to your account</p>
        <div class="security-status"><span class="status-label">Status:</span><span class="status-badge" :class="twoFactorEnabled ? 'enabled' : 'disabled'">{{ twoFactorEnabled ? '✅ Enabled' : '❌ Disabled' }}</span></div>
        <button @click="toggle2FA" class="btn-security" :class="twoFactorEnabled ? 'btn-danger' : 'btn-success'">{{ twoFactorEnabled ? 'Disable 2FA' : 'Enable 2FA' }}</button>
      </div>
      <div class="security-card">
        <h3>📋 Audit Logs</h3>
        <p class="description">Recent security events</p>
        <div class="audit-logs">
          <div v-for="log in auditLogs" :key="log.id" class="log-entry"><span class="log-action">{{ log.action }}</span><span class="log-time">{{ formatDate(log.created_at) }}</span></div>
          <div v-if="auditLogs.length === 0" class="empty-logs">No audit logs found</div>
        </div>
        <button @click="fetchAuditLogs" class="btn-refresh">🔄 Refresh Logs</button>
      </div>
    </div>
  </div>
</template>
<script>
import api from '../services/api'
export default {
  data() { return { twoFactorEnabled: false, auditLogs: [] }; },
  mounted() { this.fetchAuditLogs(); },
  methods: {
    formatDate(date) { return date ? new Date(date).toLocaleString() : 'N/A'; },
    async toggle2FA() {
      try {
        if (this.twoFactorEnabled) { this.twoFactorEnabled = false; alert('2FA disabled'); }
        else { const { data } = await api.post('/security/2fa/enable'); this.twoFactorEnabled = true; alert('✅ 2FA enabled! Secret: ' + data.secret); }
      } catch(e) { alert('❌ Failed: ' + e.message); }
    },
    async fetchAuditLogs() { try { const { data } = await api.get('/security/audit-logs'); this.auditLogs = data.slice(0, 10); } catch(e) { console.error(e); } }
  }
}
</script>
<style scoped>
.security-page { padding: 2rem; max-width: 1200px; margin: 0 auto; }
.security-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem; margin-top: 1.5rem; }
.security-card { background: var(--bg-card); padding: 1.5rem; border-radius: 16px; border: 1px solid var(--border); }
.security-card .description { color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem; }
.security-status { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; }
.status-badge { padding: 0.3rem 0.8rem; border-radius: 20px; font-weight: 600; font-size: 0.85rem; }
.status-badge.enabled { background: #22c55e; color: white; }
.status-badge.disabled { background: #ef4444; color: white; }
.btn-security { padding: 0.6rem 1.5rem; border: none; border-radius: 12px; cursor: pointer; font-weight: 600; }
.btn-success { background: #22c55e; color: white; }
.btn-danger { background: #ef4444; color: white; }
.btn-refresh { background: #3b82f6; color: white; border: none; padding: 0.6rem 1.5rem; border-radius: 12px; cursor: pointer; font-weight: 600; margin-top: 1rem; }
.audit-logs { max-height: 200px; overflow-y: auto; }
.log-entry { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border); }
.log-action { font-weight: 500; }
.log-time { color: var(--text-secondary); font-size: 0.8rem; }
.empty-logs { color: var(--text-secondary); text-align: center; padding: 1rem 0; }
</style>
