<template>
  <div class="py-2" data-testid="budget-bar">
    <div class="flex items-baseline justify-between gap-3">
      <span class="text-sm truncate" style="color: var(--text-primary);" data-testid="budget-bar-label">
        {{ label }}
      </span>
      <span class="text-xs mono shrink-0">
        <span
          :style="pct > 100 ? 'color: var(--danger);' : 'color: var(--text-secondary);'"
          data-testid="budget-bar-actual"
        >{{ formatAmount(actual) }}</span>
        <span style="color: var(--text-muted);"> / <span data-testid="budget-bar-budget">{{ formatAmount(budget) }}</span></span>
      </span>
    </div>

    <div class="mt-1.5 h-1.5 rounded-full overflow-hidden" style="background: var(--bg-elevated);">
      <div
        class="h-full rounded-full transition-all"
        :class="fillClass"
        :style="`width: ${widthPct}%;`"
        data-testid="budget-bar-fill"
      ></div>
    </div>

    <div class="mt-1 flex items-center justify-between text-[11px]">
      <span class="mono" style="color: var(--text-muted);" data-testid="budget-bar-pct">{{ pct }}%</span>
      <span
        v-if="pct > 100"
        class="font-medium"
        style="color: var(--danger);"
        data-testid="budget-bar-over"
      >over budget</span>
      <span
        v-else-if="pct > 90"
        class="font-medium"
        style="color: var(--amber);"
        data-testid="budget-bar-near"
      >near limit</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

/**
 * BudgetBar — budget vs actual meter for spend analytics.
 *
 * Threshold coloring: ≤90% accent, >90% --amber, >100% --danger.
 * The fill width tracks actual/budget and caps at 100% so overspend
 * never overflows the track — the danger color + "over budget" flag
 * carry that signal instead. Amounts render .mono per cockpit style.
 */
const props = defineProps({
  label:  { type: String, required: true },
  budget: { type: [Number, String], default: 0 },
  actual: { type: [Number, String], default: 0 },
})

const pct = computed(() => {
  const budget = Number(props.budget) || 0
  if (budget <= 0) return 0
  return Math.round((Number(props.actual) / budget) * 100)
})

const widthPct = computed(() => Math.max(0, Math.min(100, pct.value)))

const fillClass = computed(() => {
  if (pct.value > 100) return 'bb-danger'
  if (pct.value > 90) return 'bb-amber'
  return 'bb-ok'
})

function formatAmount(v) {
  return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })
}
</script>

<style scoped>
.bb-ok     { background: var(--accent); }
.bb-amber  { background: var(--amber); }
.bb-danger { background: var(--danger); }
</style>
