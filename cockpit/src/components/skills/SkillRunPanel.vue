<template>
  <aside class="sn-card p-4 space-y-4" aria-labelledby="skill-run-title" data-testid="skill-run-panel">
    <div>
      <p class="sn-eyebrow">{{ cta }}</p>
      <h2 id="skill-run-title" class="text-base font-semibold mt-0.5" style="color: var(--text-primary);">
        {{ cta }} {{ card.name }}
      </h2>
      <p v-if="card.one_liner" class="text-xs mt-1" style="color: var(--text-secondary);">{{ card.one_liner }}</p>
    </div>

    <form v-if="fields.length" class="space-y-3" data-testid="skill-run-form" @submit.prevent="submit">
      <div v-for="f in fields" :key="f.key">
        <label
          :for="`skill-input-${f.key}`"
          class="block text-xs font-medium mb-1"
          style="color: var(--text-secondary);"
        >{{ f.label }}<span v-if="f.required" style="color: var(--amber);" aria-hidden="true"> *</span></label>

        <select
          v-if="f.widget === 'select'"
          :id="`skill-input-${f.key}`"
          v-model="inputs[f.key]"
          class="sn-input"
          :required="f.required"
          :data-testid="`skill-input-${f.key}`"
        >
          <option v-if="!f.required" value="">—</option>
          <option v-for="opt in f.enum" :key="String(opt)" :value="opt">{{ opt }}</option>
        </select>

        <textarea
          v-else-if="f.widget === 'textarea'"
          :id="`skill-input-${f.key}`"
          v-model="inputs[f.key]"
          class="sn-textarea"
          rows="4"
          :required="f.required"
          :placeholder="f.placeholder"
          :data-testid="`skill-input-${f.key}`"
        ></textarea>

        <input
          v-else-if="f.widget === 'number'"
          :id="`skill-input-${f.key}`"
          v-model.number="inputs[f.key]"
          type="number"
          class="sn-input"
          :min="f.min"
          :max="f.max"
          :step="f.step"
          :required="f.required"
          :data-testid="`skill-input-${f.key}`"
        />

        <label
          v-else-if="f.widget === 'checkbox'"
          class="flex items-center gap-2 text-sm"
          style="color: var(--text-primary);"
        >
          <input
            :id="`skill-input-${f.key}`"
            v-model="inputs[f.key]"
            type="checkbox"
            :data-testid="`skill-input-${f.key}`"
          />
          <span>{{ f.description || f.label }}</span>
        </label>

        <input
          v-else
          :id="`skill-input-${f.key}`"
          v-model="inputs[f.key]"
          type="text"
          class="sn-input"
          :required="f.required"
          :placeholder="f.placeholder"
          :data-testid="`skill-input-${f.key}`"
        />

        <p v-if="f.description && f.widget !== 'checkbox'" class="text-[11px] mt-1" style="color: var(--text-muted);">
          {{ f.description }}
        </p>
      </div>
    </form>
    <p v-else class="text-xs" style="color: var(--text-muted);" data-testid="skill-run-no-inputs">
      No inputs needed — {{ isOpen ? 'open it to start' : 'just run it' }}.
    </p>

    <p
      v-if="blockedReason"
      class="text-xs rounded-md px-3 py-2"
      style="background: rgba(245,165,36,0.10); color: var(--amber); border: 1px solid rgba(245,165,36,0.30);"
      data-testid="skill-run-blocked-reason"
    >{{ blockedReason }}</p>
    <p
      v-else-if="validation"
      class="text-[11px]"
      style="color: var(--text-muted);"
      data-testid="skill-run-validation"
    >{{ validation }}</p>

    <div class="flex items-center gap-2 flex-wrap">
      <RouterLink
        v-if="isOpen && !blockedReason"
        :to="card.entry_path"
        class="sn-btn-primary"
        data-testid="skill-run-button"
      >Open</RouterLink>
      <button
        v-else
        type="button"
        class="sn-btn-primary"
        :disabled="!canRun"
        :aria-disabled="canRun ? undefined : 'true'"
        data-testid="skill-run-button"
        @click="submit"
      >{{ running ? 'Starting…' : cta }}</button>

      <button
        v-if="!card.enabled && card.provisioning !== 'requires_pack'"
        type="button"
        class="sn-btn-secondary"
        :disabled="running"
        data-testid="skill-run-enable"
        @click="emit('enable')"
      >Enable</button>
      <RouterLink
        v-if="card.provisioning === 'requires_pack'"
        :to="installPath"
        class="sn-btn-secondary"
        data-testid="skill-run-install"
      >Install pack</RouterLink>
    </div>

    <!-- 422 missing_brain / 202 questions → answer inline, then re-run -->
    <form
      v-if="questions.length"
      class="space-y-3 rounded-lg p-3"
      style="background: var(--bg-elevated); border: 1px solid var(--border);"
      data-testid="skill-run-questions"
      @submit.prevent="submitAnswers"
    >
      <p class="text-sm font-medium" style="color: var(--text-primary);">
        Atlas needs {{ questions.length === 1 ? 'one answer' : `${questions.length} answers` }} before it can write
      </p>
      <div v-for="(qn, i) in questions" :key="`${qn.path}#${qn.section || i}`">
        <label
          :for="`skill-run-answer-${i}`"
          class="block text-sm mb-1"
          style="color: var(--text-primary);"
        >{{ qn.question }}</label>
        <p class="mono text-[11px] mb-1" style="color: var(--text-muted);">
          {{ qn.path }}<span v-if="qn.section"> › {{ qn.section }}</span>
        </p>
        <textarea
          :id="`skill-run-answer-${i}`"
          v-model="answers[i]"
          class="sn-textarea"
          rows="3"
          style="min-height: 4.5rem;"
          :data-testid="`skill-run-answer-${i}`"
        ></textarea>
      </div>
      <button
        type="submit"
        class="sn-btn-primary"
        :disabled="running || !answersComplete"
        data-testid="skill-run-answers-submit"
      >{{ running ? 'Sending…' : 'Answer and run' }}</button>
    </form>

    <div
      v-if="result"
      role="status"
      aria-live="polite"
      class="rounded-lg p-3 text-sm"
      :style="resultStyle"
      data-testid="skill-run-result"
      :data-outcome="outcome"
    >
      <template v-if="result.success">
        <p class="font-medium" style="color: var(--text-primary);">{{ resultTitle }}</p>
        <p v-if="result.data?.status" class="text-xs mt-0.5" style="color: var(--text-secondary);">
          Status: {{ result.data.status }}
        </p>
        <div class="flex flex-wrap gap-2 mt-2">
          <RouterLink
            v-if="result.data?.run_id"
            :to="`/agents/runs/${result.data.run_id}`"
            class="sn-chip"
            data-testid="skill-run-link-run"
          >View run</RouterLink>
          <RouterLink
            v-if="result.data?.approval_id"
            :to="{ path: '/approvals', query: { id: result.data.approval_id } }"
            class="sn-chip"
            data-testid="skill-run-link-approval"
          >Approval</RouterLink>
          <RouterLink
            v-if="result.data?.entry_path"
            :to="result.data.entry_path"
            class="sn-chip"
            data-testid="skill-run-link-draft"
          >Open draft</RouterLink>
        </div>
      </template>
      <template v-else-if="result.status === 402">
        <p class="font-medium" style="color: var(--text-primary);">This skill needs a pack</p>
        <p class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ result.checkout_hint || result.error }}</p>
        <RouterLink :to="packPath(result.pack_id)" class="sn-chip mt-2" data-testid="skill-run-checkout">
          Install {{ result.pack_id || 'the pack' }}
        </RouterLink>
      </template>
      <p v-else-if="result.missing_brain" class="text-xs" style="color: var(--text-secondary);">
        {{ result.error }}
      </p>
      <p v-else style="color: var(--danger);" data-testid="skill-run-error">{{ result.error }}</p>
    </div>
  </aside>
