<template>
  <div class="px-6 py-7 max-w-7xl mx-auto" data-testid="brain-page">
    <div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
      <div>
        <div class="sn-eyebrow">Operate · Brain</div>
        <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Business brain</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">
          The files every agent reads before it acts. Versioned; Atlas asks before it writes.
        </p>
      </div>
      <div class="flex gap-2 items-center">
        <input
          v-model="search"
          type="search"
          class="sn-input"
          style="width: 14rem;"
          placeholder="Search files"
          aria-label="Search brain files"
          data-testid="brain-search"
        />
        <button type="button" class="sn-btn" :disabled="store.syncing" data-testid="brain-sync" @click="onSync">
          {{ store.syncing ? 'Syncing…' : 'Sync' }}
        </button>
      </div>
    </div>

    <div class="sn-card p-4 mb-4" data-testid="brain-readiness-bar">
      <div class="flex items-center justify-between text-xs">
        <span style="color: var(--text-secondary);">Brain readiness</span>
        <span class="mono" style="color: var(--text-primary);" data-testid="brain-readiness-pct">{{ pct }}%</span>
      </div>
      <div class="mt-2 h-2 rounded-full overflow-hidden" style="background: var(--bg-elevated);" role="progressbar" :aria-valuenow="pct" aria-valuemin="0" aria-valuemax="100" aria-label="Brain readiness">
        <div class="h-full rounded-full transition-all" :style="`width:${pct}%; background: ${pct >= 80 ? 'var(--success)' : 'var(--accent)'};`"></div>
      </div>
      <p class="text-[11px] mt-1.5" style="color: var(--text-muted);" data-testid="brain-readiness-counts">
        {{ store.filledCount }} filled · {{ partialCount }} partial · {{ store.missingCount }} missing of {{ store.fileCount }} files
      </p>
    </div>

    <p
      v-if="store.error"
      class="text-sm mb-3 rounded-md px-3 py-2"
      style="color: var(--amber); background: rgba(245,165,36,0.08); border: 1px solid rgba(245,165,36,0.25);"
      data-testid="brain-error"
    >{{ store.error }}</p>
    <p v-if="syncNotice" class="text-xs mb-3" :style="{ color: syncError ? 'var(--danger)' : 'var(--accent)' }" role="status" data-testid="brain-sync-notice">{{ syncNotice }}</p>

    <div v-if="store.gaps.length" class="sn-card p-4 mb-4" style="border-color: rgba(245,165,36,0.35);" data-testid="brain-gaps">
      <p class="text-sm font-medium" style="color: var(--text-primary);">
        {{ store.gaps.length }} gap{{ store.gaps.length === 1 ? '' : 's' }} blocking skills
      </p>
      <ul class="mt-2 space-y-1.5">
        <li
          v-for="g in store.gaps.slice(0, 5)"
          :key="`${g.path}#${g.section}`"
          class="flex items-start justify-between gap-3 text-xs"
          :data-testid="`brain-gap-${fileKey(`${g.path}-${g.section}`)}`"
        >
          <div class="min-w-0">
            <p style="color: var(--text-primary);">{{ g.question }}</p>
            <p class="mono" style="color: var(--text-muted);">
              {{ g.path }}<span v-if="g.section"> › {{ g.section }}</span><span v-if="g.blocks?.length"> · blocks {{ g.blocks.join(', ') }}</span>
            </p>
          </div>
          <div class="flex gap-1.5 shrink-0">
            <button type="button" class="sn-chip" @click="select(g.path)">Open file</button>
            <RouterLink :to="askRoute(g)" class="sn-chip">Ask Atlas</RouterLink>
          </div>
        </li>
      </ul>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[300px_minmax(0,1fr)] gap-4 items-start">
      <nav class="sn-card p-3" aria-label="Brain folders" data-testid="brain-tree">
        <p v-if="store.loading && !store.tree.folders.length" class="text-sm px-2 py-1" style="color: var(--text-muted);">Loading…</p>
        <div v-for="folder in visibleFolders" :key="folder.key" class="mb-3" :data-testid="`brain-folder-${folder.key}`">
          <p class="sn-section-title px-2" style="margin-bottom: 0.25rem;">{{ folder.title }}</p>
          <ul>
            <li v-for="f in folder.files" :key="f.path">
              <button
                type="button"
                class="w-full text-left flex items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors"
                :style="selectedPath === f.path ? 'background: var(--accent-weak); color: var(--accent);' : 'color: var(--text-primary);'"
                :aria-current="selectedPath === f.path ? 'true' : undefined"
                :data-testid="`brain-tree-file-${fileKey(f.path)}`"
                :data-status="f.status"
                @click="select(f.path)"
              >
                <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="brainStatusDot(f.status)" aria-hidden="true"></span>
                <span class="sr-only">{{ f.status }}.</span>
                <span class="truncate flex-1">{{ f.title }}</span>
                <span class="mono text-[10px]" style="color: var(--text-muted);">v{{ f.version ?? 0 }}</span>
              </button>
            </li>
          </ul>
        </div>
        <p v-if="!store.loading && !visibleFolders.length" class="text-sm px-2 py-1" style="color: var(--text-muted);" data-testid="brain-tree-empty">No files match.</p>
      </nav>

      <article class="sn-card p-0 overflow-hidden" data-testid="brain-file-panel">
        <template v-if="selectedPath">
          <header class="px-5 py-4 border-b flex items-start justify-between gap-3" style="border-color: var(--border);">
            <div class="min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <h2 class="text-base font-semibold truncate" style="color: var(--text-primary);" data-testid="brain-file-title">
                  {{ current?.title || selectedEntry?.title || selectedPath }}
                </h2>
                <span class="sn-pill" :class="statusPill(currentStatus)" data-testid="brain-file-status">{{ currentStatus }}</span>
                <span v-if="dataClass" class="sn-pill text-[10px]" :title="`Data class: ${dataClass}`">{{ dataClass }}</span>
              </div>
              <p class="mono text-[11px] mt-0.5" style="color: var(--text-muted);" data-testid="brain-file-path">
                {{ selectedPath }} · v{{ current?.version ?? selectedEntry?.version ?? 0 }}
                · {{ current?.updated_at ? fmtDate(current.updated_at) : 'never written' }}<span v-if="current?.source"> · {{ current.source }}</span>
              </p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
              <RouterLink :to="askRoute({ path: selectedPath })" class="sn-btn-secondary text-xs" data-testid="brain-file-ask">Ask Atlas</RouterLink>
              <button type="button" class="sn-btn-primary text-xs" data-testid="brain-file-edit" @click="openEditor">Edit</button>
            </div>
          </header>

          <p v-if="store.fileError" class="px-5 py-2 text-xs border-b" style="color: var(--amber); border-color: var(--border);" data-testid="brain-file-error">{{ store.fileError }}</p>
          <p v-if="conflict" class="px-5 py-2 text-xs border-b flex items-center gap-2" style="color: var(--danger); border-color: var(--border);" role="alert" data-testid="brain-conflict">
            {{ conflict }}
            <button type="button" class="underline" data-testid="brain-conflict-reload" @click="reloadCurrent">Reload</button>
          </p>
          <p v-if="fileNotice" class="px-5 py-2 text-xs border-b" :style="{ color: fileNoticeError ? 'var(--danger)' : 'var(--accent)', borderColor: 'var(--border)' }" role="status" data-testid="brain-file-notice">{{ fileNotice }}</p>

          <div class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_240px]">
            <div class="p-5">
              <p v-if="store.fileLoading && !current" class="text-sm" style="color: var(--text-muted);">Loading…</p>
              <div v-else-if="current?.content" class="sn-prose text-sm" data-testid="brain-file-preview" v-html="previewHtml"></div>
              <p v-else class="text-sm" style="color: var(--text-muted);" data-testid="brain-file-empty">
                Nothing written yet.
                <button type="button" class="underline" style="color: var(--accent);" @click="openEditor">Write it</button>
                or ask Atlas.
              </p>
            </div>
            <aside class="p-4 border-t md:border-t-0 md:border-l" style="border-color: var(--border);" data-testid="brain-versions">
              <p class="sn-section-title">Versions</p>
              <ul v-if="versionList.length" class="space-y-2">
                <li v-for="v in versionList" :key="v.version" class="text-xs" :data-testid="`brain-version-${v.version}`">
                  <div class="flex items-center justify-between gap-2">
                    <span class="mono" style="color: var(--text-primary);">v{{ v.version }}</span>
                    <button
                      v-if="v.version !== (current?.version ?? -1)"
                      type="button"
                      class="sn-chip"
                      style="padding: 0.15rem 0.5rem;"
                      :data-testid="`brain-revert-${v.version}`"
                      @click="askRevert(v)"
                    >Revert</button>
                    <span v-else class="sn-pill sn-pill-accent text-[10px]">current</span>
                  </div>
                  <p style="color: var(--text-muted);">{{ fmtDate(v.updated_at) }}<span v-if="v.source"> · {{ v.source }}</span></p>
                  <p v-if="v.summary" style="color: var(--text-secondary);">{{ v.summary }}</p>
                </li>
              </ul>
              <p v-else class="text-xs" style="color: var(--text-muted);" data-testid="brain-versions-empty">No history yet.</p>
            </aside>
          </div>
        </template>
        <div v-else class="p-10 text-center text-sm" style="color: var(--text-muted);" data-testid="brain-file-none">
          Pick a file to read it.
        </div>
      </article>
    </div>

    <BrainFileDrawer v-model="drawerOpen" :file="drawerFile" :saving="store.saving" @save="onSave" />

    <ConfirmDialog
      v-model="confirmRevert.open"
      title="Revert this file?"
      confirm-label="Revert"
      destructive
      @confirm="doRevert"
      @cancel="confirmRevert.version = null"
    >
      <p class="text-sm" style="color: var(--text-secondary);">
        Restore <strong style="color: var(--text-primary);">{{ selectedPath }}</strong> to v{{ confirmRevert.version }}?
        The current version stays in the history.
      </p>
    </ConfirmDialog>
  </div>
