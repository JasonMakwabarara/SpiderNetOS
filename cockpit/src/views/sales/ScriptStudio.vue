<template>
  <div class="px-6 py-7 max-w-5xl mx-auto">
    <div class="mb-6 flex items-start justify-between gap-4">
      <div>
        <div class="sn-eyebrow">Lead-to-Sale Funnel · Script Studio</div>
        <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Your sales script</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">
          Edit anything that doesn't sound like you. Nothing goes live until you approve it.
        </p>
      </div>
      <span class="sn-pill text-xs">{{ funnel.status.replace('_', ' ') }}</span>
    </div>

    <div v-if="!script" class="sn-card p-6 text-sm" style="color: var(--text-secondary);">
      No script drafted yet. <RouterLink to="/sales/funnel-setup" style="color: var(--accent);">Go to the discovery interview →</RouterLink>
    </div>

    <template v-else>
      <p v-if="script.rationale" class="text-xs mb-4 italic" style="color: var(--text-muted);">{{ script.rationale }}</p>

      <div class="grid md:grid-cols-2 gap-4 mb-6">
        <div v-for="channel in ['email', 'whatsapp']" :key="channel" class="sn-card p-4">
          <h2 class="text-sm font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">{{ channel }}</h2>
          <div v-for="field in ['opener', 'qualify', 'objections', 'close']" :key="field" class="mb-3">
            <label class="text-xs font-medium block mb-1" style="color: var(--text-secondary);">{{ field }}</label>
            <textarea
              v-model="content[channel][field]"
              rows="2"
              class="w-full rounded-lg p-2 text-sm"
              style="background: var(--bg-elevated); border: 1px solid var(--border); color: var(--text-primary);"
              :disabled="!isEditable"
            ></textarea>
          </div>
          <div>
            <label class="text-xs font-medium block mb-1" style="color: var(--text-secondary);">follow-ups</label>
            <textarea
              v-for="(fu, idx) in content[channel].followups"
              :key="idx"
              v-model="content[channel].followups[idx]"
              rows="1"
              class="w-full rounded-lg p-2 text-sm mb-1"
              style="background: var(--bg-elevated); border: 1px solid var(--border); color: var(--text-primary);"
              :disabled="!isEditable"
            ></textarea>
          </div>
        </div>
      </div>

      <div class="flex items-center gap-3">
        <button v-if="isEditable" class="sn-btn-outline px-4 py-2 rounded-lg text-sm font-semibold" :disabled="saving" @click="saveEdits">
          {{ saving ? 'Saving…' : 'Save edits' }}
        </button>
        <button v-if="script.status === 'draft'" class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold" :disabled="saving" @click="submit">
          Submit for my approval →
        </button>
        <span v-if="script.status === 'submitted'" class="text-sm" style="color: var(--text-secondary);">
          Waiting on approval — review it on the <RouterLink to="/approvals" style="color: var(--accent);">Approvals page</RouterLink>.
        </span>
        <button v-if="script.status === 'submitted'" class="sn-btn-outline px-4 py-2 rounded-lg text-sm font-semibold" @click="requestRevision">
          Request a different draft
        </button>
        <span v-if="script.status === 'approved'" class="text-sm font-medium" style="color: var(--accent);">
          Approved — your funnel is live.
        </span>
      </div>
      <p v-if="error" class="text-xs mt-4" style="color: #FF6B6B;">{{ error }}</p>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useFunnelStore } from '../../stores/funnel.js'

const funnel = useFunnelStore()
const saving = ref(false)
const error = ref('')
const content = ref({ email: { opener: '', qualify: '', objections: '', close: '', followups: [] }, whatsapp: { opener: '', qualify: '', objections: '', close: '', followups: [] } })

const script = computed(() => funnel.setup?.active_script || null)
const isEditable = computed(() => script.value?.status === 'draft')

onMounted(async () => {
  await funnel.fetchStatus()
})

watch(script, (s) => {
  if (s?.content) {
    content.value = JSON.parse(JSON.stringify(s.content))
  }
}, { immediate: true })

async function saveEdits() {
  saving.value = true
  error.value = ''
  const result = await funnel.reviseScript(script.value.id, content.value)
  saving.value = false
  if (!result.success) error.value = result.error
}

async function submit() {
  saving.value = true
  error.value = ''
  await saveEdits()
  const result = await funnel.submitScript(script.value.id)
  saving.value = false
  if (!result.success) error.value = result.error
}

async function requestRevision() {
  await funnel.requestRevision()
}
</script>

<style scoped>
.sn-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; }
.sn-btn { background: linear-gradient(135deg, #00E5C8, #087D6E); color: #05070A; }
.sn-btn-outline { background: transparent; color: var(--text-primary); border: 1px solid var(--border); }
.sn-pill { background: var(--accent-weak); color: var(--accent); border: 1px solid rgba(0,229,200,0.30); padding: 2px 10px; border-radius: 999px; }
</style>
