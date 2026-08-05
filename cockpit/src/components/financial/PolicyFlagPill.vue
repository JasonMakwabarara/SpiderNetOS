<template>
  <span class="text-[10px]" :class="pillClass" :title="label" data-testid="policy-flag-pill">
    {{ label }}
  </span>
</template>

<script setup>
import { computed } from 'vue'

/**
 * PolicyFlagPill — renders an expense policy violation code as a
 * warn/danger pill. Unknown codes fall back to a humanized warn pill.
 */
const props = defineProps({
  code: { type: String, required: true },
})

const FLAGS = {
  over_limit:          { label: 'Over limit',          severity: 'danger' },
  missing_receipt:     { label: 'Missing receipt',     severity: 'warn' },
  weekend_expense:     { label: 'Weekend expense',     severity: 'warn' },
  duplicate_suspect:   { label: 'Possible duplicate',  severity: 'danger' },
  category_cap:        { label: 'Category cap',        severity: 'warn' },
  stale_date:          { label: 'Older than 90 days',  severity: 'warn' },
  unapproved_merchant: { label: 'Unapproved merchant', severity: 'danger' },
}

const entry = computed(() => FLAGS[props.code] || {
  label: props.code.replaceAll('_', ' '),
  severity: 'warn',
})

const label = computed(() => entry.value.label)
const pillClass = computed(() =>
  entry.value.severity === 'danger' ? 'sn-pill sn-pill-danger' : 'sn-pill sn-pill-warn'
)
</script>
