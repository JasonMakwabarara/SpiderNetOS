<template>
  <div class="px-6 py-7 max-w-6xl mx-auto">
    <div class="mb-6">
      <div class="sn-eyebrow">Operate · Operating Model</div>
      <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">The Five A's</h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">
        Alignment, Awareness, Accountability, Activity, Assets — how this business runs itself.
      </p>
    </div>

    <nav class="flex items-center gap-1 mb-5 flex-wrap">
      <button
        v-for="t in tabs" :key="t.id"
        class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
        :style="tab === t.id
          ? 'background: var(--accent-weak); color: var(--accent); border: 1px solid rgba(0,229,200,0.30);'
          : 'background: var(--bg-elevated); color: var(--text-muted); border: 1px solid var(--border);'"
        @click="tab = t.id"
      >
        {{ t.label }}
      </button>
    </nav>

    <div v-if="store.loading" class="text-sm" style="color: var(--text-secondary);">Loading…</div>

    <template v-else>
      <!-- Alignment -->
      <div v-if="tab === 'alignment'" class="sn-card p-5 space-y-4">
        <div>
          <label class="text-xs font-medium block mb-1" style="color: var(--text-secondary);">Origin story</label>
          <textarea v-model="alignmentForm.origin_story" rows="2" class="sn-textarea"></textarea>
        </div>
        <div>
          <label class="text-xs font-medium block mb-1" style="color: var(--text-secondary);">Mission — what we must get right every day</label>
          <textarea v-model="alignmentForm.mission" rows="2" class="sn-textarea"></textarea>
        </div>
        <div>
          <label class="text-xs font-medium block mb-1" style="color: var(--text-secondary);">Vision — where we're going</label>
          <textarea v-model="alignmentForm.vision" rows="2" class="sn-textarea"></textarea>
        </div>
        <div class="grid md:grid-cols-3 gap-3">
          <div v-for="cycle in ['ninety_day_targets', 'one_year_targets', 'three_year_targets']" :key="cycle">
            <label class="text-xs font-medium block mb-1" style="color: var(--text-secondary);">
              {{ cycle === 'ninety_day_targets' ? '90-day target' : cycle === 'one_year_targets' ? '1-year target' : '3-year target' }}
            </label>
            <input
              class="sn-input w-full"
              :value="alignmentForm[cycle]?.[0]?.metric || ''"
              @input="setTarget(cycle, $event.target.value)"
              placeholder="e.g. 30 new customers"
            />
          </div>
        </div>
        <button class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold" :disabled="saving" @click="saveAlignment">
          {{ saving ? 'Saving…' : 'Save alignment' }}
        </button>
      </div>

      <!-- Org chart -->
      <div v-else-if="tab === 'org-chart'" class="sn-card p-5">
        <div class="text-center mb-6">
          <div class="inline-block px-4 py-2 rounded-lg" style="background: var(--accent-weak); color: var(--accent);">
            {{ store.orgChart?.key_person_of_influence?.name || 'Owner' }} · Key Person of Influence
          </div>
          <div class="my-2 text-xs" style="color: var(--text-muted);">↓</div>
          <div class="inline-block px-4 py-2 rounded-lg" style="background: var(--bg-elevated); color: var(--text-primary); border: 1px solid var(--border);">
            Atlas · Central Brain
          </div>
        </div>
        <div class="grid md:grid-cols-4 gap-3">
          <div v-for="r in store.orgChart?.roles || []" :key="r.agent_slug" class="p-3 rounded-lg" style="background: var(--bg-elevated); border: 1px solid var(--border);">
            <p class="text-sm font-medium" style="color: var(--text-primary);">{{ r.role.replace(/_/g, ' ') }}</p>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">{{ r.agent_name }}</p>
            <span class="text-xs mt-2 inline-block px-2 py-0.5 rounded-full" :style="r.status === 'active' ? 'background: var(--accent-weak); color: var(--accent);' : 'background: var(--bg-card); color: var(--text-muted);'">
              {{ r.status }}
            </span>
          </div>
          <p v-if="!store.orgChart?.roles?.length" class="text-sm" style="color: var(--text-muted);">Install a feature pack to populate the org chart.</p>
        </div>
      </div>

      <!-- Scoreboard -->
      <div v-else-if="tab === 'scoreboard'" class="sn-card p-5">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left" style="color: var(--text-muted);">
              <th class="pb-2 font-medium">Metric</th>
              <th class="pb-2 font-medium">Baseline</th>
              <th class="pb-2 font-medium">Goal</th>
              <th class="pb-2 font-medium">Actual</th>
            </tr>
          </thead>
          <tbody class="divide-y" style="border-color: var(--border);">
            <tr v-for="m in store.scoreboard" :key="m.metric + m.pack_id">
              <td class="py-2" style="color: var(--text-primary);">{{ m.metric.replace(/_/g, ' ') }}</td>
              <td class="py-2" style="color: var(--text-muted);">{{ m.baseline ?? '—' }}</td>
              <td class="py-2" style="color: var(--text-muted);">{{ m.goal ?? '—' }}</td>
              <td class="py-2 font-medium" style="color: var(--accent);">{{ m.actual ?? 'no data yet' }}</td>
            </tr>
          </tbody>
        </table>
        <p v-if="!store.scoreboard.length" class="text-sm mt-2" style="color: var(--text-muted);">No targets yet — install a feature pack.</p>
      </div>

      <!-- Awareness -->
      <div v-else-if="tab === 'awareness'" class="sn-card p-5">
        <div class="flex gap-2 mb-4">
          <input v-model="newAwareness" class="sn-input flex-1" placeholder="Raise something before it becomes a problem…" @keydown.enter="submitAwareness" />
          <button class="sn-btn px-4 py-2 rounded-lg text-sm font-semibold" @click="submitAwareness">Raise</button>
        </div>
        <ul class="space-y-2">
          <li v-for="i in store.awareness" :key="i.id" class="p-3 rounded-lg flex items-start justify-between gap-3" style="background: var(--bg-elevated); border: 1px solid var(--border);">
            <div>
              <p class="text-sm" style="color: var(--text-primary);">{{ i.title }}</p>
              <p class="text-xs mt-0.5" style="color: var(--text-muted);">{{ i.source }} · {{ i.severity }} · {{ i.status }}</p>
            </div>
            <button v-if="i.status !== 'resolved'" class="text-xs px-2 py-1 rounded" style="border: 1px solid var(--border); color: var(--text-secondary);" @click="store.resolveAwareness(i.id)">
              Resolve
            </button>
          </li>
          <p v-if="!store.awareness.length" class="text-sm" style="color: var(--text-muted);">All clear — nothing raised yet.</p>
        </ul>
      </div>

      <!-- Assets -->
      <div v-else-if="tab === 'assets'" class="sn-card p-5 space-y-4">
        <div v-for="(items, quarter) in store.assets" :key="quarter">
          <h3 class="text-xs font-semibold uppercase tracking-wider mb-2" style="color: var(--text-muted);">{{ quarter || 'Unassigned' }}</h3>
          <ul class="space-y-1.5">
            <li v-for="a in items" :key="a.id" class="text-sm flex justify-between" style="color: var(--text-primary);">
              <span>{{ a.name }}</span>
              <span class="text-xs" style="color: var(--text-muted);">{{ a.type }} · v{{ a.version }}</span>
            </li>
          </ul>
        </div>
        <p v-if="!Object.keys(store.assets).length" class="text-sm" style="color: var(--text-muted);">
          Assets register automatically — e.g. an approved sales script from the Lead-to-Sale Funnel bundle.
        </p>
      </div>
    </template>
    <p v-if="store.error" class="text-xs mt-4" style="color: #FF6B6B;">{{ store.error }}</p>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useOperatingStore } from '../../stores/operating.js'

