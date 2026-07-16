<template>
  <div class="agents-page">
    <div class="page-header">
      <div>
        <h1> Task-Specific Agents</h1>
        <p>Create agents with defined roles and capabilities</p>
      </div>
      <button class="btn-primary" @click="openCreateModal">+ New Agent</button>
    </div>

    <div class="stats-grid">
      <div class="stat-card">
        <span class="stat-icon purple"></span>
        <div class="stat-info">
          <span class="stat-value">{{ agents.length }}</span>
          <span class="stat-label">Total Agents</span>
        </div>
      </div>
      <div class="stat-card">
        <span class="stat-icon green"></span>
        <div class="stat-info">
          <span class="stat-value">{{ activeCount }}</span>
          <span class="stat-label">Active</span>
        </div>
      </div>
    </div>

    <div class="agents-grid">
      <div v-for="agent in agents" :key="agent.id" class="agent-card">
        <div class="agent-header">
          <div class="agent-icon">{{ getIconForRole(agent.role) }}</div>
          <div class="agent-badge" :class="agent.status">{{ agent.status }}</div>
        </div>
        <h3>{{ agent.name }}</h3>
        <div class="agent-role-badge">{{ agent.role || 'Custom' }}</div>
        <p class="agent-description">{{ agent.description || 'No description' }}</p>
        <div class="agent-capabilities">
          <span v-for="cap in getCapabilities(agent.role)" :key="cap" class="capability">{{ cap }}</span>
        </div>
        <div class="card-actions">
          <button class="action-chat" @click="openChatModal(agent)"> Chat</button>
          <button class="action-edit" @click="editAgent(agent)"> Edit</button>
          <button class="action-delete" @click="deleteAgent(agent.id)"> Delete</button>
        </div>
      </div>
      <div v-if="agents.length === 0" class="empty-state">
        <span></span>
        <p>No agents yet</p>
        <button class="btn-outline" @click="openCreateModal">Create your first agent</button>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="showModal" class="modal" @click.self="showModal = false">
      <div class="modal-content">
        <div class="modal-header">
          <h2>{{ editing ? ' Edit Agent' : ' Create Task-Specific Agent' }}</h2>
          <button class="modal-close" @click="showModal = false"></button>
        </div>
        <div class="modal-body">
          <div class="field-group">
            <label>Agent Name</label>
            <input v-model="form.name" placeholder="e.g., Sales Assistant, Support Bot" />
          </div>
          <div class="field-group">
            <label>Role / Purpose</label>
            <select v-model="form.role">
              <option value="">Select a role...</option>
              <option value="Sales"> Sales Assistant</option>
              <option value="Support"> Customer Support</option>
              <option value="Data Analyst"> Data Analyst</option>
              <option value="Developer"> Developer Helper</option>
              <option value="HR"> HR Assistant</option>
              <option value="Custom"> Custom Role</option>
            </select>
          </div>
          <div class="field-group">
            <label>Description</label>
            <textarea v-model="form.description" placeholder="What should this agent do?" rows="3"></textarea>
          </div>
          <div class="field-group" v-if="form.role === 'Custom'">
            <label>Custom Capabilities (comma separated)</label>
            <input v-model="form.customCapabilities" placeholder="e.g., answer questions, analyze data, send emails" />
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn-cancel" @click="showModal = false">Cancel</button>
          <button class="btn-save" @click="saveAgent">{{ editing ? 'Save Changes' : 'Create Agent' }}</button>
        </div>
      </div>
    </div>

    <!-- Chat Modal -->
    <div v-if="showChatModal" class="modal" @click.self="showChatModal = false">
      <div class="modal-content chat-modal">
        <div class="modal-header">
          <h2> Chat with {{ currentAgent?.name }}</h2>
          <div class="agent-role-badge small">{{ currentAgent?.role || 'Custom' }}</div>
          <button class="modal-close" @click="showChatModal = false"></button>
        </div>
        <div class="chat-context">
          <div class="context-badge"> {{ getContextPrompt(currentAgent) }}</div>
        </div>
        <div class="chat-messages" ref="chatContainer">
          <div v-for="(msg, idx) in chatHistory" :key="idx" :class="['message', msg.role]">
            <div class="avatar">{{ msg.role === 'user' ? '' : getIconForRole(currentAgent?.role) }}</div>
            <div class="bubble">{{ msg.content }}</div>
          </div>
          <div v-if="chatLoading" class="message assistant"><div class="avatar"></div><div class="bubble typing">...</div></div>
        </div>
        <div class="chat-input">
          <input v-model="chatMessage" @keyup.enter="sendChatMessage" :placeholder="`Ask ${currentAgent?.name} something...`" />
          <button @click="sendChatMessage">Send</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import api from '../services/api'

