<template>
  <div class="px-6 py-7 max-w-6xl mx-auto" data-testid="systems-map-page">
    <div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
      <div class="min-w-0">
        <div class="sn-eyebrow">Operate · Systems Map</div>
        <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Your business, mapped</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">
          Every process gets a goal and exactly one owner. Delegate smallest-first until nothing needs you day-to-day.
        </p>
      </div>
      <RouterLink
        to="/map"
        class="sn-btn-secondary text-xs shrink-0"
        style="padding: 0.45rem 0.8rem;"
        data-testid="systems-map-link"
      >Map</RouterLink>
    </div>

    <!-- Founder load -->
    <div class="grid md:grid-cols-4 gap-4 mb-6" data-testid="founder-load">
      <div class="sn-card p-4">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Processes</p>
        <p class="text-2xl font-bold mt-1" style="color: var(--text-primary);">{{ founderLoad.total_processes }}</p>
      </div>
      <div class="sn-card p-4">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Still on you</p>
        <p class="text-2xl font-bold mt-1" style="color: var(--owner-founder);">
          {{ founderLoad.founder_owned }} ({{ founderLoad.founder_owned_pct }}%)
        </p>
      </div>
      <div class="sn-card p-4">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Delegated</p>
        <p class="text-2xl font-bold mt-1" style="color: var(--owner-team);">{{ founderLoad.delegated }}</p>
      </div>
      <div class="sn-card p-4">
        <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Automated</p>
        <p class="text-2xl font-bold mt-1" style="color: var(--owner-agent);">{{ founderLoad.automated }}</p>
      </div>
    </div>

    <!-- Snowball -->
    <div class="sn-card p-5 mb-6 space-y-3" data-testid="snowball">
      <h2 class="font-medium" style="color: var(--text-primary);">Snowball — delegate next</h2>
      <p class="text-sm" style="color: var(--text-secondary);">{{ snowball.next_action }}</p>
      <ul v-if="snowball.queue?.length" class="space-y-2">
        <li
          v-for="item in snowball.queue.slice(0, 5)"
          :key="item.id"
          class="flex items-center justify-between text-sm rounded-lg px-3 py-2"
          style="background: var(--surface-low);"
        >
          <span style="color: var(--text-primary);">
            {{ item.name }}
            <span class="text-xs ml-2" style="color: var(--text-muted);">{{ item.function }} · effort {{ item.effort_size }}/5</span>
          </span>
          <span
            class="text-xs"
            :style="{ color: item.has_published_sop ? 'var(--status-live)' : 'var(--status-assisted)' }"
          >
            {{ item.has_published_sop ? 'SOP ready — hand it off' : 'Needs SOP first' }}
          </span>
        </li>
      </ul>
    </div>

    <!-- Bootstrap empty state -->
    <div v-if="!systems.length" class="sn-card p-6 text-center space-y-3" data-testid="systems-empty">
      <p class="text-sm" style="color: var(--text-secondary);">
        No systems mapped yet. Start with the six core functions every business runs on.
      </p>
      <button
        type="button"
        class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold"
        :disabled="bootstrapping"
        data-testid="systems-bootstrap"
        @click="bootstrap"
      >
        {{ bootstrapping ? 'Creating…' : 'Create my systems map' }}
      </button>
    </div>

    <!-- Map by function -->
    <div v-else class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-for="system in systems"
        :key="system.id"
        class="sn-card p-4 space-y-3"
        :data-testid="`system-${system.id}`"
      >
        <div>
          <p class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">{{ system.function }}</p>
          <h3 class="font-medium" style="color: var(--text-primary);">{{ system.name }}</h3>
          <p class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ system.goal }}</p>
        </div>

        <ProcessList
          :processes="system.processes || []"
          :busy-process="busyProcess"
          @automate="onAutomate"
          @run="onRun"
          @escalate="openEscalation"
        />

        <form class="flex gap-2" @submit.prevent="addProcess(system)">
          <input
            v-model="drafts[system.id]"
            type="text"
            placeholder="Add a task…"
            class="flex-1 text-sm rounded-lg px-2 py-1.5 border"
            style="background: var(--surface-low); border-color: var(--border); color: var(--text-primary);"
            :aria-label="`Add a task to ${system.name}`"
          />
          <button type="submit" class="sn-btn px-3 py-1.5 rounded-lg text-xs font-semibold">Add</button>
        </form>
      </div>
    </div>

    <p v-if="error" class="text-sm mt-4" style="color: var(--danger);" data-testid="systems-error">{{ error }}</p>

    <PromptDialog
      v-model="escalationOpen"
      :title="escalating ? `“${escalating.name}” is stuck` : 'Stuck process'"
      message="It failed twice and its owner is stuck. What's the answer? It resolves the escalation AND becomes the next SOP revision, so this question never comes back."
      input-label="Your answer"
      placeholder="Explain what to do when this happens…"
      confirm-label="Resolve & update SOP"
      @confirm="submitEscalation"
      @cancel="escalating = null"
    />
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useSystemizationStore } from '../../stores/systemization.js'
import ProcessList from '../../components/map/ProcessList.vue'
import PromptDialog from '../../components/feedback/PromptDialog.vue'

const store = useSystemizationStore()
const { systems, founderLoad, snowball, busyProcess, error } = storeToRefs(store)

const drafts = reactive({})
const bootstrapping = ref(false)
const escalationOpen = ref(false)
const escalating = ref(null)

function onAutomate(p) {
  return store.automate(p.id)
}

function onRun(p) {
  return store.runNow(p.id)
}

function openEscalation(p) {
  escalating.value = p
  escalationOpen.value = true
}

async function submitEscalation(answer) {
  const p = escalating.value
  escalating.value = null
  if (!p || !answer) return
  await store.resolveEscalation(p.id, answer)
}

async function bootstrap() {
  bootstrapping.value = true
  try {
    await store.bootstrap()
  } finally {
    bootstrapping.value = false
  }
}

async function addProcess(system) {
  const name = (drafts[system.id] || '').trim()
  if (!name) return
  const res = await store.addProcess(system.id, name)
  if (res.success) drafts[system.id] = ''
}

onMounted(() => store.refresh())
</script>
