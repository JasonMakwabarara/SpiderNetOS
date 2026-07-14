<template>
  <div class="orchestration-page">
    <div class="page-header">
      <h1>🤖 Multi-Agent Orchestration</h1>
      <p>Coordinate multiple AI agents to work together on complex tasks</p>
    </div>

    <div class="orchestration-container">
      <div class="task-input">
        <label>Task Description</label>
        <textarea v-model="task" placeholder="Describe what you want the agents to accomplish..." rows="4"></textarea>
      </div>

      <div class="agent-selection">
        <h3>Select Agents</h3>
        <div class="agent-checkboxes">
          <label v-for="agent in agents" :key="agent.id" class="agent-checkbox">
            <input type="checkbox" v-model="selectedAgents" :value="agent.id" />
            <span>{{ agent.name }}</span>
            <span class="status-badge" :class="agent.status">{{ agent.status }}</span>
          </label>
        </div>
      </div>

      <button @click="orchestrate" class="btn-orchestrate" :disabled="!task || selectedAgents.length === 0 || loading">
        {{ loading ? '⏳ Orchestrating...' : '🚀 Orchestrate Now' }}
      </button>

      <div v-if="result" class="result-container">
        <h3>📋 Result</h3>
        <pre>{{ result }}</pre>
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
      selectedAgents: [],
      task: '',
      result: null,
      loading: false
    }
  },
  mounted() {
    this.fetchAgents()
  },
  methods: {
    async fetchAgents() {
      try {
        const { data } = await api.get('/agents')
        this.agents = data.filter(a => a.status === 'active')
      } catch (e) {
        console.error('Failed to fetch agents:', e)
      }
    },
    async orchestrate() {
      if (!this.task || this.selectedAgents.length === 0) return
      this.loading = true
      this.result = null
      try {
        const { data } = await api.post('/orchestrate', {
          task: this.task,
          agent_ids: this.selectedAgents
        })
        this.result = data.response || data.message || JSON.stringify(data, null, 2)
      } catch (e) {
        this.result = '❌ Error: ' + e.message
      } finally {
        this.loading = false
      }
    }
  }
}
</script>

<style scoped>
.orchestration-page { padding: 2rem; max-width: 900px; margin: 0 auto; }
.page-header { margin-bottom: 2rem; }
.page-header h1 { font-size: 2rem; font-weight: 700; background: linear-gradient(135deg, #8b5cf6, #6d28d9); -webkit-background-clip: text; background-clip: text; color: transparent; }
.orchestration-container { display: flex; flex-direction: column; gap: 1.5rem; }
.task-input textarea { width: 100%; padding: 1rem; border: 1px solid var(--border); border-radius: 12px; background: var(--bg-card); color: var(--text-primary); font-size: 1rem; resize: vertical; }
.agent-selection { background: var(--bg-card); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border); }
.agent-checkboxes { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.8rem; margin-top: 0.5rem; }
.agent-checkbox { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem; border-radius: 8px; background: var(--bg-elevated); cursor: pointer; }
.agent-checkbox:hover { background: var(--border); }
.status-badge { font-size: 0.6rem; padding: 0.2rem 0.6rem; border-radius: 20px; text-transform: uppercase; }
.status-badge.active { background: #22c55e; color: white; }
.status-badge.inactive { background: #ef4444; color: white; }
.btn-orchestrate { background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: white; border: none; padding: 0.8rem 2rem; border-radius: 12px; font-size: 1.1rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.btn-orchestrate:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(139,92,246,0.3); }
.btn-orchestrate:disabled { opacity: 0.5; cursor: not-allowed; }
.result-container { background: var(--bg-card); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border); margin-top: 1rem; }
.result-container pre { white-space: pre-wrap; word-wrap: break-word; margin: 0; }
</style>
