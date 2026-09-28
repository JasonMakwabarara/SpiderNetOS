<template>
  <section class="sn-card p-4" data-testid="brain-readiness">
    <div class="flex items-start justify-between gap-3 mb-3">
      <div class="min-w-0">
        <p class="sn-eyebrow">Brain</p>
        <h3 class="text-sm font-semibold mt-0.5" style="color: var(--text-primary);">{{ title }}</h3>
        <p class="text-xs mt-1" style="color: var(--text-secondary);" data-testid="brain-readiness-summary">
          {{ summary }}
        </p>
      </div>
      <span
        v-if="files.length"
        class="sn-pill shrink-0"
        :class="allReady ? 'sn-pill-success' : 'sn-pill-warn'"
        data-testid="brain-readiness-pill"
      >{{ filledCount }}/{{ files.length }} ready</span>
    </div>

    <p
      v-if="!files.length"
      class="text-xs"
      style="color: var(--text-muted);"
      data-testid="brain-readiness-empty"
    >No brain files are linked to this yet.</p>

    <ul v-else class="space-y-2">
      <li
        v-for="file in files"
        :key="fileKey(file)"
        class="rounded-lg px-3 py-2 flex items-start justify-between gap-3"
        style="background: var(--bg-elevated); border: 1px solid var(--border);"
        :data-testid="`brain-file-${fileKey(file)}`"
        :data-status="statusOf(file)"
      >
        <div class="min-w-0">
          <p class="text-sm font-medium flex items-center gap-1.5 min-w-0" style="color: var(--text-primary);">
            <span
              class="shrink-0"
              :style="{ color: STATUS[statusOf(file)].color }"
              aria-hidden="true"
              :data-testid="`brain-file-glyph-${fileKey(file)}`"
            >{{ STATUS[statusOf(file)].glyph }}</span>
            <span class="sr-only">{{ STATUS[statusOf(file)].label }}.</span>
            <span class="truncate">{{ file.title || file.path || fileKey(file) }}</span>
            <span
              v-if="file.required"
              class="sn-pill sn-pill-warn shrink-0"
              style="font-size: 0.62rem; padding: 1px 7px;"
              :data-testid="`brain-file-required-${fileKey(file)}`"
            >Required</span>
          </p>
          <p v-if="file.path" class="text-[11px] mono mt-0.5 truncate" style="color: var(--text-muted);">
            {{ file.path }}
          </p>
        </div>

        <div class="flex items-center gap-1.5 shrink-0">
          <button
            type="button"
            class="sn-btn-secondary text-xs"
            style="padding: 0.3rem 0.6rem;"
            :data-testid="`brain-file-open-${fileKey(file)}`"
            @click="emit('open', file)"
          >Open file</button>
          <button
            v-if="statusOf(file) !== 'filled'"
            type="button"
            class="sn-btn-secondary text-xs"
            style="padding: 0.3rem 0.6rem; color: var(--accent); border-color: rgba(0,214,201,0.30);"
            :data-testid="`brain-file-ask-${fileKey(file)}`"
            @click="emit('ask', file)"
          >Ask Atlas</button>
        </div>
      </li>
    </ul>
  </section>
</template>

<script setup>
/**
 * BrainReadiness — "reads before it writes" checklist. Each row is a brain
 * file a skill (or the launch interview) depends on, with a ✓ / ◐ / ○
 * glyph for filled / partial / missing. The parent decides what "Open
 * file" and "Ask Atlas" do (usually a BrainFileDrawer and an Atlas
 * prefill) — this component only emits.
 *
 * files: [{ key?, path?, title?, status: 'filled'|'partial'|'missing', required?, ask_prompt? }]
 */
import { computed } from 'vue'

const props = defineProps({
  files: { type: Array, default: () => [] },
  title: { type: String, default: 'Reads before it writes' },
})

const emit = defineEmits(['open', 'ask'])

const STATUS = {
  filled:  { glyph: '✓', label: 'Filled',  color: 'var(--status-live)' },
  partial: { glyph: '◐', label: 'Partial', color: 'var(--status-assisted)' },
  missing: { glyph: '○', label: 'Missing', color: 'var(--status-missing)' },
}

function statusOf(file) {
  return STATUS[file?.status] ? file.status : 'missing'
}

// Stable, DOM-safe key: explicit `key`, else the path's basename minus
// extension ("brain/offer.md" → "offer").
function fileKey(file) {
  const raw = file?.key || String(file?.path || '').split('/').pop().replace(/\.[^.]+$/, '') || 'file'
  return String(raw).replace(/[^a-z0-9_-]+/gi, '-')
}

const filledCount = computed(() => props.files.filter((f) => statusOf(f) === 'filled').length)
const missingRequired = computed(() =>
  props.files.filter((f) => f.required && statusOf(f) !== 'filled').length,
)
const allReady = computed(() => props.files.length > 0 && filledCount.value === props.files.length)

const summary = computed(() => {
  if (!props.files.length) return 'Nothing to read yet.'
  if (allReady.value) return 'Every file this needs is filled in.'
  if (missingRequired.value) {
    return `${missingRequired.value} required file${missingRequired.value === 1 ? '' : 's'} still need${missingRequired.value === 1 ? 's' : ''} filling before this can run.`
  }
  return 'Optional files are still thin — Atlas can help fill them.'
})
</script>