export default {
  data() {
    return {
      agents: [],
      showModal: false,
      editing: false,
      form: { id: null, name: '', role: '', description: '', customCapabilities: '' },
      showChatModal: false,
      currentAgent: null,
      chatHistory: [],
      chatMessage: '',
      chatLoading: false
    }
  },
  computed: {
    activeCount() {
      return this.agents.filter(a => a.status === 'active').length
    }
  },
  mounted() {
    this.fetchAgents()
  },
  methods: {
    getIconForRole(role) {
      const icons = { Sales: '', Support: '', 'Data Analyst': '', Developer: '', HR: '' }
      return icons[role] || ''
    },
    getCapabilities(role) {
      const caps = {
        Sales: ['lead generation', 'prospecting', 'follow-up'],
        Support: ['troubleshooting', 'faq', 'ticket handling'],
        'Data Analyst': ['data processing', 'visualization', 'reporting'],
        Developer: ['code review', 'debugging', 'documentation'],
        HR: ['screening', 'onboarding', 'employee support']
      }
      return caps[role] || ['General assistance']
    },
    getContextPrompt(agent) {
      if (!agent) return 'General assistant'
      return `${agent.role || 'Custom'}  ${agent.description || 'AI assistant'}`
    },
    async fetchAgents() {
      try {
        const { data } = await api.get('/agents')
        this.agents = data
      } catch (e) {
        console.error('Error fetching agents:', e)
      }
    },
    openCreateModal() {
      this.editing = false
      this.form = { id: null, name: '', role: '', description: '', customCapabilities: '' }
      this.showModal = true
    },
    editAgent(agent) {
      this.editing = true
      this.form = { ...agent }
      this.showModal = true
    },
    async saveAgent() {
      try {
        if (this.editing) {
          await api.put(`/agents/${this.form.id}`, this.form)
        } else {
          await api.post('/agents', this.form)
        }
        this.showModal = false
        await this.fetchAgents()
        alert(this.editing ? ' Agent updated!' : ' Agent created!')
      } catch (e) {
        alert(' Failed to save agent: ' + (e.response?.data?.message || e.message))
      }
    },
    async deleteAgent(id) {
      if (!confirm('Delete this agent?')) return
      try {
        await api.delete(`/agents/${id}`)
        await this.fetchAgents()
        alert(' Agent deleted')
      } catch (e) {
        alert(' Failed to delete: ' + (e.response?.data?.message || e.message))
      }
    },
    openChatModal(agent) {
      this.currentAgent = agent
      this.chatHistory = [{ role: 'assistant', content: ` I'm ${agent.name}. ${agent.description || 'How can I help you?'}` }]
      this.showChatModal = true
    },
    async sendChatMessage() {
      if (!this.chatMessage.trim()) return
      this.chatHistory.push({ role: 'user', content: this.chatMessage })
      this.chatLoading = true
      try {
        const { data } = await api.post('/agents/chat', {
          agent_id: this.currentAgent.id,
          message: this.chatMessage
        })
        this.chatHistory.push({ role: 'assistant', content: data.response || data.message })
      } catch (e) {
        this.chatHistory.push({ role: 'assistant', content: ' Error: ' + e.message })
      }
      this.chatMessage = ''
      this.chatLoading = false
    }
  }
}
</script>