</template>

<script setup>
/**
 * Business brain browser — folder tree with status dots, readiness bar,
 * gaps banner, and a file panel (markdown preview, edit in the drawer,
 * versions with revert, sync). The selected file lives in `?file=`.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useBrainStore } from '../../stores/brain.js'
import BrainFileDrawer from '../../components/brain/BrainFileDrawer.vue'
import ConfirmDialog from '../../components/feedback/ConfirmDialog.vue'
import { renderMarkdown } from '../../utils/markdown.js'
import { fmtDate, brainStatusDot } from '../../utils/format.js'

const route = useRoute()
const router = useRouter()
const store = useBrainStore()

const search = ref('')
const drawerOpen = ref(false)
const drawerFile = ref(null)
const conflict = ref('')
const fileNotice = ref('')
const fileNoticeError = ref(false)
const syncNotice = ref('')
const syncError = ref(false)
const confirmRevert = reactive({ open: false, version: null })

const selectedPath = computed(() => (route.query.file ? String(route.query.file) : ''))
const current = computed(() => store.files[selectedPath.value] || null)
const selectedEntry = computed(() => store.treeEntry(selectedPath.value))
const currentStatus = computed(() => current.value?.status || selectedEntry.value?.status || 'missing')
const dataClass = computed(() => current.value?.data_class || current.value?.frontmatter?.data_class || selectedEntry.value?.data_class || '')
const versionList = computed(() => store.versions[selectedPath.value] || [])
const previewHtml = computed(() => renderMarkdown(current.value?.content || ''))

const pct = computed(() => Math.max(0, Math.min(100, Math.round(Number(store.readiness.pct) || 0))))
const partialCount = computed(() => store.allFiles.filter((f) => f.status === 'partial').length)

const visibleFolders = computed(() => {
  const q = search.value.trim().toLowerCase()
  return (store.tree.folders || [])
    .map((folder) => ({
      ...folder,
      files: (folder.files || []).filter((f) => !q || `${f.title} ${f.path}`.toLowerCase().includes(q)),
    }))
    .filter((folder) => folder.files.length)
})

function fileKey(path) {
  return String(path || '').replace(/[^a-z0-9_-]+/gi, '-')
}
function statusPill(s) {
  if (s === 'filled') return 'sn-pill-success'
  if (s === 'partial') return 'sn-pill-warn'
  return 'sn-pill-danger'
}

function select(path) {
  router.replace({ query: { ...route.query, file: path } })
}

function askRoute(g) {
  const prefill = g.question
    ? `${g.question} — save it to ${g.path}${g.section ? ` › ${g.section}` : ''}.`
    : `Help me fill in ${g.path} for my business brain. Ask me what you need, then draft it.`
  return { path: '/atlas', query: { hannah: '1', prefill } }
}

function openEditor() {
  const f = current.value
  drawerFile.value = {
    path: selectedPath.value,
    title: f?.title || selectedEntry.value?.title || selectedPath.value,
    status: currentStatus.value,
    body_md: f?.content ?? '',
    version: f?.version,
  }
  drawerOpen.value = true
}

async function onSave({ path, body_md }) {
  conflict.value = ''
  const res = await store.saveFile(path, body_md, drawerFile.value?.version)
  if (res.success) {
    drawerOpen.value = false
    fileNoticeError.value = false
    fileNotice.value = `Saved v${res.file?.version ?? '?'}.`
    store.fetchVersions(path)
    store.fetchReadiness()
  } else if (res.conflict) {
    conflict.value = res.error
  } else {
    fileNoticeError.value = true
    fileNotice.value = res.error
  }
}

async function reloadCurrent() {
  await store.fetchFile(selectedPath.value)
  conflict.value = ''
  if (drawerOpen.value && current.value) {
    drawerFile.value = { ...drawerFile.value, body_md: current.value.content ?? '', version: current.value.version }
  }
}

function askRevert(v) {
  confirmRevert.version = v.version
  confirmRevert.open = true
}

async function doRevert() {
  const version = confirmRevert.version
  confirmRevert.version = null
  const res = await store.revert(selectedPath.value, version)
  fileNoticeError.value = !res.success
  fileNotice.value = res.success ? `Reverted to v${version}.` : res.error
}

async function onSync() {
  const res = await store.sync()
  syncError.value = !res.success
  syncNotice.value = res.success ? 'Synced.' : res.error
}

watch(
  selectedPath,
  async (path) => {
    conflict.value = ''
    fileNotice.value = ''
    if (!path) return
    await Promise.all([store.fetchFile(path), store.fetchVersions(path)])
  },
  { immediate: true },
)

onMounted(() => store.fetchAll())
</script>

<style scoped>
.sn-prose :deep(h1) { font-size: 1.25rem; font-weight: 600; margin: 0 0 0.6rem; color: var(--text-primary); }
.sn-prose :deep(h2) { font-size: 1.05rem; font-weight: 600; margin: 1rem 0 0.4rem; color: var(--text-primary); }
.sn-prose :deep(h3) { font-size: 0.9rem; font-weight: 600; margin: 0.8rem 0 0.3rem; color: var(--text-primary); text-transform: uppercase; letter-spacing: 0.04em; }
.sn-prose :deep(p)  { margin: 0 0 0.6rem; color: var(--text-secondary); line-height: 1.55; white-space: pre-line; }
.sn-prose :deep(ul), .sn-prose :deep(ol) { margin: 0 0 0.6rem 1.2rem; color: var(--text-secondary); }
.sn-prose :deep(ul) { list-style: disc; }
.sn-prose :deep(ol) { list-style: decimal; }
.sn-prose :deep(li) { margin: 0.15rem 0; }
.sn-prose :deep(strong) { color: var(--text-primary); font-weight: 600; }
.sn-prose :deep(code) { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 0.8em; padding: 1px 5px; border-radius: 4px; background: var(--bg-elevated); border: 1px solid var(--border); }
.sn-prose :deep(a) { color: var(--accent); text-decoration: underline; }
</style>
