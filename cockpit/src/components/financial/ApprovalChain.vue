<template>
  <ol class="space-y-0" data-testid="approval-chain">
    <li
      v-for="(step, i) in steps"
      :key="step.id ?? i"
      class="relative flex gap-3 pb-4 last:pb-0"
      :data-testid="`chain-step-${i}`"
    >
      <!-- Rail -->
      <div class="flex flex-col items-center">
        <span
          class="w-6 h-6 rounded-full grid place-items-center text-[10px] font-semibold shrink-0 border"
          :style="dotStyle(step, i)"
        >
          <svg v-if="statusOf(step) === 'approved'" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          <svg v-else-if="statusOf(step) === 'rejected'" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
            <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/>
          </svg>
          <template v-else>{{ i + 1 }}</template>
        </span>
        <span
          v-if="i < steps.length - 1"
          class="w-px flex-1 mt-1"
          style="background: var(--border);"
        ></span>
      </div>

      <!-- Body -->
      <div class="min-w-0 flex-1 pt-0.5">
        <div class="flex items-center gap-2 flex-wrap">
          <span class="text-sm font-medium" style="color: var(--text-primary);">
            {{ step.approver_name || step.approver || step.role || `Step ${i + 1}` }}
          </span>
          <span v-if="step.role && (step.approver_name || step.approver)" class="text-[11px]" style="color: var(--text-muted);">
            {{ step.role }}
          </span>
          <span class="text-[10px]" :class="statusPill(statusOf(step))" :data-testid="`chain-step-${i}-status`">
            {{ statusOf(step) }}
          </span>
        </div>
        <div v-if="step.acted_at" class="text-[11px] mt-0.5 mono" style="color: var(--text-muted);">
          {{ formatDate(step.acted_at) }}
        </div>
        <p v-if="step.response" class="text-xs mt-1 rounded-md px-2 py-1.5"
           style="background: var(--bg-elevated); color: var(--text-secondary); border: 1px solid var(--border);">
          {{ step.response }}
        </p>
      </div>
    </li>
  </ol>
</template>

<script setup>
/**
 * ApprovalChain — vertical stepper for a multi-stage approval.
 *
 * `steps` come from GET /api/approvals/{approval_id}. Each step:
 * { id, role, approver_name, status, acted_at, response }
 * Status vocabulary: approved | pending | queued | rejected | skipped | expired.
 * `currentStep` (index) highlights the active pending step.
 */
const props = defineProps({
  steps:       { type: Array, default: () => [] },
  currentStep: { type: Number, default: -1 },
})

function statusOf(step) {
  return step.status || 'queued'
}

function statusPill(s) {
  if (s === 'approved') return 'sn-pill sn-pill-success'
  if (s === 'rejected') return 'sn-pill sn-pill-danger'
  if (s === 'pending')  return 'sn-pill sn-pill-warn'
  if (s === 'expired')  return 'sn-pill sn-pill-danger'
  return 'sn-pill' // queued | skipped
}

function dotStyle(step, i) {
  const s = statusOf(step)
  if (s === 'approved')
    return 'background: rgba(49,214,123,0.14); color: var(--success); border-color: rgba(49,214,123,0.40);'
  if (s === 'rejected' || s === 'expired')
    return 'background: rgba(240,93,94,0.14); color: var(--danger); border-color: rgba(240,93,94,0.40);'
  if (s === 'pending' || i === props.currentStep)
    return 'background: var(--accent-weak); color: var(--accent); border-color: rgba(0,214,201,0.40);'
  return 'background: var(--bg-elevated); color: var(--text-muted); border-color: var(--border);'
}

function formatDate(ts) {
  try {
    return new Date(ts).toLocaleString()
  } catch {
    return String(ts)
  }
}
</script>
