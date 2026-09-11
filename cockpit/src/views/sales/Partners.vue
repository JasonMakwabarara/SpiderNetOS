<template>
  <div class="px-6 py-7 max-w-7xl mx-auto">
    <div class="mb-6 flex items-start justify-between gap-4">
      <div>
        <div class="sn-eyebrow">Operate · Sales · Partners</div>
        <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Partner outreach</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">
          Creators and pages invited into the affiliate program. Email goes out automatically; DMs wait for you in the queue.
        </p>
      </div>
      <div class="flex gap-2">
        <RouterLink to="/sales/partners/dm-queue" class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold">
          DM queue ({{ store.summary.dm_queue }})
        </RouterLink>
        <RouterLink to="/sales/partners/settings" class="px-4 py-2 rounded-lg text-sm border" style="border-color: var(--border); color: var(--text-secondary);">
          Settings
        </RouterLink>
        <button type="button" class="px-4 py-2 rounded-lg text-sm border" style="border-color: var(--border); color: var(--text-secondary);" @click="showImport = !showImport">
          {{ showImport ? 'Cancel' : 'Import CSV' }}
        </button>
      </div>
    </div>

    <div v-if="showImport" class="sn-card p-5 mb-6 space-y-3">
      <p class="text-sm" style="color: var(--text-secondary);">
        Upload an Affonso Finder shortlist export (Opportunity Name, Domain, Primary URL, All URLs …). Rows dedupe on the profile URL; re-importing never overwrites emails or statuses.
      </p>
      <div class="flex flex-wrap items-center gap-3">
        <input type="file" accept=".csv,text/csv" class="sn-input" @change="onFile" />
        <label class="text-sm flex items-center gap-2" style="color: var(--text-secondary);">
          <input v-model="importDryRun" type="checkbox" /> Dry run (count only)
        </label>
        <button type="button" class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold" :disabled="!importFile || importing" @click="doImport">
          {{ importing ? 'Importing…' : (importDryRun ? 'Preview' : 'Import') }}
        </button>
      </div>
      <p v-if="importReport" class="text-sm" style="color: var(--text-primary);">
        {{ importReport.rows }} rows: {{ importReport.created }} new, {{ importReport.updated }} updated,
        {{ importReport.duplicates }} duplicates, {{ importReport.invalid }} invalid, {{ importReport.with_email }} with an email
        <span v-if="importReport.dry_run">(dry run)</span>
      </p>
      <ul v-if="importReport?.errors?.length" class="text-xs" style="color: #FF6B6B;">
        <li v-for="e in importReport.errors" :key="e">{{ e }}</li>
      </ul>
    </div>

    <div class="grid md:grid-cols-5 gap-3 mb-6">
      <div v-for="tile in tiles" :key="tile.label" class="sn-card p-4">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">{{ tile.label }}</p>
        <p class="text-2xl font-bold mt-1" :style="{ color: tile.color || 'var(--text-primary)' }">{{ tile.value }}</p>
      </div>
    </div>

    <div class="flex flex-wrap gap-2 mb-4">
      <select v-model="filters.status" class="sn-input" @change="load">
        <option value="">All statuses</option>
        <option v-for="s in PROSPECT_STATUSES" :key="s" :value="s">{{ s.replace('_', ' ') }}</option>
      </select>
      <select v-model="filters.platform" class="sn-input" @change="load">
        <option value="">All platforms</option>
        <option v-for="p in ['tiktok', 'facebook', 'instagram', 'youtube', 'x', 'linkedin', 'web']" :key="p" :value="p">{{ p }}</option>
      </select>
      <select v-model="filters.has_email" class="sn-input" @change="load">
        <option value="">Email: any</option>
        <option value="1">Has email</option>
        <option value="0">No email</option>
      </select>
      <input v-model="filters.q" class="sn-input" placeholder="Search name, handle, URL" @keyup.enter="load" />
      <button type="button" class="px-3 py-2 rounded-lg text-sm border" style="border-color: var(--border); color: var(--text-secondary);" :disabled="ticking" @click="tick">
        {{ ticking ? 'Running…' : 'Dry-run tick' }}
      </button>
    </div>
    <p v-if="tickResult" class="text-xs mb-3" style="color: var(--text-muted);">
      Tick: {{ tickResult.skipped ? `skipped (${tickResult.skipped})` : `${tickResult.due} due, would send ${tickResult.sent}, draft ${tickResult.drafted}, retire ${tickResult.retired}` }}
    </p>
    <p v-if="store.error" class="text-sm mb-3" style="color: #FF6B6B;">{{ store.error }}</p>

    <div class="sn-card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr style="color: var(--text-muted);" class="text-left text-xs uppercase tracking-wider">
            <th class="p-3">Prospect</th>
            <th class="p-3">Platform</th>
            <th class="p-3">Status</th>
            <th class="p-3">Email</th>
            <th class="p-3">Step</th>
            <th class="p-3">Next send</th>
            <th class="p-3"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="store.loading"><td colspan="7" class="p-4" style="color: var(--text-muted);">Loading…</td></tr>
          <tr v-else-if="!store.prospects.length"><td colspan="7" class="p-4" style="color: var(--text-muted);">No prospects yet. Import a CSV to start.</td></tr>
          <tr v-for="p in store.prospects" :key="p.id" style="border-top: 1px solid var(--border);">
            <td class="p-3">
              <div class="font-medium" style="color: var(--text-primary);">{{ p.display_name || p.handle || '—' }}</div>
              <a :href="p.profile_url" target="_blank" rel="noopener noreferrer" class="text-xs underline" style="color: var(--accent);">{{ p.handle ? '@' + p.handle : p.profile_url }}</a>
            </td>
            <td class="p-3" style="color: var(--text-secondary);">{{ p.platform }}</td>
            <td class="p-3"><span class="text-[11px] px-2 py-1 rounded-full" :class="statusClass(p.status)">{{ p.status.replace('_', ' ') }}</span></td>
            <td class="p-3">
              <span v-if="p.lead?.email" style="color: var(--text-primary);">{{ p.lead.email }}</span>
              <form v-else class="flex gap-1" @submit.prevent="setEmail(p)">
                <input v-model="emailDraft[p.id]" class="sn-input text-xs" placeholder="add email" style="width: 180px;" />
                <button type="submit" class="sn-btn px-2 py-1 rounded text-xs" :disabled="!emailDraft[p.id]">Save</button>
              </form>
              <p v-if="rowError[p.id]" class="text-xs mt-1" style="color: #FF6B6B;">{{ rowError[p.id] }}</p>
            </td>
            <td class="p-3" style="color: var(--text-secondary);">{{ p.sequence_step }}</td>
            <td class="p-3 text-xs" style="color: var(--text-muted);">{{ fmt(p.next_send_at) }}</td>
            <td class="p-3 text-right whitespace-nowrap">
              <button v-if="!p.bot_paused_at && !isTerminal(p)" class="text-xs underline mr-2" style="color: var(--text-muted);" @click="store.action(p.id, 'pause')">Pause</button>
              <button v-if="p.bot_paused_at" class="text-xs underline mr-2" style="color: var(--accent);" @click="store.action(p.id, 'resume')">Resume</button>
              <button v-if="!isTerminal(p)" class="text-xs underline" style="color: #FF6B6B;" @click="store.action(p.id, 'retire')">Retire</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { usePartnersStore, PROSPECT_STATUSES } from '../../stores/partners.js'

