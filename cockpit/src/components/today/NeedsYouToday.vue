<template>
  <section class="sn-card p-0 overflow-hidden" aria-labelledby="needs-you-today-title" data-testid="needs-you-today">
    <header class="px-4 py-3 border-b flex items-start justify-between gap-3" style="border-color: var(--border);">
      <div class="min-w-0">
        <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">
          Needs you today<span v-if="store.brief.date"> · {{ store.brief.date }}</span>
        </div>
        <h3 id="needs-you-today-title" class="font-heading text-[15px] font-semibold mt-0.5" style="color: var(--text-primary);">
          {{ headline }}
        </h3>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <span v-if="store.moneyAtStake > 0" class="sn-pill sn-pill-warn mono" data-testid="today-money">
          {{ fmtMoney(store.moneyAtStake) }} at stake
        </span>
        <button type="button" class="sn-btn py-1 px-2 text-xs" :disabled="store.loading" data-testid="today-refresh" @click="store.fetchToday()">
          {{ store.loading ? 'Loading…' : 'Refresh' }}
        </button>
      </div>
    </header>

    <p
      v-if="store.error"
      class="px-4 py-2 text-xs border-b"
      style="color: var(--amber); border-color: var(--border); background: rgba(245,165,36,0.06);"
      data-testid="today-error"
    >{{ store.error }}</p>

    <ol v-if="store.items.length" class="divide-y" style="border-color: var(--divider);" data-testid="today-items">
      <li
        v-for="item in store.items"
        :key="item.id"
        class="px-4 py-3 flex items-start gap-3"
        :data-testid="`today-item-${item.id}`"
        :data-kind="item.kind"
      >
        <span class="sn-pill text-[10px] shrink-0 mt-0.5" :class="kindOf(item.kind).pill">{{ kindOf(item.kind).label }}</span>
        <div class="min-w-0 flex-1">
          <p class="text-sm" style="color: var(--text-primary);">{{ item.title }}</p>
          <p v-if="item.detail" class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ item.detail }}</p>
          <p class="text-[11px] mt-0.5 mono" style="color: var(--text-muted);">
            <span v-if="item.age">{{ item.age }}</span>
            <span v-if="item.age && item.money"> · </span>
            <span v-if="item.money">{{ fmtMoney(item.money.amount, item.money.currency) }}</span>
          </p>
        </div>
        <div class="flex items-center gap-1 shrink-0">
          <RouterLink
            v-if="item.action?.path"
            :to="item.action.path"
            class="sn-btn py-0.5 px-2 text-[11px]"
            :data-testid="`today-action-${item.id}`"
          >{{ item.action.label || 'Open' }}</RouterLink>
          <button
            type="button"
            class="sn-btn-secondary text-[11px]"
            style="padding: 0.2rem 0.45rem;"
            :aria-label="`Dismiss ${item.title}`"
            :data-testid="`today-dismiss-${item.id}`"
            @click="store.dismiss(item.id)"
          >✕</button>
        </div>
      </li>
    </ol>
    <p v-else-if="!store.loading" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);" data-testid="today-empty">
      Nothing needs you right now. Atlas will say so the moment that changes.
    </p>
    <p v-if="store.hiddenCount" class="px-4 py-2 text-[11px]" style="color: var(--text-muted);" data-testid="today-hidden">
      {{ store.hiddenCount }} more waiting after these seven.
    </p>

    <div
      v-if="store.oneMoreQuestion"
      class="mx-4 my-3 rounded-lg px-3 py-2.5 flex items-start gap-3"
      style="background: var(--accent-weak); border: 1px solid rgba(0,214,201,0.30);"
      data-testid="today-question"
    >
      <span class="sn-pill sn-pill-accent text-[10px] shrink-0 mt-0.5">One more question</span>
      <div class="min-w-0 flex-1">
        <p class="text-sm" style="color: var(--text-primary);">{{ store.oneMoreQuestion.question }}</p>
        <p class="text-[11px] mono mt-0.5" style="color: var(--text-muted);">
          {{ store.oneMoreQuestion.path }}<span v-if="store.oneMoreQuestion.section"> › {{ store.oneMoreQuestion.section }}</span>
        </p>
      </div>
      <div class="flex items-center gap-1 shrink-0">
        <RouterLink :to="answerRoute" class="sn-btn py-0.5 px-2 text-[11px]" data-testid="today-question-answer">Answer</RouterLink>
        <button type="button" class="sn-btn-secondary text-[11px]" style="padding: 0.2rem 0.5rem;" data-testid="today-question-skip" @click="store.skipQuestion()">Skip</button>
      </div>
    </div>

    <div v-if="store.overnight.length" class="px-4 py-3 border-t" style="border-color: var(--border);" data-testid="today-overnight">
      <div class="text-[10px] tracking-widest uppercase font-semibold mb-2" style="color: var(--text-muted);">What ran overnight</div>
      <ul class="space-y-1.5">
        <li v-for="run in store.overnight" :key="run.id" class="flex items-start gap-2 text-xs" :data-testid="`today-overnight-${run.id}`">
          <span class="w-1.5 h-1.5 rounded-full shrink-0 mt-1.5" :style="runStatusDot(run.status)" aria-hidden="true"></span>
          <div class="min-w-0 flex-1">
            <RouterLink v-if="run.path" :to="run.path" class="font-medium hover:underline" style="color: var(--text-primary);">{{ run.skill_name || run.id }}</RouterLink>
            <span v-else class="font-medium" style="color: var(--text-primary);">{{ run.skill_name || run.id }}</span>
            <span style="color: var(--text-secondary);"> — {{ run.summary }}</span>
          </div>
        </li>
      </ul>
    </div>
  </section>
</template>

<script setup>
/**
 * NeedsYouToday — the deterministic morning brief (D8 #3) at the top of
 * the dashboard: at most seven items with one action each, the
 * "one more question" chip (answer via Atlas prefill / skip), and what
 * ran overnight. Reads everything from the today store; the store falls
 * back to the fixture with an inline error line when /api/today is down.
 */
import { computed, onMounted } from 'vue'
import { useTodayStore, ITEM_KINDS } from '../../stores/today.js'
import { fmtMoney, runStatusDot } from '../../utils/format.js'

const props = defineProps({
  autoload: { type: Boolean, default: true },
})

const store = useTodayStore()

const headline = computed(() => {
  const n = store.items.length
  if (store.loading && !n) return 'Reading the brief…'
  if (!n) return 'All clear'
  return `${n} thing${n === 1 ? '' : 's'} need${n === 1 ? 's' : ''} a decision from you`
})

function kindOf(kind) {
  return ITEM_KINDS[kind] || { label: kind || 'Item', pill: 'sn-pill' }
}

const answerRoute = computed(() => {
  const q = store.oneMoreQuestion
  if (!q) return '/atlas'
  const prefill = `${q.question.replace(/\s*\(or say skip\)\s*$/i, '')} — save my answer to ${q.path}${q.section ? ` › ${q.section}` : ''}.`
  return { path: '/atlas', query: { hannah: '1', prefill } }
})

onMounted(() => {
  if (props.autoload) store.fetchToday()
})
</script>
