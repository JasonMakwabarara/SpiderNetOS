<template>
  <div class="px-6 py-7 max-w-4xl mx-auto">
    <div class="mb-6 flex items-start justify-between gap-4">
      <div v-if="prospect">
        <div class="sn-eyebrow">Operate · Sales · Partners</div>
        <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ prospect.display_name || prospect.handle || 'Prospect' }}</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">
          <a :href="prospect.profile_url" target="_blank" rel="noopener noreferrer" class="underline" style="color: var(--accent);">{{ prospect.platform }} profile</a>
          · <span class="text-[11px] px-2 py-1 rounded-full dct-pill-cyan">{{ prospect.status.replace('_', ' ') }}</span>
          · {{ prospect.lead?.email || 'no email' }} · step {{ prospect.sequence_step }}
        </p>
      </div>
      <RouterLink to="/sales/partners" class="px-4 py-2 rounded-lg text-sm border" style="border-color: var(--border); color: var(--text-secondary);">Back</RouterLink>
    </div>

    <p v-if="loading" class="text-sm" style="color: var(--text-muted);">Loading…</p>
    <p v-else-if="!conversations.length" class="text-sm mb-6" style="color: var(--text-muted);">No messages yet.</p>

    <div v-for="c in conversations" :key="c.id" class="sn-card p-5 mb-4">
      <p class="text-xs uppercase tracking-wider mb-3" style="color: var(--text-muted);">{{ c.channel === 'manual_dm' ? 'Direct messages (sent by you)' : c.channel }} · {{ c.status }}</p>
      <div v-for="m in c.messages" :key="m.id" class="mb-3 p-3 rounded-lg" :style="{ background: m.direction === 'in' ? 'var(--bg-elevated)' : 'var(--surface-low)', border: '1px solid var(--border)' }">
        <div class="flex items-center gap-2 text-[11px] mb-1" style="color: var(--text-muted);">
          <span class="font-semibold" :style="{ color: m.direction === 'in' ? 'var(--accent)' : 'var(--text-secondary)' }">{{ m.direction === 'in' ? 'Creator' : (m.sent_by === 'outreach' ? 'Sequence' : 'You') }}</span>
          <span v-if="m.classification" class="px-2 py-0.5 rounded-full" :class="m.classification === 'bounce' || m.classification === 'opt_out' ? 'dct-pill-pink' : 'dct-pill-cyan'">{{ m.classification }}</span>
          <span>{{ m.status }}</span>
          <span>{{ fmt(m.sent_at || m.created_at) }}</span>
        </div>
        <p v-if="m.subject" class="text-xs font-medium mb-1" style="color: var(--text-secondary);">{{ m.subject }}</p>
        <p class="text-sm whitespace-pre-line" style="color: var(--text-primary);">{{ m.body }}</p>
      </div>
    </div>

    <form v-if="prospect" class="sn-card p-5 space-y-3" @submit.prevent="send">
      <h2 class="font-medium" style="color: var(--text-primary);">Reply</h2>
      <div class="flex gap-3 items-center">
        <select v-model="channel" class="sn-input">
          <option value="email" :disabled="!prospect.lead?.email">Email (from your mailbox)</option>
          <option value="manual_dm">Direct message (draft for the DM queue)</option>
        </select>
        <input v-if="channel === 'email'" v-model="subject" class="sn-input flex-1" placeholder="Subject (defaults to Re: the last one)" />
      </div>
      <textarea v-model="body" rows="5" class="sn-input w-full text-sm" placeholder="Write as yourself; the legal footer and threading are added automatically."></textarea>
      <div class="flex items-center gap-3">
        <button type="submit" class="sn-btn px-5 py-2 rounded-lg text-sm font-semibold" :disabled="!body || sending">{{ sending ? 'Sending…' : (channel === 'email' ? 'Send email' : 'Queue DM draft') }}</button>
        <span v-if="notice" class="text-sm" :style="{ color: noticeError ? '#FF6B6B' : '#3ddc97' }">{{ notice }}</span>
      </div>
    </form>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { usePartnersStore } from '../../stores/partners.js'

const route = useRoute()
const store = usePartnersStore()
const prospect = ref(null)
const conversations = ref([])
const loading = ref(true)
const channel = ref('email')
const subject = ref('')
const body = ref('')
const sending = ref(false)
const notice = ref('')
const noticeError = ref(false)

async function load() {
  loading.value = true
  try {
    const res = await store.fetchProspect(route.params.id)
    prospect.value = res.data
    conversations.value = res.conversations || []
    if (!prospect.value?.lead?.email) channel.value = 'manual_dm'
  } finally {
    loading.value = false
  }
}

async function send() {
  sending.value = true
  notice.value = ''
  noticeError.value = false
  try {
    await store.reply(prospect.value.id, body.value, channel.value, subject.value || null)
    body.value = ''
    notice.value = channel.value === 'email' ? 'Sent.' : 'Draft queued; find it in the DM queue.'
    await load()
  } catch (err) {
    noticeError.value = true
    notice.value = err?.response?.data?.message || 'Could not send.'
  } finally {
    sending.value = false
  }
}

function fmt(v) { return v ? new Date(v).toLocaleString() : '' }

onMounted(load)
</script>
