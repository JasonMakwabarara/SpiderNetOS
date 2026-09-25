<template>
  <article
    class="sn-card p-0 overflow-hidden"
    :aria-labelledby="`batch-title-${group.key}`"
    :data-testid="`approval-batch-${group.key}`"
    @keydown="onKeydown"
  >
    <header class="px-4 py-3 border-b flex items-start justify-between gap-3" style="border-color: var(--border);">
      <div class="min-w-0">
        <div class="text-[10px] tracking-widest uppercase font-semibold" style="color: var(--text-muted);">
          Batch · {{ group.items.length }} draft{{ group.items.length === 1 ? '' : 's' }}
        </div>
        <h3 :id="`batch-title-${group.key}`" class="font-heading text-[15px] font-semibold mt-0.5" style="color: var(--text-primary);">
          {{ group.skill_name || group.skill_slug || 'Agent drafts' }}
        </h3>
        <p class="text-[11px] mt-0.5 mono" style="color: var(--text-muted);">
          <RouterLink v-if="group.skill_slug" :to="`/skills/${group.skill_slug}`" class="hover:underline" :data-testid="`batch-skill-${group.key}`">{{ group.skill_slug }}</RouterLink>
          <span v-if="group.run_id"> · <RouterLink :to="`/agents/runs/${group.run_id}`" class="hover:underline" :data-testid="`batch-run-${group.key}`">{{ group.run_id }}</RouterLink></span>
        </p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <span class="sn-kbd hidden md:inline" title="j/k move · a approve · r reject · e edit">j k · a · r · e</span>
        <button
          type="button"
          class="sn-btn"
          style="background: rgba(34,211,155,0.16); color: var(--success); border-color: rgba(34,211,155,0.40);"
          :disabled="busy || !pending.length"
          :data-testid="`batch-approve-all-${group.key}`"
          @click="emit('approve-all', pending)"
        >{{ busy ? 'Working…' : `Approve all ${pending.length}` }}</button>
      </div>
    </header>

    <ol
      ref="listRef"
      role="list"
      :aria-label="`${group.skill_name || 'Batch'} drafts`"
      class="divide-y"
      style="border-color: var(--divider);"
    >
      <li
        v-for="(item, idx) in group.items"
        :key="item.id"
        :ref="(el) => (rows[idx] = el)"
        tabindex="0"
        class="px-4 py-3 outline-none transition-colors"
        :style="idx === focusIndex ? 'background: var(--accent-weak); border-left: 2px solid var(--accent);' : 'border-left: 2px solid transparent;'"
        :data-testid="`batch-item-${item.id}`"
        :data-status="item.status"
        @focus="focusIndex = idx"
        @click="focusIndex = idx"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2 mb-1 flex-wrap">
              <span class="sn-pill text-[10px]">{{ stepLabel(item) }}</span>
              <span class="sn-pill text-[10px]" :class="statusPill(item.status)">{{ item.status }}</span>
              <span class="text-sm truncate" style="color: var(--text-primary);">{{ item.title || item.resource_name }}</span>
            </div>

            <template v-if="editing === item.id">
              <input
                v-if="artifactOf(item).subject !== undefined"
                v-model="draft.subject"
                type="text"
                class="sn-input text-sm mb-2"
                aria-label="Subject"
                :data-testid="`batch-edit-subject-${item.id}`"
              />
              <textarea
                v-model="draft.body"
                class="sn-textarea"
                rows="6"
                aria-label="Body"
                :data-testid="`batch-edit-body-${item.id}`"
              ></textarea>
              <div class="flex items-center gap-2 mt-2">
                <button type="button" class="sn-btn-primary text-xs" :disabled="busy" :data-testid="`batch-edit-save-${item.id}`" @click="saveEdit(item)">Save edit</button>
                <button type="button" class="sn-btn-secondary text-xs" :data-testid="`batch-edit-cancel-${item.id}`" @click="editing = null">Cancel</button>
              </div>
            </template>
            <template v-else>
              <p v-if="artifactOf(item).subject" class="text-xs font-medium" style="color: var(--text-secondary);" :data-testid="`batch-subject-${item.id}`">
                {{ artifactOf(item).subject }}
              </p>
              <p class="text-sm mt-1 whitespace-pre-line" style="color: var(--text-primary);" :data-testid="`batch-body-${item.id}`">
                {{ artifactOf(item).body || item.summary || '—' }}
              </p>
              <p v-if="changedOf(item)" class="text-[11px] mt-1.5" style="color: var(--amber);" :data-testid="`batch-changed-${item.id}`">
                Changed vs last approved: {{ changedOf(item) }}
              </p>
            </template>
          </div>

          <div v-if="item.status === 'pending'" class="flex items-center gap-1.5 shrink-0">
            <button type="button" class="sn-btn-secondary text-xs" style="padding: 0.3rem 0.6rem;" :disabled="busy" :data-testid="`batch-edit-${item.id}`" @click="startEdit(item)">Edit</button>
            <button type="button" class="sn-btn text-xs" style="padding: 0.3rem 0.6rem; border-color: rgba(255,90,122,0.40); color: var(--danger);" :disabled="busy" :data-testid="`batch-reject-${item.id}`" @click="emit('reject', item)">Reject</button>
            <button type="button" class="sn-btn text-xs" style="padding: 0.3rem 0.6rem; background: rgba(34,211,155,0.16); color: var(--success); border-color: rgba(34,211,155,0.40);" :disabled="busy" :data-testid="`batch-approve-${item.id}`" @click="emit('approve', item)">Approve</button>
          </div>
        </div>
      </li>
    </ol>
  </article>
