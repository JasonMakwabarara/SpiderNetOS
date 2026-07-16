<template>
  <div class="p-6">
    <div class="flex items-center gap-3 mb-6">
      <button @click="$router.back()" class="text-gray-500 hover:text-gray-700">? Back</button>
      <h1 class="text-2xl font-bold">?? Chat with {{ agent?.name || 'Agent' }}</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
      <div v-if="loading" class="text-center py-8">Loading...</div>
      <div v-else-if="!agent" class="text-center py-8 text-red-500">Agent not found</div>
      <div v-else>
        <!-- Agent Info -->
        <div class="mb-4 p-3 bg-gray-50 rounded">
          <p><strong>Name:</strong> {{ agent.name }}</p>
          <p><strong>Description:</strong> {{ agent.description || 'No description' }}</p>
          <p><strong>Status:</strong> <span :class="{'text-green-600': agent.status === 'active', 'text-gray-600': agent.status !== 'active'}">{{ agent.status }}</span></p>
        </div>

        <!-- Chat Messages -->
        <div class="chat-messages border rounded p-4 mb-4 h-96 overflow-y-auto" ref="messagesContainer">
          <div v-if="messages.length === 0" class="text-center py-8 text-gray-500">
            No messages yet. Start a conversation!
          </div>
          <div v-for="(msg, index) in messages" :key="index" class="mb-3">
            <div :class="['flex', msg.role === 'user' ? 'justify-end' : 'justify-start']">
              <div :class="[
                'max-w-[70%] p-3 rounded-lg',
                msg.role === 'user' ? 'bg-blue-500 text-white' : 'bg-gray-100 text-gray-800'
              ]">
                <p class="whitespace-pre-wrap">{{ msg.content }}</p>
                <span class="text-xs opacity-70 mt-1 block">{{ new Date(msg.timestamp).toLocaleTimeString() }}</span>
              </div>
            </div>
          </div>
          <div v-if="sending" class="text-center text-gray-500 py-2">Agent is typing...</div>
        </div>

        <!-- Input Area -->
        <div class="flex gap-2">
          <input 
            v-model="newMessage" 
            @keyup.enter="sendMessage" 
            type="text" 
            placeholder="Type your message..." 
            class="flex-1 p-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <button 
            @click="sendMessage" 
            :disabled="!newMessage.trim() || sending"
            class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 disabled:bg-gray-400"
          >
            Send
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import api from '../services/api.js'

export default {
  data() {
    return {
      agent: null,
      messages: [],
      newMessage: '',
      loading: false,
      sending: false,
      agentId: null
    }
  },
  mounted() {
    this.agentId = this.$route.params.id
    if (this.agentId) {
      this.fetchAgent()
      this.loadMessages()
    }
  },
  methods: {
    async fetchAgent() {
      this.loading = true
      try {
        const { data } = await api.get(`/agents/${this.agentId}`)
        this.agent = data
      } catch (error) {
        console.error('Error fetching agent:', error)
        alert('Failed to load agent')
      } finally {
        this.loading = false
      }
    },
    loadMessages() {
      // Load from localStorage or start fresh
      const saved = localStorage.getItem(`chat_${this.agentId}`)
      if (saved) {
        try {
          this.messages = JSON.parse(saved)
        } catch {
          this.messages = []
        }
      } else {
        this.messages = [
          { 
            role: 'assistant', 
            content: `Hello! I'm ${this.agent?.name || 'the agent'}. How can I help you today?`,
            timestamp: new Date().toISOString()
          }
        ]
      }
    },
    saveMessages() {
      localStorage.setItem(`chat_${this.agentId}`, JSON.stringify(this.messages))
    },
    async sendMessage() {
      if (!this.newMessage.trim() || this.sending) return
      
      const message = this.newMessage.trim()
      this.newMessage = ''
      
      // Add user message
      this.messages.push({
        role: 'user',
        content: message,
        timestamp: new Date().toISOString()
      })
      this.saveMessages()
      this.scrollToBottom()
      
      // Send to API
      this.sending = true
      try {
        const { data } = await api.post('/agents/chat', {
          agent_id: this.agentId,
          message: message
        })
        
        // Add assistant response
        this.messages.push({
          role: 'assistant',
          content: data.response || 'I received your message!',
          timestamp: new Date().toISOString()
        })
        this.saveMessages()
        this.scrollToBottom()
      } catch (error) {
        console.error('Chat error:', error)
        this.messages.push({
          role: 'assistant',
          content: '? Error: Failed to get response. Please try again.',
          timestamp: new Date().toISOString()
        })
        this.saveMessages()
      } finally {
        this.sending = false
      }
    },
    scrollToBottom() {
      this.$nextTick(() => {
        const container = this.$refs.messagesContainer
        if (container) {
          container.scrollTop = container.scrollHeight
        }
      })
    }
  }
}
</script>

<style scoped>
.chat-messages {
  scroll-behavior: smooth;
}
</style>
