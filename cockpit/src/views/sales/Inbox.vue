<template>
  <div class="px-6 py-7 max-w-6xl mx-auto">
    <div class="mb-6">
      <div class="sn-eyebrow">Lead-to-Sale Funnel · Inbox</div>
      <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Conversations</h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">Every reply from a lead on email or WhatsApp.</p>
    </div>

    <section class="grid grid-cols-1 lg:grid-cols-[360px_1fr] gap-4 min-h-[60vh]">
      <aside class="sn-card overflow-hidden flex flex-col">
        <div class="px-3 py-2 border-b text-xs uppercase tracking-widest font-semibold" style="border-color: var(--border); color: var(--text-muted);">
          Conversations · {{ conversations.length }}
        </div>
        <ul class="flex-1 overflow-y-auto divide-y" style="border-color: var(--border);">
          <li v-if="loading" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">Loading…</li>
          <li v-else-if="!conversations.length" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">No conversations yet.</li>
          <li
            v-for="c in conversations" :key="c.id"
            class="px-3 py-2.5 cursor-pointer"
            :style="selectedId === c.id ? 'background: var(--accent-weak); border-left: 2px solid var(--accent);' : 'border-left: 2px solid transparent;'"
            @click="select(c.id)"
          >
            <div class="flex items-center gap-2 mb-1">
              <span class="sn-pill text-[10px]">{{ c.channel }}</span>
              <span class="ml-auto sn-pill text-[10px]">{{ c.status }}</span>
            </div>
            <div class="text-sm truncate" style="color: var(--text-primary);">{{ c.lead?.name || c.lead?.email || c.lead?.whatsapp_number || 'Lead' }}</div>
            <div class="text-[11px] mt-0.5" style="color: var(--text-muted);">{{ timeAgo(c.last_message_at) }}</div>
          </li>
        </ul>
      </aside>

      <article class="sn-card p-0 overflow-hidden flex flex-col">
        <template v-if="active">
          <header class="px-5 py-3 border-b" style="border-color: var(--border);">
            <div class="text-sm font-medium" style="color: var(--text-primary);">
              {{ active.lead?.name || active.lead?.email || active.lead?.whatsapp_number }}
            </div>
            <div class="text-xs" style="color: var(--text-muted);">{{ active.channel }} · {{ active.lead?.stage }}</div>
          </header>
          <div class="flex-1 overflow-y-auto p-4 space-y-3">
            <div v-for="m in active.messages" :key="m.id" class="flex" :class="m.direction === 'out' ? 'justify-end' : 'justify-start'">
              <div class="max-w-[70%] rounded-lg px-3 py-2 text-sm"
                   :style="m.direction === 'out'
                     ? 'background: var(--accent-weak); color: var(--text-primary);'
                     : 'background: var(--bg-elevated); color: var(--text-primary);'">
                {{ m.body }}
                <div class="text-[10px] mt-1" style="color: var(--text-muted);">{{ m.status }} · {{ timeAgo(m.created_at) }}</div>
              </div>
            </div>
          </div>
          <footer class="p-3 border-t flex gap-2" style="border-color: var(--border);">
            <input
              v-model="draft"
              type="text"
              class="flex-1 rounded-lg px-3 py-2 text-sm"
              style="background: var(--bg-elevated); border: 1px solid var(--border); color: var(--text-primary);"
              placeholder="Type a reply…"
              @keydown.enter="reply"
            />
            <button class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold disabled:opacity-50" :disabled="!draft.trim() || sending" @click="reply">
              Send
            </button>
          </footer>
        </template>
        <div v-else class="flex-1 flex items-center justify-center text-sm" style="color: var(--text-muted);">
          Select a conversation
        </div>
      </article>
    </section>
    <p v-if="error" class="text-xs mt-4" style="color: #FF6B6B;">{{ error }}</p>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../services/api.js'

const conversations = ref([])
const active = ref(null)
const selectedId = ref(null)
const loading = ref(true)
const sending = ref(false)
const error = ref('')
const draft = ref('')

onMounted(fetchList)

async function fetchList() {
  loading.value = true
  try {
    const { data } = await api.get('/api/sales/conversations')
    conversations.value = data?.data?.data || data?.data || []
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to load conversations'
  } finally {
    loading.value = false
  }
}

async function select(id) {
  selectedId.value = id
  try {
    const { data } = await api.get(`/api/sales/conversations/${id}`)
    active.value = data.data
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to load conversation'
  }
}

async function reply() {
  if (!draft.value.trim() || !active.value) return
  sending.value = true
  error.value = ''
  try {
    await api.post(`/api/sales/conversations/${active.value.id}/reply`, { body: draft.value.trim() })
    draft.value = ''
    await select(active.value.id)
    await fetchList()
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to send reply'
  } finally {
    sending.value = false
  }
}

function timeAgo(dateStr) {
  if (!dateStr) return ''
  const diffMs = Date.now() - new Date(dateStr).getTime()
  const mins = Math.floor(diffMs / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins}m ago`
  const hours = Math.floor(mins / 60)
  if (hours < 24) return `${hours}h ago`
  return `${Math.floor(hours / 24)}d ago`
}
</script>

<style scoped>
.sn-eyebrow { font-size: 11px; letter-spacing: 0.18em; text-transform: uppercase; color: var(--accent); }
.sn-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; }
.sn-btn { background: linear-gradient(135deg, #00E5C8, #087D6E); color: #05070A; }
.sn-pill { background: var(--accent-weak); color: var(--accent); border: 1px solid rgba(0,229,200,0.30); padding: 2px 8px; border-radius: 999px; }
</style>