const store = usePartnersStore()
const filters = reactive({ status: '', platform: '', has_email: '', q: '' })
const showImport = ref(false)
const importFile = ref(null)
const importDryRun = ref(true)
const importing = ref(false)
const importReport = ref(null)
const emailDraft = reactive({})
const rowError = reactive({})
const ticking = ref(false)
const tickResult = ref(null)

const TERMINAL = ['signed_up', 'declined', 'unsubscribed', 'bounced', 'retired']

const tiles = computed(() => {
  const s = store.summary.by_status || {}
  return [
    { label: 'Prospects', value: store.summary.total },
    { label: 'With email', value: store.summary.with_email, color: 'var(--accent)' },
    { label: 'Contacted', value: (s.invited || 0) + (s.nudged || 0) + (s.last_called || 0) + (s.dm_sent || 0) },
    { label: 'Replied', value: (s.replied || 0) + (s.negotiating || 0) + (s.handoff || 0), color: '#FFAA00' },
    { label: 'Signed up', value: s.signed_up || 0, color: '#3ddc97' },
  ]
})

function load() { return store.fetchProspects(filters) }
function isTerminal(p) { return TERMINAL.includes(p.status) }
function fmt(v) { return v ? new Date(v).toLocaleString() : '—' }
function statusClass(s) {
  if (s === 'signed_up') return 'dct-pill-lime'
  if (['declined', 'unsubscribed', 'bounced'].includes(s)) return 'dct-pill-pink'
  return 'dct-pill-cyan'
}
function onFile(e) { importFile.value = e.target.files?.[0] || null }

async function doImport() {
  importing.value = true
  importReport.value = null
  try {
    importReport.value = await store.importCsv(importFile.value, { dryRun: importDryRun.value })
    if (!importDryRun.value) await load()
  } catch { /* store.error is shown */ } finally {
    importing.value = false
  }
}

async function setEmail(p) {
  rowError[p.id] = ''
  try {
    await store.updateProspect(p.id, { email: emailDraft[p.id] })
    emailDraft[p.id] = ''
  } catch (err) {
    rowError[p.id] = err?.response?.data?.message || 'Rejected'
  }
}

async function tick() {
  ticking.value = true
  try { tickResult.value = await store.runTick({ dryRun: true }) } finally { ticking.value = false }
}

onMounted(load)
</script>
