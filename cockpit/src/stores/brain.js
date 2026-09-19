/**
 * Business Brain Pinia store — the versioned virtual filesystem every
 * agent reads before it acts.
 *
 * Endpoints (PR 1 contract):
 *   GET  /api/brain/tree                      → { data: { folders: [{ key, title, files: [...] }] } }
 *   GET  /api/brain/readiness                 → { data: { pct, files: [...] } }
 *   GET  /api/brain/gaps                      → { data: [{ path, section, question, blocks[] }] }
 *   GET  /api/brain/files/{path}              → { data: { path, title, content, frontmatter, version, status, updated_at, source } }
 *   PUT  /api/brain/files/{path} {content, base_version}   (409 → { current_version })
 *   GET  /api/brain/files/{path}/versions     → { data: [{ version, updated_at, source, summary }] }
 *   POST /api/brain/files/{path}/revert {version}
 *   POST /api/brain/sync
 *
 * Paths contain slashes; each segment is URL-encoded so the slash survives
 * (`offer/offer.md` → `offer/offer.md`, `a b.md` → `a%20b.md`).
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api.js'
import { describeError, fallbackNotice, clone } from '../utils/apiFallback.js'
import treeFixture from '../../tests/fixtures/brain_tree.json'
import fileFixture from '../../tests/fixtures/brain_file.json'

export function encodeBrainPath(path) {
  return String(path || '').split('/').map(encodeURIComponent).join('/')
}

export const useBrainStore = defineStore('brain', () => {
  // ── State ──────────────────────────────────────────────────────────
  const tree = ref({ folders: [] })
  const readiness = ref({ pct: 0, files: [] })
  const gaps = ref([])
  /** path → file record (with content) */
  const files = ref({})
  /** path → [{ version, updated_at, source, summary }] */
  const versions = ref({})

  const loading = ref(false)
  const fileLoading = ref(false)
  const saving = ref(false)
  const syncing = ref(false)
  const error = ref(null)
  const fileError = ref(null)
  const fallback = ref(false)

  // ── Getters ────────────────────────────────────────────────────────
  const allFiles = computed(() => (tree.value.folders || []).flatMap((f) => f.files || []))
  const fileCount = computed(() => allFiles.value.length)
  const filledCount = computed(() => allFiles.value.filter((f) => f.status === 'filled').length)
  const missingCount = computed(() => allFiles.value.filter((f) => f.status === 'missing').length)

  // ── Helpers ────────────────────────────────────────────────────────
  function treeEntry(path) {
    for (const folder of tree.value.folders || []) {
      const hit = (folder.files || []).find((f) => f.path === path)
      if (hit) return hit
    }
    return null
  }

  function patchTreeEntry(path, patch) {
    tree.value = {
      ...tree.value,
      folders: (tree.value.folders || []).map((folder) => ({
        ...folder,
        files: (folder.files || []).map((f) => (f.path === path ? { ...f, ...patch } : f)),
      })),
    }
  }

  function patchReadiness(path, patch) {
    readiness.value = {
      ...readiness.value,
      files: (readiness.value.files || []).map((f) => (f.path === path ? { ...f, ...patch } : f)),
    }
  }

  function fallbackFile(path) {
    const base = clone(fileFixture.data)
    if (path === base.path) return base
    const entry = treeEntry(path)
    return {
      ...base,
      path,
      title: entry?.title || path.split('/').pop(),
      status: entry?.status || 'missing',
      version: entry?.version ?? 0,
      updated_at: entry?.updated_at || null,
      content: entry?.status === 'missing' ? '' : base.content,
    }
  }

  // ── Actions ────────────────────────────────────────────────────────
  async function fetchTree() {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/api/brain/tree')
      tree.value = data?.data || { folders: [] }
      fallback.value = false
      return { success: true }
    } catch (err) {
      error.value = fallbackNotice(err, 'the brain')
      fallback.value = true
      tree.value = clone(treeFixture.data)
      return { success: false, error: error.value }
    } finally {
      loading.value = false
    }
  }

  async function fetchReadiness() {
    try {
      const { data } = await api.get('/api/brain/readiness')
      readiness.value = data?.data || { pct: 0, files: [] }
      return { success: true }
    } catch (err) {
      readiness.value = clone(treeFixture.readiness.data)
      return { success: false, error: describeError(err, 'Could not load readiness') }
    }
  }

  async function fetchGaps() {
    try {
      const { data } = await api.get('/api/brain/gaps')
      gaps.value = data?.data || []
      return { success: true }
    } catch (err) {
      gaps.value = clone(treeFixture.gaps.data)
      return { success: false, error: describeError(err, 'Could not load gaps') }
    }
  }

  function fetchAll() {
    return Promise.all([fetchTree(), fetchReadiness(), fetchGaps()])
  }

  async function fetchFile(path) {
    if (!path) return { success: false, error: 'No path' }
    fileLoading.value = true
    fileError.value = null
    try {
      const { data } = await api.get(`/api/brain/files/${encodeBrainPath(path)}`)
      const file = data?.data || data
      files.value = { ...files.value, [path]: file }
      return { success: true, file }
    } catch (err) {
      fileError.value = fallbackNotice(err, path)
      const file = fallbackFile(path)
      files.value = { ...files.value, [path]: file }
      return { success: false, error: fileError.value, file }
    } finally {
      fileLoading.value = false
    }
  }

  /**
   * Save with optimistic concurrency. `baseVersion` defaults to the version
   * we last loaded; a 409 means someone (or some agent) wrote in between and
   * the caller decides whether to reload and retry.
   */
  async function saveFile(path, content, baseVersion) {
    if (!path) return { success: false, error: 'No path' }
    const base = baseVersion ?? files.value[path]?.version ?? null
    saving.value = true
    try {
      const { data } = await api.put(`/api/brain/files/${encodeBrainPath(path)}`, { content, base_version: base })
      const saved = { ...(files.value[path] || {}), ...(data?.data || {}), path, content: data?.data?.content ?? content }
      files.value = { ...files.value, [path]: saved }
      const patch = { status: saved.status, version: saved.version, updated_at: saved.updated_at }
      patchTreeEntry(path, patch)
      patchReadiness(path, { status: saved.status })
      return { success: true, file: saved }
    } catch (err) {
      const status = err?.response?.status
      if (status === 409) {
        const current = err.response?.data?.current_version
        return { success: false, conflict: true, status, current_version: current, error: `Someone saved a newer version (v${current ?? '?'}) while you were editing.` }
      }
      return { success: false, status, error: describeError(err, 'Could not save the file') }
    } finally {
      saving.value = false
    }
  }

  async function fetchVersions(path) {
    try {
      const { data } = await api.get(`/api/brain/files/${encodeBrainPath(path)}/versions`)
      versions.value = { ...versions.value, [path]: data?.data || [] }
      return { success: true, versions: versions.value[path] }
    } catch (err) {
      const list = path === fileFixture.data.path ? clone(fileFixture.versions.data) : []
      versions.value = { ...versions.value, [path]: list }
      return { success: false, error: describeError(err, 'Could not load versions'), versions: list }
    }
  }

  async function revert(path, version) {
    try {
      const { data } = await api.post(`/api/brain/files/${encodeBrainPath(path)}/revert`, { version })
      const file = data?.data
      if (file) {
        files.value = { ...files.value, [path]: { ...(files.value[path] || {}), ...file } }
        patchTreeEntry(path, { status: file.status, version: file.version, updated_at: file.updated_at })
      } else {
        await fetchFile(path)
      }
      await fetchVersions(path)
      return { success: true, file: files.value[path] }
    } catch (err) {
      return { success: false, error: describeError(err, 'Could not revert') }
    }
  }

  async function sync() {
    syncing.value = true
    try {
      const { data } = await api.post('/api/brain/sync')
      await fetchAll()
      return { success: true, data: data?.data }
    } catch (err) {
      return { success: false, error: describeError(err, 'Sync failed') }
    } finally {
      syncing.value = false
    }
  }

  /**
   * Adopt a readiness payload that arrived with something else — Atlas
   * returns `metadata.brain` on every launch turn, so the brain rows on the
   * launch panel stay in step without a second GET. Accepts either the bare
   * `{pct, files}` or the `{data: {...}}` envelope, ignores anything that is
   * not readiness-shaped, and mirrors each file's status into the tree so the
   * drawer and the tree never disagree.
   */
  function applyReadiness(payload) {
    const next = payload?.data ?? payload
    if (!next || typeof next !== 'object' || !Array.isArray(next.files)) return readiness.value

    readiness.value = {
      pct: Number.isFinite(Number(next.pct)) ? Number(next.pct) : 0,
      files: next.files.map((f) => ({ ...f })),
    }

    for (const file of readiness.value.files) {
      if (file?.path && treeEntry(file.path)) {
        const patch = { status: file.status, version: file.version }
        Object.keys(patch).forEach((k) => patch[k] === undefined && delete patch[k])
        patchTreeEntry(file.path, patch)
      }
    }

    return readiness.value
  }

  // ── Real-time handlers ─────────────────────────────────────────────
  function handleFileUpdated(data) {
    const path = data?.path
    if (!path) return
    const patch = { status: data.status, version: data.version, updated_at: data.updated_at }
    Object.keys(patch).forEach((k) => patch[k] === undefined && delete patch[k])
    if (treeEntry(path)) patchTreeEntry(path, patch)
    patchReadiness(path, patch)
    if (files.value[path]) {
      files.value = { ...files.value, [path]: { ...files.value[path], ...data } }
    }
  }

  return {
    // state
    tree, readiness, gaps, files, versions,
    loading, fileLoading, saving, syncing, error, fileError, fallback,
    // getters
    allFiles, fileCount, filledCount, missingCount,
    // actions
    treeEntry, fetchTree, fetchReadiness, fetchGaps, fetchAll,
    fetchFile, saveFile, fetchVersions, revert, sync, applyReadiness,
    // realtime
    handleFileUpdated,
  }
})
