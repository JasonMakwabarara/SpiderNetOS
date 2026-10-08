<template>
  <div data-testid="pipeline-stepper">
    <div class="flex items-center justify-between gap-3 mb-2">
      <p class="sn-section-title" style="margin-bottom: 0;">{{ label }}</p>
      <span
        v-if="inherited"
        class="sn-pill"
        title="This skill follows the tenant automation level until you set it explicitly."
        data-testid="pipeline-inherited"
      >Inherited from tenant</span>
    </div>

    <div
      role="radiogroup"
      :aria-label="label"
      :aria-disabled="canChange ? undefined : 'true'"
      class="grid grid-cols-3 gap-2"
      data-testid="pipeline-stages"
    >
      <button
        v-for="(s, idx) in STAGES"
        :key="s.id"
        :ref="(el) => (buttons[idx] = el)"
        type="button"
        role="radio"
        :aria-checked="s.id === stage ? 'true' : 'false'"
        :aria-disabled="canChange ? undefined : 'true'"
        :tabindex="s.id === stage ? 0 : -1"
        class="pipeline-stage text-left rounded-lg px-3 py-2 border transition-colors"
        :class="{ 'is-current': s.id === stage, 'is-reached': idx <= currentIndex, 'is-locked': !canChange }"
        :style="stageStyle(s, idx)"
        :data-testid="`pipeline-stage-${s.id}`"
        @click="select(s.id)"
        @keydown="onKeydown($event, idx)"
      >
        <span class="flex items-center gap-2">
          <span
            class="inline-block size-2 rounded-full shrink-0"
            :style="{ background: idx <= currentIndex ? s.color : 'var(--status-idle)' }"
            aria-hidden="true"
          />
          <span class="text-sm font-medium" style="color: var(--text-primary);">{{ s.label }}</span>
        </span>
        <span class="block text-[11px] mt-0.5" style="color: var(--text-secondary);">{{ s.hint }}</span>
      </button>
    </div>

    <p
      v-if="!canChange"
      class="text-[11px] mt-2"
      style="color: var(--text-muted);"
      data-testid="pipeline-locked"
    >{{ lockedReason }}</p>
  </div>
</template>

<script>
/**
 * PipelineStepper — the three-stage pipeline a skill runs at:
 * human_led → assisted → autonomous. Implements the WAI-ARIA radio-group
 * pattern (roving tabindex, arrow keys move + select). The parent owns
 * the value: `change` is emitted and the parent decides whether to
 * confirm (raising to autonomous goes through ConfirmDialog) and persist.
 *
 * Module scope (plain <script>) so defineProps' validator can reference
 * the stage list — defineProps is hoisted out of setup().
 */
export const PIPELINE_STAGES = [
  { id: 'human_led',  label: 'Human-led',  hint: 'You do it; Atlas assists',       color: 'var(--stage-human)' },
  { id: 'assisted',   label: 'Assisted',   hint: 'Atlas drafts; you approve',      color: 'var(--stage-assisted)' },
  { id: 'autonomous', label: 'Autonomous', hint: 'Atlas runs it; you review',      color: 'var(--stage-autonomous)' },
]
const STAGES = PIPELINE_STAGES
const STAGE_IDS = STAGES.map((s) => s.id)
</script>

<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
  stage:        { type: String, default: 'human_led', validator: (v) => STAGE_IDS.includes(v) },
  inherited:    { type: Boolean, default: false },
  canChange:    { type: Boolean, default: true },
  label:        { type: String, default: 'Pipeline stage' },
  lockedReason: { type: String, default: 'Only tenant managers can change the pipeline stage.' },
})

const emit = defineEmits(['change'])

const buttons = ref([])
const currentIndex = computed(() => Math.max(0, STAGE_IDS.indexOf(props.stage)))

function stageStyle(s, idx) {
  const current = s.id === props.stage
  return {
    borderColor: current ? s.color : 'var(--border)',
    background: current ? `color-mix(in srgb, ${s.color} 12%, transparent)` : 'var(--bg-elevated)',
    opacity: idx <= currentIndex.value || current ? 1 : 0.75,
    cursor: props.canChange ? 'pointer' : 'not-allowed',
  }
}

function select(id) {
  if (!props.canChange || id === props.stage) return
  emit('change', id)
}

function moveTo(idx) {
  const target = (idx + STAGES.length) % STAGES.length
  buttons.value[target]?.focus?.()
  select(STAGE_IDS[target])
}

function onKeydown(event, idx) {
  if (!props.canChange) return
  switch (event.key) {
    case 'ArrowRight':
    case 'ArrowDown':
      event.preventDefault(); moveTo(idx + 1); break
    case 'ArrowLeft':
    case 'ArrowUp':
      event.preventDefault(); moveTo(idx - 1); break
    case 'Home':
      event.preventDefault(); moveTo(0); break
    case 'End':
      event.preventDefault(); moveTo(STAGES.length - 1); break
    case ' ':
    case 'Enter':
      event.preventDefault(); select(STAGE_IDS[idx]); break
    default:
  }
}
</script>

<style scoped>
.pipeline-stage:hover:not(.is-locked) { border-color: var(--border-active) !important; }
.pipeline-stage.is-locked { cursor: not-allowed; }
</style>
