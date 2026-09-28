<template>
  <SideDrawer
    :model-value="modelValue"
    :title="displayTitle"
    width="600px"
    @update:modelValue="setOpen"
    @close="emit('close')"
  >
    <template #header>
      <div class="min-w-0">
        <p class="sn-eyebrow">Brain · file</p>
        <div class="flex items-center gap-2 mt-0.5 min-w-0">
          <h2
            class="text-base font-semibold truncate"
            style="color: var(--text-primary);"
            data-testid="brain-file-drawer-title"
          >{{ displayTitle }}</h2>
          <span
            class="sn-pill shrink-0"
            :class="statusPillClass"
            data-testid="brain-file-drawer-status"
          >{{ statusLabel }}</span>
        </div>
        <p
          v-if="file?.path"
          class="text-[11px] mono mt-0.5 truncate"
          style="color: var(--text-muted);"
          data-testid="brain-file-drawer-path"
        >{{ file.path }}</p>
      </div>
    </template>

    <div class="flex items-center justify-between gap-2 mb-3">
      <div class="flex items-center gap-1" role="tablist" aria-label="Editor mode">
        <button
          type="button"
          role="tab"
          class="sn-chip"
          :class="{ 'sn-chip-active': !preview }"
          :aria-selected="!preview ? 'true' : 'false'"
          data-testid="brain-file-mode-edit"
          @click="preview = false"
        >Edit</button>
        <button
          type="button"
          role="tab"
          class="sn-chip"
          :class="{ 'sn-chip-active': preview }"
          :aria-selected="preview ? 'true' : 'false'"
          data-testid="brain-file-preview-toggle"
          @click="preview = !preview"
        >Preview</button>
      </div>
      <span
        v-if="dirty"
        class="text-[11px]"
        style="color: var(--amber);"
        data-testid="brain-file-dirty"
      >Unsaved changes</span>
    </div>

    <div
      v-if="preview"
      class="sn-prose text-sm"
      data-testid="brain-file-preview"
      v-html="previewHtml"
    ></div>
    <textarea
      v-else
      v-model="draft"
      class="sn-textarea mono"
      rows="18"
      spellcheck="false"
      autofocus
      :placeholder="placeholder"
      :aria-label="`${displayTitle} contents`"
      data-testid="brain-file-editor"
      @keydown.ctrl.enter.prevent="save"
      @keydown.meta.enter.prevent="save"
    ></textarea>

    <template #footer>
      <div class="flex items-center justify-between gap-2 flex-wrap">
        <button
          type="button"
          class="sn-btn-secondary"
          style="color: var(--accent); border-color: rgba(0,214,201,0.30);"
          data-testid="brain-file-ask-atlas"
          @click="askAtlas"
        >Ask Atlas to fill this</button>
        <div class="flex items-center gap-2">
          <button
            type="button"
            class="sn-btn-secondary"
            data-testid="brain-file-cancel"
            @click="setOpen(false)"
          >Cancel</button>
          <button
            type="button"
            class="sn-btn sn-btn-primary"
            :disabled="saving || !file?.path"
            data-testid="brain-file-save"
            @click="save"
          >{{ saving ? 'Saving…' : 'Save' }}</button>
        </div>
      </div>
    </template>
  </SideDrawer>
</template>

<script setup>
/**
 * BrainFileDrawer — edit one brain file (markdown) in a SideDrawer with a
 * live preview. Persisting is the parent's job: `save` emits
 * `{ path, body_md }` and the brain store (next slice) does the PUT.
 * "Ask Atlas to fill this" hands off to the Atlas thread with a prefill,
 * the same way ReadinessGuidancePanel does.
 *
 * file: { key?, path, title?, status?: 'filled'|'partial'|'missing', body_md?, ask_prompt? }
 */
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import SideDrawer from '../data/SideDrawer.vue'
import { renderMarkdown } from '../../utils/markdown.js'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  file:       { type: Object, default: null },
  saving:     { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'save', 'ask', 'close'])

// useRouter() is undefined when mounted outside a router (unit tests).
const router = useRouter()

const draft = ref('')
const preview = ref(false)

const STATUS_LABEL = { filled: 'Filled', partial: 'Partial', missing: 'Missing' }
const STATUS_PILL  = { filled: 'sn-pill-success', partial: 'sn-pill-warn', missing: 'sn-pill-danger' }

const status = computed(() => (STATUS_LABEL[props.file?.status] ? props.file.status : 'missing'))
const statusLabel = computed(() => STATUS_LABEL[status.value])
const statusPillClass = computed(() => STATUS_PILL[status.value])

const displayTitle = computed(() => props.file?.title || props.file?.path || 'Brain file')
const placeholder = computed(() => `Write ${displayTitle.value} in plain markdown — headings, bullets, bold.`)

const original = computed(() => props.file?.body_md ?? props.file?.body ?? '')
const dirty = computed(() => draft.value !== original.value)

const previewHtml = computed(() =>
  draft.value.trim()
    ? renderMarkdown(draft.value)
    : '<p style="color: var(--text-muted);">Nothing written yet.</p>',
)

// Reset the editor whenever the drawer opens or is pointed at another file.
watch(
  () => [props.modelValue, props.file],
  ([open]) => {
    if (!open) return
    draft.value = original.value
    preview.value = false
  },
  { immediate: true },
)

function setOpen(value) {
  emit('update:modelValue', value)
}

function save() {
  if (props.saving || !props.file?.path) return
  emit('save', { path: props.file.path, body_md: draft.value })
}

function askAtlas() {
  const prefill =
    props.file?.ask_prompt ||
    `Help me fill in "${displayTitle.value}" (${props.file?.path || 'brain file'}) for my business brain. Ask me what you need, then draft it.`
  emit('ask', { ...props.file, prefill })
  setOpen(false)
  router?.push({ path: '/atlas', query: { hannah: '1', prefill } })
}
</script>

<style scoped>
/* Preview typography — Tailwind preflight strips heading/list styling. */
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
