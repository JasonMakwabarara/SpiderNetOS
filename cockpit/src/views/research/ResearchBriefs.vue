<template>
  <div class="px-6 py-7 max-w-7xl mx-auto" data-testid="research-briefs-page">
    <div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
      <div>
        <div class="sn-eyebrow">Operate · Research</div>
        <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Research briefs</h1>
        <p class="text-sm mt-1 max-w-2xl" style="color: var(--text-secondary);">
          Unreviewed drafts with a source register. A link is not evidence, and nothing here is an accepted business record until someone approves it.
        </p>
      </div>
      <button type="button" class="sn-btn" :disabled="loading" data-testid="research-briefs-refresh" @click="load">
        {{ loading ? 'Loading…' : 'Refresh' }}
      </button>
    </div>

    <p
      v-if="error"
      class="text-sm mb-3 rounded-md px-3 py-2"
      style="color: var(--amber); background: rgba(245,165,36,0.08); border: 1px solid rgba(245,165,36,0.25);"
      data-testid="research-briefs-error"
      role="alert"
    >{{ error }}</p>
    <p v-if="notice" class="text-xs mb-3" style="color: var(--accent);" role="status" data-testid="research-briefs-notice">{{ notice }}</p>

    <section class="sn-card p-4 mb-4" data-testid="research-briefs-import">
      <h2 class="text-sm font-medium" style="color: var(--text-primary);">Import a draft</h2>
      <p class="text-xs mt-1 mb-3" style="color: var(--text-muted);">
        Paste a JSON envelope with title, objective, audience, supplied facts, sources, and an optional draft. The import stays unreviewed. It cannot set a tenant or an approval.
      </p>
      <textarea
        v-model="envelope"
        class="sn-input w-full font-mono text-xs"
        rows="6"
        spellcheck="false"
        aria-label="Research brief JSON"
        placeholder='{"title":"Distributor campaign","objective":"Find three channels","audience":"Mine canteens","supplied_facts":["Meal is shelf-stable"],"sources":[{"title":"Public notice","url":"https://example.com/notice","excerpt":"Shift patterns"}],"draft":"A convenient meal during a demanding working day."}'
        data-testid="research-briefs-envelope"
      />
      <div class="mt-3">
        <button type="button" class="sn-btn-primary text-xs" :disabled="importing" data-testid="research-briefs-import-submit" @click="importEnvelope">
          {{ importing ? 'Importing…' : 'Import as unreviewed draft' }}
        </button>
      </div>
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-[300px_minmax(0,1fr)] gap-4 items-start">
      <nav class="sn-card p-3" aria-label="Research briefs" data-testid="research-briefs-list">
        <p v-if="loading && !briefs.length" class="text-sm px-2 py-1" style="color: var(--text-muted);">Loading…</p>
        <ul>
          <li v-for="brief in briefs" :key="brief.id">
            <button
              type="button"
              class="w-full text-left rounded-md px-2 py-2 text-sm"
              :style="selected?.id === brief.id ? 'background: var(--accent-weak); color: var(--accent);' : 'color: var(--text-primary);'"
              :aria-current="selected?.id === brief.id ? 'true' : undefined"
              :data-testid="`research-brief-${brief.id}`"
              @click="selectBrief(brief.id)"
            >
              <span class="block truncate">{{ brief.title || 'Untitled brief' }}</span>
              <span class="block text-[11px] mt-0.5" style="color: var(--text-muted);">
                {{ brief.status }} · {{ sourceCount(brief) }} source{{ sourceCount(brief) === 1 ? '' : 's' }}
              </span>
            </button>
          </li>
        </ul>
        <p v-if="!loading && !briefs.length" class="text-sm px-2 py-1" style="color: var(--text-muted);" data-testid="research-briefs-empty">
          No research briefs yet.
        </p>
      </nav>

      <article class="sn-card p-0 overflow-hidden" data-testid="research-brief-detail">
        <template v-if="selected">
          <header class="px-5 py-4 border-b" style="border-color: var(--border);">
            <div class="flex items-start justify-between gap-3 flex-wrap">
              <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <h2 class="text-base font-semibold" style="color: var(--text-primary);" data-testid="research-brief-title">{{ selected.title }}</h2>
                  <span class="sn-pill" data-testid="research-brief-status">{{ selected.status }}</span>
                  <span class="sn-pill">unverified sources</span>
                </div>
                <p class="text-[11px] mt-1" style="color: var(--text-muted);">
                  Suggestions only. Accepting this brief is an approval decision, not a new fact.
                </p>
              </div>
              <div class="flex gap-2 shrink-0">
                <button type="button" class="sn-btn-secondary text-xs" data-testid="research-brief-download" @click="download">Download Markdown</button>
                <button
                  v-if="selected.status === 'draft'"
                  type="button"
                  class="sn-btn-primary text-xs"
                  :disabled="submitting"
                  data-testid="research-brief-submit"
                  @click="submitForReview"
                >{{ submitting ? 'Submitting…' : 'Submit for review' }}</button>
              </div>
            </div>
          </header>

          <div class="px-5 py-4 space-y-5 text-sm" style="color: var(--text-primary);">
            <section>
              <h3 class="sn-section-title">Objective</h3>
              <p class="mt-1 whitespace-pre-wrap" data-testid="research-brief-objective">{{ selected.meta?.objective || '—' }}</p>
            </section>
            <section>
              <h3 class="sn-section-title">Audience</h3>
              <p class="mt-1 whitespace-pre-wrap">{{ selected.meta?.audience || 'Not stated.' }}</p>
            </section>
            <section>
              <h3 class="sn-section-title">Supplied facts</h3>
              <ul v-if="selected.meta?.supplied_facts?.length" class="mt-1 list-disc pl-5 space-y-1">
                <li v-for="(fact, i) in selected.meta.supplied_facts" :key="i">{{ fact }}</li>
              </ul>
              <p v-else class="mt-1" style="color: var(--text-muted);">None supplied.</p>
            </section>
            <section>
              <h3 class="sn-section-title">Suggested draft</h3>
              <p class="mt-1 whitespace-pre-wrap" data-testid="research-brief-draft">{{ selected.meta?.draft || 'No draft text was supplied.' }}</p>
            </section>
            <section>
              <h3 class="sn-section-title">Source register</h3>
              <p v-if="!sources.length" class="mt-1" style="color: var(--text-muted);">No sources supplied.</p>
              <ul v-else class="mt-2 space-y-3" data-testid="research-brief-sources">
                <li v-for="(source, i) in sources" :key="`${source.url}-${i}`" class="rounded-md px-3 py-2" style="background: var(--bg-elevated);">
                  <p class="font-medium" :data-testid="`research-brief-source-${i}`">{{ source.title }}</p>
                  <a class="text-xs break-all" style="color: var(--accent);" :href="source.url" target="_blank" rel="noopener noreferrer">{{ source.url }}</a>
                  <p class="text-[11px] mt-1" style="color: var(--text-muted);">{{ source.origin || 'operator_supplied' }} · {{ source.evidence_status || 'supplied_unverified' }}</p>
                  <p v-if="source.excerpt" class="text-xs mt-1 whitespace-pre-wrap" style="color: var(--text-secondary);">{{ source.excerpt }}</p>
                </li>
              </ul>
            </section>
            <section>
              <h3 class="sn-section-title">Provenance</h3>
              <p class="mt-1 whitespace-pre-wrap" style="color: var(--text-secondary);">
                {{ selected.meta?.provenance || 'Not stated. Provider identity is self-reported and was not verified.' }}
              </p>
            </section>
          </div>
        </template>
        <p v-else class="px-5 py-8 text-sm" style="color: var(--text-muted);" data-testid="research-brief-none">
          Select a brief to review its sources and download the Markdown.
        </p>
      </article>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../services/api.js'

