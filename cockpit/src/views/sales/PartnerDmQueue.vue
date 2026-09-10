<template>
  <div class="px-6 py-7 max-w-5xl mx-auto">
    <div class="mb-6 flex items-start justify-between gap-4">
      <div>
        <div class="sn-eyebrow">Operate · Sales · Partners</div>
        <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">DM queue</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">
          Prospects without an email get a drafted message. Copy it, send it yourself in the app, then mark it sent.
          When they answer, paste the reply so the thread continues here.
        </p>
      </div>
      <RouterLink to="/sales/partners" class="px-4 py-2 rounded-lg text-sm border" style="border-color: var(--border); color: var(--text-secondary);">Back to prospects</RouterLink>
    </div>

    <p v-if="loading" class="text-sm" style="color: var(--text-muted);">Loading…</p>
    <p v-else-if="!store.dmQueue.length" class="text-sm" style="color: var(--text-muted);">Nothing waiting. Drafts appear here a minute after a prospect without an email is imported.</p>

    <div v-for="item in store.dmQueue" :key="item.message_id" class="sn-card p-5 mb-4 space-y-3">
      <div class="flex items-start justify-between gap-3">
        <div>
          <div class="font-medium" style="color: var(--text-primary);">{{ item.prospect?.display_name || item.prospect?.handle || 'Prospect' }}</div>
          <a v-if="item.prospect?.profile_url" :href="item.prospect.profile_url" target="_blank" rel="noopener noreferrer" class="text-xs underline" style="color: var(--accent);">
            Open {{ item.prospect.platform }} profile
          </a>
        </div>
        <span class="text-[11px] px-2 py-1 rounded-full dct-pill-cyan">{{ item.template_key }}</span>
      </div>

      <textarea v-model="drafts[item.message_id]" rows="4" class="sn-input w-full text-sm"></textarea>
      <p class="text-xs" style="color: var(--text-muted);">{{ (drafts[item.message_id] || '').length }} characters</p>

      <div class="flex flex-wrap gap-2">
        <button type="button" class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold" @click="copy(item)">{{ copied === item.message_id ? 'Copied' : 'Copy' }}</button>
        <button type="button" class="px-4 py-2 rounded-lg text-sm border" style="border-color: var(--border); color: var(--text-secondary);" :disabled="busy === item.message_id" @click="markSent(item)">Mark sent</button>
        <button type="button" class="px-4 py-2 rounded-lg text-sm border" style="border-color: var(--border); color: var(--text-muted);" @click="replyFor = replyFor === item.message_id ? null : item.message_id">Paste reply</button>
      </div>

      <div v-if="replyFor === item.message_id" class="space-y-2">
        <textarea v-model="replies[item.message_id]" rows="3" class="sn-input w-full text-sm" placeholder="Paste what the creator wrote back"></textarea>
        <button type="button" class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold" :disabled="!replies[item.message_id] || busy === item.message_id" @click="sendReply(item)">Save reply</button>
      </div>
      <p v-if="errors[item.message_id]" class="text-xs" style="color: #FF6B6B;">{{ errors[item.message_id] }}</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { usePartnersStore } from '../../stores/partners.js'

const store = usePartnersStore()
const loading = ref(true)
const drafts = reactive({})
const replies = reactive({})
const errors = reactive({})
const copied = ref(null)
const busy = ref(null)
const replyFor = ref(null)

async function load() {
  loading.value = true
  try {
    const items = await store.fetchDmQueue()
    for (const item of items) drafts[item.message_id] = item.body
  } finally {
    loading.value = false
  }
}

async function copy(item) {
  try {
    await navigator.clipboard.writeText(drafts[item.message_id] || item.body)
    copied.value = item.message_id
    setTimeout(() => { if (copied.value === item.message_id) copied.value = null }, 1500)
  } catch {
    errors[item.message_id] = 'Clipboard blocked; select the text and copy manually.'
  }
}

async function markSent(item) {
  busy.value = item.message_id
  errors[item.message_id] = ''
  try {
    await store.markDmSent(item.message_id)
  } catch (err) {
    errors[item.message_id] = err?.response?.data?.message || 'Could not mark as sent'
  } finally {
    busy.value = null
  }
}

async function sendReply(item) {
  if (!item.prospect?.id) return
  busy.value = item.message_id
  errors[item.message_id] = ''
  try {
    await store.dmReply(item.prospect.id, replies[item.message_id])
    replies[item.message_id] = ''
    replyFor.value = null
    await load()
  } catch (err) {
    errors[item.message_id] = err?.response?.data?.message || 'Could not save the reply'
  } finally {
    busy.value = null
  }
}

onMounted(load)
</script>
