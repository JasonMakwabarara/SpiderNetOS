<template>
  <div class="px-6 py-7 max-w-6xl mx-auto" data-testid="board-room-page">
    <div class="mb-6">
      <div class="sn-eyebrow">Operate · Board room</div>
      <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Board of advisors</h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">
        Five seats read your brain, answer in isolation, then argue anonymously. The chairman hands you one decision.
      </p>
    </div>

    <p
      v-if="store.disabled"
      class="sn-card p-4 text-sm"
      style="color: var(--text-secondary);"
      data-testid="board-disabled"
    >{{ store.error }}</p>

    <template v-else>
      <p
        v-if="store.error"
        class="text-sm mb-4 rounded-md px-3 py-2"
        style="color: var(--amber); background: rgba(245,165,36,0.08); border: 1px solid rgba(245,165,36,0.25);"
        data-testid="board-error"
      >{{ store.error }}</p>

      <!-- The seats, so it is obvious who is about to answer. -->
      <section class="mb-6" data-testid="board-seats">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Who sits</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          <article v-for="seat in store.seats" :key="seat.slug" class="sn-card p-4" :data-testid="`board-seat-${seat.slug}`">
            <h3 class="text-sm font-semibold" style="color: var(--text-primary);">{{ seat.display_name }}</h3>
            <p v-if="seat.inspired_by" class="text-xs mt-1" style="color: var(--text-muted);">
              Reasons with the frameworks of {{ seat.inspired_by }}
            </p>
            <p v-if="seat.asks_first?.length" class="text-sm mt-2" style="color: var(--text-secondary);">
              “{{ seat.asks_first[0] }}”
            </p>
          </article>
        </div>
        <p v-if="store.chair" class="text-xs mt-3" style="color: var(--text-muted);" data-testid="board-chair">
          {{ store.chair.display_name }} writes the synthesis and carries the dissent verbatim.
        </p>
      </section>

      <!-- Asking is a real spend, so it is a deliberate act with a visible cost. -->
      <section class="sn-card p-5 mb-6" data-testid="board-ask">
        <label for="board-question" class="text-sm font-semibold block mb-2" style="color: var(--text-primary);">
          Put a question to the board
        </label>
        <textarea
          id="board-question"
          v-model="question"
          rows="3"
          class="sn-input w-full"
          placeholder="Should we raise the price of the monthly plan by 20 percent?"
          :disabled="store.convening"
          data-testid="board-question"
        ></textarea>
        <div class="flex items-center justify-between gap-3 mt-3 flex-wrap">
          <p class="text-xs" style="color: var(--text-muted);">
            {{ store.seats.length }} seats × 2 rounds, plus the chairman. Every answer is read from your brain — nothing is invented.
          </p>
          <button
            type="button"
            class="sn-btn"
            :disabled="!canAsk"
            data-testid="board-convene"
            @click="ask"
          >{{ store.convening ? 'The board is sitting…' : 'Convene the board' }}</button>
        </div>
        <p v-if="question.length && question.length < MIN_QUESTION" class="text-xs mt-2" style="color: var(--amber);" data-testid="board-too-short">
          A question the board can answer needs a bit more than that.
        </p>
      </section>

      <!-- The verdict. -->
      <section v-if="store.verdict" class="sn-card p-5 mb-6" data-testid="board-verdict">
        <h2 class="text-base font-semibold mb-1" style="color: var(--text-primary);">{{ store.session.question }}</h2>
        <p class="text-xs mb-4 mono" style="color: var(--text-muted);">
          ${{ Number(store.session.cost_usd || 0).toFixed(4) }}
          <span v-if="store.session.brain_path"> · filed at {{ store.session.brain_path }}</span>
        </p>

        <table class="w-full text-sm mb-4">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wider" style="color: var(--text-muted);">
              <th class="py-2">Seat</th>
              <th class="py-2">Stance</th>
              <th class="py-2 text-right">Confidence</th>
              <th class="py-2">The number they watch</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in store.tableRows" :key="row.seat" style="border-top: 1px solid var(--border);">
              <td class="py-2" style="color: var(--text-primary);">{{ row.display_name || row.seat }}</td>
              <td class="py-2"><span :class="store.stanceOf(row.stance).pill">{{ store.stanceOf(row.stance).label }}</span></td>
              <td class="py-2 text-right mono" style="color: var(--text-secondary);">{{ Number(row.confidence || 0).toFixed(2) }}</td>
              <td class="py-2" style="color: var(--text-secondary);">{{ row.one_number }}</td>
            </tr>
          </tbody>
        </table>

        <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Consensus</h3>
        <p class="text-sm mt-1 mb-4" style="color: var(--text-secondary);">{{ store.verdict.consensus }}</p>

        <!-- Verbatim, always: the chairman may introduce the dissent, never rewrite it. -->
        <blockquote
          v-if="store.verdict.minority_report"
          class="text-sm mb-4 pl-3"
          style="border-left: 3px solid var(--amber); color: var(--text-secondary);"
          data-testid="board-minority"
        >
          <p class="text-xs uppercase tracking-wider mb-1" style="color: var(--amber);">
            Minority report — {{ store.verdict.minority_seat }}, verbatim
          </p>
          {{ store.verdict.minority_report }}
        </blockquote>

        <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Recommended action</h3>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ store.verdict.recommended_action }}</p>
        <p v-if="store.verdict.next_check_date" class="text-xs mt-2 mono" style="color: var(--text-muted);">
          Next check: {{ store.verdict.next_check_date }}
        </p>

        <ul v-if="store.verdict.kill_criteria?.length" class="text-sm mt-4 space-y-1" style="color: var(--text-secondary);">
          <li class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Kill criteria</li>
          <li v-for="(criterion, i) in store.verdict.kill_criteria" :key="i">— {{ criterion }}</li>
        </ul>

        <p class="text-xs mt-5 pt-3" style="border-top: 1px solid var(--border); color: var(--text-muted);" data-testid="board-disclaimer">
          The seats are archetypes that reason with published frameworks. They are not the people those frameworks
          belong to, they do not speak for them, and this is {{ lowerFirst(store.disclaimer) }}
        </p>
      </section>

      <section v-if="store.sessions.length" data-testid="board-sessions">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Past sessions</h2>
        <ul class="space-y-2">
          <li v-for="past in store.sessions" :key="past.id" class="sn-card p-3 text-sm flex items-center justify-between gap-3">
            <button type="button" class="text-left truncate" style="color: var(--text-primary);" @click="open(past)">
              {{ past.question }}
            </button>
            <span class="mono text-xs shrink-0" style="color: var(--text-muted);">{{ past.status }}</span>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useBoardStore, MIN_QUESTION } from '../../stores/board.js'

const store = useBoardStore()
const question = ref('')

const canAsk = computed(() => !store.convening && question.value.trim().length >= MIN_QUESTION && store.hasBoard)

function lowerFirst(text) {
  return text ? text.charAt(0).toLowerCase() + text.slice(1) : ''
}

async function ask() {
  const result = await store.convene(question.value.trim())
  if (result.success) question.value = ''
}

async function open(past) {
  await store.fetchSession(past.slug || past.id)
}

onMounted(async () => {
  await store.fetchSeats()
  if (!store.disabled) await store.fetchSessions()
})
</script>
