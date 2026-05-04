<template>
  <div class="p-6 space-y-4">
    <header>
      <h1 class="text-2xl font-bold">Feature flags</h1>
      <p class="text-sm text-gray-500">
        Flag registry. Per-tenant overrides supported. All writes require step-up auth and are audit-logged.
      </p>
    </header>

    <StepUpGuard reason="Flag writes change platform behaviour. Verify it is you.">
      <div class="flex items-center gap-2 mb-3">
        <input
          v-model="query"
          type="text"
          placeholder="Search flags…"
          class="px-3 py-2 border rounded text-sm w-72"
          aria-label="Search flags"
        />
        <button class="px-3 py-2 border rounded text-sm" @click="load">Refresh</button>
      </div>

      <DataTable :columns="columns" :rows="filteredFlags" :loading="loading" caption="Feature flags">
        <template #cell-value="{ row }">
          <code class="font-mono text-xs bg-gray-100 px-2 py-0.5 rounded">{{ row.value }}</code>
        </template>
        <template #actions="{ row }">
          <button class="text-xs text-indigo-600 mr-2" @click="openEditor(row)">Edit</button>
          <button class="text-xs text-gray-500" @click="openHistory(row)">History</button>
        </template>
        <template #empty>
          <EmptyState title="No flags registered" description="Add flags via config/features.php." />
        </template>
      </DataTable>
    </StepUpGuard>

    <!-- Edit dialog -->
    <ConfirmDialog
      v-model="editorOpen"
      title="Update flag"
      confirmLabel="Write value"
      @confirm="commitEdit"
    >
      <div v-if="editTarget">
        <p class="text-sm mb-2">
          <code class="bg-gray-100 px-1 rounded">{{ editTarget.name }}</code>
        </p>
        <label class="block text-xs font-medium mb-1" for="flag-val">New value</label>
        <input
          id="flag-val"
          v-model="editValue"
          class="w-full px-3 py-2 border rounded text-sm font-mono"
          autocomplete="off"
        />
        <p class="text-xs text-gray-500 mt-2">
          Typical values: <code>on</code>, <code>off</code>, <code>fallback</code>, or a scalar.
        </p>
      </div>
    </ConfirmDialog>

    <JsonDrawer v-model="historyOpen" :payload="historyTarget?.history || []" :title="`History: ${historyTarget?.name || ''}`" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import DataTable      from '../../components/data/DataTable.vue'
import EmptyState     from '../../components/data/EmptyState.vue'
import JsonDrawer     from '../../components/data/JsonDrawer.vue'
import ConfirmDialog  from '../../components/feedback/ConfirmDialog.vue'
import StepUpGuard    from '../../components/security/StepUpGuard.vue'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

const loading = ref(false)
const flags   = ref([])
const query   = ref('')

const columns = [
  { key: 'name',   label: 'Name',   sortable: true },
  { key: 'value',  label: 'Value' },
  { key: 'scope',  label: 'Scope' },
  { key: 'source', label: 'Source' },
]

const filteredFlags = computed(() => {
  if (!query.value) return flags.value
  const q = query.value.toLowerCase()
  return flags.value.filter((f) => f.name.toLowerCase().includes(q))
})

const editorOpen = ref(false)
const editTarget = ref(null)
const editValue  = ref('')

function openEditor(row) {
  editTarget.value = row
  editValue.value  = String(row.value)
  editorOpen.value = true
}

async function commitEdit() {
  if (!editTarget.value) return
  try {
    await axios.put(`${API_URL}/api/platform/feature-flags/${encodeURIComponent(editTarget.value.name)}`, {
      value: editValue.value,
    })
    await load()
  } catch {
    /* server-authoritative: if refused (capability/step-up), surface via toast layer */
  }
}

const historyOpen   = ref(false)
const historyTarget = ref(null)
function openHistory(row) {
  historyTarget.value = row
  historyOpen.value   = true
}

async function load() {
  loading.value = true
  try {
    const { data } = await axios.get(`${API_URL}/api/platform/feature-flags`)
    flags.value = data?.flags || []
  } catch {
    flags.value = []
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