</template>

<script setup>
/**
 * ApprovalBatchCard — one card for every pending `agent_artifact`
 * approval that came from the same skill + run (D8 #8). Shows the
 * artifact preview (subject / body per step), what changed vs the last
 * approved version, inline edit before approve, approve-all / reject-one,
 * and a keyboard path: j/k move, a approve, r reject, e edit.
 *
 * group: { key, skill_slug, skill_name, run_id, items: [approval] }
 * Emits approve(item), reject(item), approve-all(items), edit(item, content).
 */
import { computed, reactive, ref } from 'vue'
import { parseContext } from '../../utils/format.js'

const props = defineProps({
  group: { type: Object, required: true },
  busy:  { type: Boolean, default: false },
})

const emit = defineEmits(['approve', 'reject', 'approve-all', 'edit'])

const listRef = ref(null)
const rows = ref([])
const focusIndex = ref(0)
const editing = ref(null)
const draft = reactive({ subject: '', body: '' })

const pending = computed(() => props.group.items.filter((i) => i.status === 'pending'))

function artifactOf(item) {
  const ctx = parseContext(item.context)
  return ctx.artifact || ctx.draft || {}
}
function changedOf(item) {
  return parseContext(item.context).changed_vs_last_approved || ''
}
function stepLabel(item) {
  const a = artifactOf(item)
  if (a.step) return `Step ${a.step}${a.variant ? ` · ${String(a.variant).toUpperCase()}` : ''}`
  return a.kind ? String(a.kind).replace(/_/g, ' ') : 'draft'
}
function statusPill(s) {
  if (s === 'approved') return 'sn-pill-success'
  if (s === 'rejected') return 'sn-pill-danger'
  return 'sn-pill-warn'
}

function startEdit(item) {
  const a = artifactOf(item)
  draft.subject = a.subject ?? ''
  draft.body = a.body ?? ''
  editing.value = item.id
}
function saveEdit(item) {
  const a = artifactOf(item)
  const content = { body: draft.body }
  if (a.subject !== undefined) content.subject = draft.subject
  emit('edit', item, content)
  editing.value = null
}

function focusRow(idx) {
  const n = props.group.items.length
  if (!n) return
  focusIndex.value = ((idx % n) + n) % n
  rows.value[focusIndex.value]?.focus?.()
}

function onKeydown(event) {
  const tag = event.target?.tagName
  if (tag === 'TEXTAREA' || tag === 'INPUT' || tag === 'SELECT') return
  const item = props.group.items[focusIndex.value]
  switch (event.key) {
    case 'j': case 'ArrowDown': event.preventDefault(); focusRow(focusIndex.value + 1); break
    case 'k': case 'ArrowUp':   event.preventDefault(); focusRow(focusIndex.value - 1); break
    case 'a': if (item?.status === 'pending' && !props.busy) { event.preventDefault(); emit('approve', item) } break
    case 'r': if (item?.status === 'pending' && !props.busy) { event.preventDefault(); emit('reject', item) } break
    case 'e': if (item?.status === 'pending') { event.preventDefault(); startEdit(item) } break
    default:
  }
}
</script>
