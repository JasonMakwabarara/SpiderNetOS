<template>
  <div class="px-6 py-7 max-w-3xl mx-auto">
    <div class="mb-6">
      <div class="sn-eyebrow">Lead-to-Sale Funnel · Setup</div>
      <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Let's build your funnel</h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">
        A few quick questions so the Funnel Architect can draft a sales script in your voice — you'll review and approve it before anything goes live.
      </p>
    </div>

    <div v-if="funnel.loading && !funnel.setup" class="text-sm" style="color: var(--text-secondary);">Loading…</div>

    <template v-else>
      <!-- Purchased, not yet started -->
      <div v-if="funnel.status === 'purchased'" class="sn-card p-6">
        <h2 class="font-medium mb-2" style="color: var(--text-primary);">Ready when you are</h2>
        <p class="text-sm mb-4" style="color: var(--text-secondary);">
          This takes about 5 minutes. Answer in your own words — the more specific, the better the script.
        </p>
        <button class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold" :disabled="funnel.loading" @click="start">
          Start discovery interview
        </button>
      </div>

      <!-- Interviewing -->
      <div v-else-if="funnel.status === 'interviewing' && funnel.next && !funnel.next.done" class="sn-card p-6">
        <div class="flex items-center justify-between mb-4">
          <span class="sn-pill text-xs">{{ funnel.next.section?.title }}</span>
          <span class="text-xs" style="color: var(--text-muted);">{{ funnel.next.progress_pct }}% complete</span>
        </div>
        <div class="w-full h-1.5 rounded-full mb-5" style="background: var(--bg-elevated);">
          <div class="h-1.5 rounded-full transition-all" :style="`width: ${funnel.next.progress_pct}%; background: var(--accent);`"></div>
        </div>
        <p class="text-base font-medium mb-4" style="color: var(--text-primary);">{{ funnel.next.question?.prompt }}</p>
        <textarea
          v-model="draftAnswer"
          rows="3"
          class="w-full rounded-lg p-3 text-sm mb-4"
          style="background: var(--bg-elevated); border: 1px solid var(--border); color: var(--text-primary);"
          placeholder="Type your answer…"
          @keydown.enter.meta="submitAnswer"
        ></textarea>
        <button class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold disabled:opacity-50" :disabled="!draftAnswer.trim() || funnel.loading" @click="submitAnswer">
          {{ funnel.loading ? 'Saving…' : 'Next' }}
        </button>
      </div>

      <!-- Interview done, ready to draft -->
      <div v-else-if="funnel.status === 'interviewing' && funnel.next?.done" class="sn-card p-6">
        <h2 class="font-medium mb-2" style="color: var(--text-primary);">That's everything</h2>
        <p class="text-sm mb-4" style="color: var(--text-secondary);">
          The Funnel Architect will draft your sales script now — you'll get to review and edit every line before it goes anywhere.
        </p>
        <button class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold disabled:opacity-50" :disabled="funnel.loading" @click="draft">
          {{ funnel.loading ? 'Drafting…' : 'Draft my sales script' }}
        </button>
      </div>

      <!-- Script drafted / awaiting approval / live -->
      <div v-else class="sn-card p-6">
        <h2 class="font-medium mb-2" style="color: var(--text-primary);">{{ statusHeadline }}</h2>
        <p class="text-sm mb-4" style="color: var(--text-secondary);">{{ statusBody }}</p>
        <RouterLink to="/sales/script-studio" class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold inline-block">
          Open Script Studio →
        </RouterLink>
      </div>
    </template>

    <p v-if="funnel.error" class="text-xs mt-4" style="color: #FF6B6B;">{{ funnel.error }}</p>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useFunnelStore } from '../../stores/funnel.js'

const funnel = useFunnelStore()
const draftAnswer = ref('')

onMounted(() => funnel.fetchStatus())

const statusHeadline = computed(() => ({
  script_drafted: 'Your draft script is ready',
  awaiting_approval: 'Waiting on your approval',
  approved: 'Approved — activating your funnel',
  live: 'Your funnel is live',
  rejected: "Let's revise the script",
}[funnel.status] || 'Funnel setup'))

const statusBody = computed(() => ({
  script_drafted: 'Review it in Script Studio and submit it for your own approval when it sounds right.',
  awaiting_approval: 'Approve or request changes from the Approvals page or Script Studio.',
  approved: 'Activating pack agents and templates now.',
  live: 'Leads are being captured, scored, and followed up on email and WhatsApp using your approved script.',
  rejected: 'Head back to Script Studio to request a new draft.',
}[funnel.status] || ''))

async function start() {
  await funnel.startInterview()
}

async function submitAnswer() {
  if (!draftAnswer.value.trim()) return
  const questionId = funnel.next.question.id
  const result = await funnel.answer(questionId, draftAnswer.value.trim())
  if (result.success) draftAnswer.value = ''
}

async function draft() {
  await funnel.draftScript()
}
</script>

<style scoped>
.sn-eyebrow { font-size: 11px; letter-spacing: 0.18em; text-transform: uppercase; color: var(--accent); }
.sn-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; }
.sn-btn { background: linear-gradient(135deg, #00E5C8, #087D6E); color: #05070A; }
.sn-pill { background: var(--accent-weak); color: var(--accent); border: 1px solid rgba(0,229,200,0.30); padding: 2px 10px; border-radius: 999px; }
</style>
