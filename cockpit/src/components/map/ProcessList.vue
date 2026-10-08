<template>
  <ul class="space-y-1.5" data-testid="process-list">
    <li
      v-if="!processes.length"
      class="text-xs px-2 py-1.5"
      style="color: var(--text-muted);"
      data-testid="process-list-empty"
    >No tasks yet — add the first one below.</li>

    <li
      v-for="p in processes"
      :key="p.id"
      class="text-sm rounded px-2 py-1.5 space-y-1"
      style="background: var(--surface-low);"
      :data-testid="`process-${p.id}`"
    >
      <div class="flex items-center justify-between gap-2">
        <span class="flex items-center gap-1.5 min-w-0" style="color: var(--text-primary);">
          <span
            v-if="p.flow_id"
            class="inline-block size-2 rounded-full shrink-0"
            :style="{ background: runDot(p) }"
            :title="p.last_run_status ? `Last run: ${p.last_run_status}` : 'Not run yet'"
            :data-testid="`process-dot-${p.id}`"
          />
          <span class="truncate">{{ p.name }}</span>
        </span>
        <span
          class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded shrink-0"
          :style="ownerBadge(p.owner_type)"
          :data-testid="`process-owner-${p.id}`"
        >{{ ownerLabel(p.owner_type) }}</span>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <button
          v-if="p.has_published_sop && !p.flow_id"
          type="button"
          class="text-[11px] underline"
          style="color: var(--accent);"
          :disabled="busyProcess === p.id"
          :data-testid="`process-automate-${p.id}`"
          @click="emit('automate', p)"
        >{{ busyProcess === p.id ? 'Compiling…' : 'Automate' }}</button>

        <button
          v-if="p.flow_id && !p.needs_attention"
          type="button"
          class="text-[11px] underline"
          style="color: var(--accent);"
          :disabled="busyProcess === p.id"
          :data-testid="`process-run-${p.id}`"
          @click="emit('run', p)"
        >{{ busyProcess === p.id ? 'Running…' : 'Run now' }}</button>

        <button
          v-if="p.needs_attention"
          type="button"
          class="text-[11px] underline font-semibold"
          style="color: var(--status-human);"
          :data-testid="`process-stuck-${p.id}`"
          @click="emit('escalate', p)"
        >Stuck — answer &amp; fix SOP</button>

        <span v-if="p.schedule_cron" class="text-[10px]" style="color: var(--text-muted);">
          {{ String(p.schedule_cron).replace('_', ' ') }}
        </span>
      </div>
    </li>
  </ul>
</template>

<script setup>
/**
 * ProcessList — the per-system task rows from the Systems map, extracted
 * so the business-map node drawer can reuse them. Pure presentation: the
 * parent (SystemsMap view / MapNodeDrawer) talks to the systemization
 * store and passes `busyProcess` back down.
 */
defineProps({
  processes:   { type: Array, default: () => [] },
  busyProcess: { type: [String, Number], default: null },
})

const emit = defineEmits(['automate', 'run', 'escalate'])

const OWNER_VAR = {
  founder: 'var(--owner-founder)',
  team:    'var(--owner-team)',
  agent:   'var(--owner-agent)',
}

function ownerBadge(ownerType) {
  const color = OWNER_VAR[ownerType] || OWNER_VAR.founder
  return { background: `color-mix(in srgb, ${color} 15%, transparent)`, color }
}

function ownerLabel(ownerType) {
  if (!ownerType || ownerType === 'founder') return 'you'
  return ownerType
}

// Run-state → status token: stuck = missing (red), passed = live,
// failed = human (someone needs to look), anything else = idle.
function runDot(p) {
  if (p.needs_attention) return 'var(--status-missing)'
  if (p.last_run_status === 'passed') return 'var(--status-live)'
  if (p.last_run_status === 'failed') return 'var(--status-human)'
  return 'var(--status-idle)'
}
</script>