<style scoped>
.agents-page { padding: 2rem; max-width: 1400px; margin: 0 auto; min-height: 100vh; }
.page-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem; }
.page-header h1 { font-size: 2rem; font-weight: 700; background: linear-gradient(135deg, #8b5cf6, #6d28d9); -webkit-background-clip: text; background-clip: text; color: transparent; }
.page-header p { color: var(--text-secondary); margin-top: 0.25rem; }
.btn-primary { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; border: none; padding: 0.6rem 1.5rem; border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
.stat-card { display: flex; align-items: center; gap: 1rem; background: var(--bg-card); padding: 1rem 1.5rem; border-radius: 16px; border: 1px solid var(--border); }
.stat-icon { font-size: 2rem; }
.stat-info { display: flex; flex-direction: column; }
.stat-value { font-size: 1.5rem; font-weight: 700; }
.stat-label { font-size: 0.85rem; color: var(--text-secondary); }
.agents-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1.5rem; }
.agent-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 1.5rem; transition: all 0.2s; }
.agent-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px rgba(0,0,0,0.1); }
.agent-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
.agent-icon { font-size: 2rem; }
.agent-badge { font-size: 0.6rem; padding: 0.2rem 0.6rem; border-radius: 20px; text-transform: uppercase; font-weight: 600; }
.agent-badge.active { background: #22c55e; color: #fff; }
.agent-badge.inactive { background: #ef4444; color: #fff; }
.agent-role-badge { display: inline-block; font-size: 0.7rem; padding: 0.2rem 0.8rem; border-radius: 20px; background: var(--border); color: var(--text-secondary); margin: 0.5rem 0; }
.agent-description { color: var(--text-secondary); font-size: 0.9rem; margin: 0.5rem 0; }
.agent-capabilities { display: flex; gap: 0.5rem; flex-wrap: wrap; margin: 0.5rem 0; }
.capability { font-size: 0.7rem; padding: 0.2rem 0.6rem; border-radius: 20px; background: var(--bg-elevated); border: 1px solid var(--border); }
.card-actions { display: flex; gap: 0.5rem; margin-top: 1rem; border-top: 1px solid var(--border); padding-top: 1rem; }
.card-actions button { padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.75rem; cursor: pointer; border: none; font-weight: 500; transition: all 0.2s; }
.action-chat { background: #3b82f6; color: #fff; }
.action-edit { background: var(--bg-elevated); color: var(--text-primary); }
.action-delete { background: #ef4444; color: #fff; }
.action-chat:hover { background: #2563eb; }
.action-edit:hover { background: var(--border); }
.action-delete:hover { background: #dc2626; }
.empty-state { text-align: center; padding: 4rem 0; grid-column: 1 / -1; }
.empty-state span { font-size: 4rem; display: block; margin-bottom: 1rem; }
.btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text-primary); padding: 0.6rem 1.5rem; border-radius: 12px; cursor: pointer; }
.modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(4px); }
.modal-content { background: var(--bg); border-radius: 24px; width: 90%; max-width: 540px; max-height: 90vh; overflow: hidden; animation: fadeIn 0.2s ease; }
@keyframes fadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 1.5rem; border-bottom: 1px solid var(--border); }
.modal-header h2 { font-size: 1.3rem; font-weight: 600; }
.modal-close { background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--text-secondary); }
.modal-close:hover { color: #ef4444; }
.modal-body { padding: 1.5rem; overflow-y: auto; max-height: 60vh; }
.field-group { margin-bottom: 1.25rem; }
.field-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.85rem; color: var(--text-secondary); }
.field-group input, .field-group textarea, .field-group select { width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: 12px; background: var(--bg-card); color: var(--text-primary); font-size: 0.9rem; }
.field-group input:focus, .field-group textarea:focus, .field-group select:focus { outline: none; border-color: #3b82f6; }
.modal-footer { display: flex; gap: 1rem; justify-content: flex-end; padding: 1rem 1.5rem 1.5rem; border-top: 1px solid var(--border); }
.btn-cancel { background: var(--bg-elevated); border: 1px solid var(--border); padding: 0.6rem 1.2rem; border-radius: 40px; cursor: pointer; font-weight: 500; color: var(--text-secondary); }
.btn-save { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; border: none; padding: 0.6rem 1.2rem; border-radius: 40px; cursor: pointer; font-weight: 600; transition: all 0.2s; }
.btn-save:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }
.chat-modal { max-width: 600px; }
.chat-context { padding: 0.75rem 1.5rem; background: var(--bg-elevated); border-bottom: 1px solid var(--border); }
.context-badge { font-size: 0.85rem; color: var(--text-secondary); }
.chat-messages { max-height: 300px; overflow-y: auto; padding: 1.5rem; }
.chat-messages .message { display: flex; gap: 0.75rem; margin-bottom: 0.75rem; }
.chat-messages .message.user { flex-direction: row-reverse; }
.chat-messages .message.user .bubble { background: #3b82f6; color: #fff; }
.chat-messages .message.assistant .bubble { background: var(--bg-elevated); border: 1px solid var(--border); }
.chat-messages .bubble { padding: 0.6rem 1rem; border-radius: 12px; max-width: 75%; }
.chat-input { display: flex; gap: 0.75rem; padding: 1rem 1.5rem; border-top: 1px solid var(--border); }
.chat-input input { flex: 1; padding: 0.6rem 1rem; border: 1px solid var(--border); border-radius: 12px; background: var(--bg-card); color: var(--text-primary); }
.chat-input input:focus { outline: none; border-color: #3b82f6; }
.chat-input button { padding: 0.6rem 1.5rem; border: none; border-radius: 12px; background: #3b82f6; color: #fff; font-weight: 600; cursor: pointer; }
.chat-input button:hover { background: #2563eb; }
</style>