const store = useOperatingStore()
const tab = ref('alignment')
const saving = ref(false)
const newAwareness = ref('')
const alignmentForm = reactive({ origin_story: '', mission: '', vision: '', ninety_day_targets: [], one_year_targets: [], three_year_targets: [] })

const tabs = [
  { id: 'alignment', label: 'Alignment' },
  { id: 'org-chart', label: 'Org chart' },
  { id: 'scoreboard', label: 'Scoreboard' },
  { id: 'awareness', label: 'Awareness' },
  { id: 'assets', label: 'Assets' },
]

onMounted(async () => {
  await store.fetchAll()
  if (store.alignment) {
    Object.assign(alignmentForm, {
      origin_story: store.alignment.origin_story || '',
      mission: store.alignment.mission || '',
      vision: store.alignment.vision || '',
      ninety_day_targets: store.alignment.ninety_day_targets || [],
      one_year_targets: store.alignment.one_year_targets || [],
      three_year_targets: store.alignment.three_year_targets || [],
    })
  }
})

function setTarget(cycle, value) {
  alignmentForm[cycle] = value ? [{ metric: value }] : []
}

async function saveAlignment() {
  saving.value = true
  await store.saveAlignment({ ...alignmentForm })
  saving.value = false
}

async function submitAwareness() {
  if (!newAwareness.value.trim()) return
  await store.raiseAwareness({ title: newAwareness.value.trim() })
  newAwareness.value = ''
}
</script>

<style scoped>
.sn-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; }
.sn-btn { background: linear-gradient(135deg, #00E5C8, #087D6E); color: #05070A; }
.sn-input {
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  color: var(--text-primary);
  border-radius: 8px;
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
}
</style>
