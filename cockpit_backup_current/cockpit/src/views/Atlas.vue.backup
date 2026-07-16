<template>
  <div class="atlas-container">
    <div class="page-header">
      <h1>🗣️ Atlas – General AI Assistant</h1>
      <p>I'm your AI Operating System assistant. I can help you create agents, manage flows, and answer questions about the platform.</p>
    </div>
    <div class="chat-wrapper">
      <div class="chat-messages" ref="messagesContainer">
        <div v-for="msg in messages" :key="msg.id" :class="['message', msg.role]">
          <div class="avatar">{{ msg.role === 'user' ? '👤' : '🗣️' }}</div>
          <div class="bubble" v-html="formatMessage(msg.content)"></div>
        </div>
        <div v-if="loading" class="message assistant"><div class="avatar">🗣️</div><div class="bubble typing">...</div></div>
      </div>
      <div class="chat-input-area">
        <div class="suggestions">
          <button v-for="s in suggestions" :key="s" @click="sendSuggestion(s)">{{ s }}</button>
        </div>
        <div class="input-group">
          <input v-model="inputMessage" @keyup.enter="sendMessage" placeholder='Try "create agent SalesBot" or "create flow DataPipeline"' />
          <button @click="sendMessage" class="send-btn">Send</button>
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
      messages: [],
      inputMessage: '',
      loading: false,
      suggestions: ['create agent SalesBot', 'create flow DataPipeline', 'create email flow Newsletter to email@example.com', '/agents', '/help']
    }
  },
  mounted() {
    this.messages.push({
      id: Date.now(),
      role: 'assistant',
      content: this.getWelcomeMessage()
    })
  },
  methods: {
    getWelcomeMessage() {
      return `👋 **Hello! I'm Atlas, your AI Operating System assistant.**

I can help you:
• Create task-specific agents (Sales, Support, Data Analyst, etc.)
• Build automated workflows
• Monitor your AI workspace
• Answer questions about SpiderNetOS

**Try these commands:**
• \`create agent SalesBot\`
• \`create flow DataPipeline\`
• \`create email flow Newsletter to email@example.com\`
• \`create slack flow Alerts webhook https://hooks.slack.com/...\`
• \`/agents\`
• \`/flows\`
• \`/status\`
• \`/help\``
    },
    formatMessage(content) {
      return content.replace(/\n/g, '<br>')
    },
    scrollToBottom() {
      this.$nextTick(() => {
        const container = this.$refs.messagesContainer
        if (container) container.scrollTop = container.scrollHeight
      })
    },
    async sendSuggestion(suggestion) {
      this.inputMessage = suggestion
      await this.sendMessage()
    },
    async sendMessage() {
      if (!this.inputMessage.trim()) return
      
      const userMsg = { id: Date.now(), role: 'user', content: this.inputMessage }
      this.messages.push(userMsg)
      const command = this.inputMessage
      this.inputMessage = ''
      this.loading = true
      this.scrollToBottom()
      
      try {
        const response = await api.post('/atlas/chat', { message: command })
        const data = response.data
        this.messages.push({ id: Date.now(), role: 'assistant', content: data.response || data.message || 'No response' })
      } catch(err) {
        console.error('Error:', err)
        this.messages.push({ id: Date.now(), role: 'assistant', content: `❌ Error: ${err.response?.data?.message || err.message}` })
      } finally {
        this.loading = false
        this.scrollToBottom()
      }
    }
  }
}
</script>

<style scoped>
.atlas-container {
  padding: 2rem;
  max-width: 1200px;
  margin: 0 auto;
  min-height: 100vh;
}
.page-header {
  margin-bottom: 2rem;
}
.page-header h1 {
  font-size: 2rem;
  font-weight: 700;
  background: linear-gradient(135deg, #3b82f6, #8b5cf6);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}
.page-header p {
  color: var(--text-secondary);
  font-size: 0.95rem;
  margin-top: 0.5rem;
}
.chat-wrapper {
  display: flex;
  flex-direction: column;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 16px;
  overflow: hidden;
}
.chat-messages {
  flex: 1;
  overflow-y: auto;
  padding: 1.5rem;
  min-height: 400px;
  max-height: 500px;
}
.message {
  display: flex;
  margin-bottom: 1rem;
  gap: 0.75rem;
}
.message.user {
  flex-direction: row-reverse;
}
.message.user .bubble {
  background: linear-gradient(135deg, #3b82f6, #2563eb);
  color: #fff;
}
.message.assistant .bubble {
  background: var(--bg-elevated);
  color: var(--text-primary);
  border: 1px solid var(--border);
}
.avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bg-elevated);
  flex-shrink: 0;
  font-size: 1.1rem;
}
.bubble {
  padding: 0.75rem 1.25rem;
  border-radius: 16px;
  max-width: 75%;
  word-wrap: break-word;
  white-space: pre-wrap;
}
.bubble.typing {
  opacity: 0.6;
}
.chat-input-area {
  padding: 1rem 1.5rem;
  border-top: 1px solid var(--border);
  background: var(--bg-elevated);
}
.suggestions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  margin-bottom: 0.75rem;
}
.suggestions button {
  padding: 0.3rem 0.8rem;
  border-radius: 20px;
  border: 1px solid var(--border);
  background: var(--bg-card);
  color: var(--text-primary);
  cursor: pointer;
  font-size: 0.8rem;
  transition: all 0.2s;
}
.suggestions button:hover {
  background: #3b82f6;
  border-color: #3b82f6;
  color: #fff;
}
.input-group {
  display: flex;
  gap: 0.75rem;
}
.input-group input {
  flex: 1;
  padding: 0.6rem 1rem;
  border-radius: 12px;
  border: 1px solid var(--border);
  background: var(--bg-card);
  color: var(--text-primary);
  outline: none;
  font-size: 0.95rem;
}
.input-group input:focus {
  border-color: #3b82f6;
}
.input-group input::placeholder {
  color: var(--text-secondary);
}
.send-btn {
  padding: 0.6rem 1.5rem;
  border-radius: 12px;
  border: none;
  background: linear-gradient(135deg, #3b82f6, #2563eb);
  color: #fff;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}
.send-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(59,130,246,0.3);
}
</style>