</template>

<script setup>
/**
 * SkillRunPanel — the sticky right-hand panel on a skill card. Builds the
 * inputs form from the card's JSON-schema-ish `inputs` block, refuses to
 * run (with a reason) while required brain files are missing, and turns a
 * 422 `missing_brain` (or a 202 with `questions`) into inline answer boxes.
 *
 * The parent owns the API calls: this emits
 *   run({ inputs, answers? })           — start (or re-start with answers)
 *   answer({ run_id, answers })         — answers for an already-created blocked run
 *   enable()                            — enable the skill first
 * and receives the outcome back through `result`.
 */
import { computed, reactive, ref, watch } from 'vue'

const props = defineProps({
  card:    { type: Object, required: true },
  running: { type: Boolean, default: false },
  result:  { type: Object, default: null },
})

const emit = defineEmits(['run', 'answer', 'enable'])

const inputs = reactive({})
const answers = ref([])

const cta = computed(() => (props.card.run_cta === 'Open' ? 'Open' : 'Run'))
const isOpen = computed(() => props.card.run_cta === 'Open' && !!props.card.entry_path)

// ── Inputs schema → form fields ──────────────────────────────────────
const fields = computed(() => {
  const schema = props.card.inputs || {}
  const required = new Set(schema.required || [])
  return Object.entries(schema.properties || {}).map(([key, prop]) => {
    const p = prop || {}
    let widget = 'text'
    if (Array.isArray(p.enum)) widget = 'select'
    else if (p.type === 'integer' || p.type === 'number') widget = 'number'
    else if (p.type === 'boolean') widget = 'checkbox'
    else if (p.format === 'textarea' || p['x-widget'] === 'textarea' || p['x-ui'] === 'textarea' || (p.maxLength || 0) > 200) widget = 'textarea'
    return {
      key,
      label: p.title || key.replace(/[-_]+/g, ' ').replace(/^\w/, (c) => c.toUpperCase()),
      description: p.description || '',
      placeholder: p.placeholder || p.example || '',
      required: required.has(key),
      widget,
      enum: p.enum || [],
      min: p.minimum, max: p.maximum, step: p.type === 'integer' ? 1 : undefined,
      default: p.default,
    }
  })
})