const briefs = ref([])
const selected = ref(null)
const loading = ref(false)
const importing = ref(false)
const submitting = ref(false)
const error = ref('')
const notice = ref('')
const envelope = ref('')

const sources = computed(() => selected.value?.meta?.sources || [])

function sourceCount(brief) {
  return Array.isArray(brief?.meta?.sources) ? brief.meta.sources.length : 0
}

function messageFrom(err, fallback) {
  const data = err?.response?.data
  if (typeof data?.message === 'string' && data.message !== '') return data.message
  const errors = data?.errors
  if (errors && typeof errors === 'object') {
    const first = Object.values(errors).flat()[0]
    if (typeof first === 'string') return first
  }
  if (typeof data?.error === 'string') return data.error
  return fallback
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const response = await api.get('/api/research-briefs')
    briefs.value = response.data?.data || []
  } catch (err) {
    error.value = messageFrom(err, 'Could not load research briefs.')
  } finally {
    loading.value = false
  }
}

function selectBrief(id) {
  notice.value = ''
  return openBrief(id)
}

async function openBrief(id) {
  error.value = ''
  try {
    const response = await api.get(`/api/research-briefs/${id}`)
    selected.value = response.data?.data || null
  } catch (err) {
    error.value = messageFrom(err, 'Could not open that brief.')
  }
}

async function importEnvelope() {
  error.value = ''
  notice.value = ''
  let payload
  try {
    payload = JSON.parse(envelope.value)
  } catch {
    error.value = 'The envelope is not valid JSON.'
    return
  }
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    error.value = 'The envelope must be a JSON object.'
    return
  }

  importing.value = true
  try {
    const response = await api.post('/api/research-briefs', payload)
    const created = response.data?.data
    envelope.value = ''
    await load()
    if (created?.id) await openBrief(created.id)
    notice.value = 'Imported as an unreviewed draft.'
  } catch (err) {
    error.value = messageFrom(err, 'Import failed.')
  } finally {
    importing.value = false
  }
}

async function download() {
  if (!selected.value?.id) return
  error.value = ''
  try {
    const response = await api.get(`/api/research-briefs/${selected.value.id}/markdown`, { responseType: 'blob' })
    const blob = new Blob([response.data], { type: 'text/markdown;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    const match = /filename="([^"]+)"/.exec(response.headers?.['content-disposition'] || '')
    link.href = url
    link.download = match?.[1] || 'research-brief.md'
    link.click()
    URL.revokeObjectURL(url)
  } catch (err) {
    error.value = messageFrom(err, 'Could not download the Markdown.')
  }
}

async function submitForReview() {
  if (!selected.value?.id || selected.value.status !== 'draft') return
  submitting.value = true
  error.value = ''
  notice.value = ''
  try {
    await api.post(`/api/artifacts/${selected.value.id}/submit`, {
      reason: `Review research brief: ${selected.value.title || 'untitled'}`,
    })
    await openBrief(selected.value.id)
    await load()
    notice.value = 'Submitted for review. It stays a suggestion until the approval is accepted.'
  } catch (err) {
    error.value = messageFrom(err, 'Could not submit this brief for review.')
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>