function resetInputs() {
  Object.keys(inputs).forEach((k) => delete inputs[k])
  for (const f of fields.value) {
    if (f.default !== undefined) inputs[f.key] = f.default
    else if (f.widget === 'checkbox') inputs[f.key] = false
    else if (f.widget === 'select' && f.required && f.enum.length) inputs[f.key] = f.enum[0]
    else inputs[f.key] = ''
  }
}

watch(() => props.card?.slug, resetInputs, { immediate: true })

function plainInputs() {
  const out = {}
  for (const f of fields.value) {
    const v = inputs[f.key]
    if (v === '' || v === null || v === undefined) continue
    out[f.key] = v
  }
  return out
}

// ── Gating ───────────────────────────────────────────────────────────
const missingRequired = computed(() =>
  (props.card.brain?.files || []).filter((f) => f.required && f.status !== 'filled'),
)

const blockedReason = computed(() => {
  if (props.card.provisioning === 'requires_pack') {
    const pack = props.card.requires_pack?.name || props.card.requires_pack?.pack_id || 'a feature pack'
    return `Install ${pack} to unlock this skill.`
  }
  if (props.card.enabled === false) return 'Enable this skill before running it.'
  if (missingRequired.value.length) {
    const names = missingRequired.value.map((f) => f.title || f.path).join(', ')
    const n = missingRequired.value.length
    return `Fill ${n} required brain file${n === 1 ? '' : 's'} first: ${names}.`
  }
  return ''
})

const validation = computed(() => {
  const missing = fields.value.filter((f) => f.required && (inputs[f.key] === '' || inputs[f.key] === null || inputs[f.key] === undefined))
  if (!missing.length) return ''
  return `Needs ${missing.map((f) => f.label).join(', ')}.`
})

const canRun = computed(() => !props.running && !blockedReason.value && !validation.value)

const installPath = computed(() => packPath(props.card.requires_pack?.pack_id || props.card.requires_pack?.id))

function packPath(packId) {
  return packId ? { path: '/feature-packs', hash: `#pack-${packId}` } : '/feature-packs'
}

// ── Submit / answers ─────────────────────────────────────────────────
function submit() {
  if (!canRun.value || isOpen.value) return
  emit('run', { inputs: plainInputs() })
}

const questions = computed(() => {
  const r = props.result
  if (!r) return []
  if (Array.isArray(r.missing_brain) && r.missing_brain.length) return r.missing_brain
  if (r.success && Array.isArray(r.data?.questions) && r.data.questions.length) return r.data.questions
  return []
})

watch(questions, (qs) => { answers.value = qs.map(() => '') }, { immediate: true })

const answersComplete = computed(() =>
  questions.value.length > 0 && questions.value.every((_, i) => String(answers.value[i] || '').trim().length > 0),
)

function submitAnswers() {
  if (!answersComplete.value || props.running) return
  const payload = questions.value.map((q, i) => ({ path: q.path, section: q.section, text: String(answers.value[i]).trim() }))
  const runId = props.result?.data?.run_id
  if (runId && !props.result?.missing_brain) emit('answer', { run_id: runId, answers: payload })
  else emit('run', { inputs: plainInputs(), answers: payload })
}

// ── Result card ──────────────────────────────────────────────────────
const outcome = computed(() => {
  const r = props.result
  if (!r) return ''
  if (r.success) return questions.value.length ? 'questions' : 'started'
  if (r.status === 402) return 'checkout'
  if (r.missing_brain) return 'missing_brain'
  return 'error'
})

const resultTitle = computed(() => {
  const s = props.result?.data?.status
  if (s === 'blocked') return 'Run created — it is waiting on your answers'
  if (s === 'waiting_approval') return 'Drafted — waiting for your approval'
  if (s === 'succeeded' || s === 'done') return 'Done'
  return 'Run started'
})

const resultStyle = computed(() => {
  if (outcome.value === 'started' || outcome.value === 'questions') return 'background: rgba(34,211,155,0.08); border: 1px solid rgba(34,211,155,0.30);'
  if (outcome.value === 'error') return 'background: rgba(255,90,122,0.08); border: 1px solid rgba(255,90,122,0.30);'
  return 'background: var(--bg-elevated); border: 1px solid var(--border);'
})
</script>
