<template>
  <div class="traces p-6 space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Traces</h1>
        <p class="text-sm text-gray-500 mt-1">
          DAG execution history and observability
        </p>
      </div>
      <button
        @click="refreshTraces"
        class="p-2 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition-colors"
        title="Refresh"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
      </button>
    </div>

    <!-- Filters -->
    <div class="flex items-center space-x-4">
      <div class="relative">
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Search traces..."
          class="pl-9 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
        />
        <svg class="absolute left-3 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
      </div>

      <select
        v-model="statusFilter"
        class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
      >
        <option value="">All Status</option>
        <option value="completed">Completed</option>
        <option value="running">Running</option>
        <option value="failed">Failed</option>
      </select>

      <input
        v-model="dateFrom"
        type="date"
        class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
      />
      <span class="text-gray-400 text-sm">to</span>
      <input
        v-model="dateTo"
        type="date"
        class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
      />
    </div>

    <!-- Summary Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="bg-white rounded-lg border border-gray-200 p-4">
        <p class="text-sm text-gray-500">Total Traces</p>
        <p class="text-xl font-semibold text-gray-900">{{ tracesStore.traces.length }}</p>
      </div>
      <div class="bg-white rounded-lg border border-gray-200 p-4">
        <p class="text-sm text-gray-500">Completed</p>
        <p class="text-xl font-semibold text-green-600">{{ tracesStore.completedTraces.length }}</p>
      </div>
      <div class="bg-white rounded-lg border border-gray-200 p-4">
        <p class="text-sm text-gray-500">Failed</p>
        <p class="text-xl font-semibold text-red-600">{{ tracesStore.failedTraces.length }}</p>
      </div>
      <div class="bg-white rounded-lg border border-gray-200 p-4">
        <p class="text-sm text-gray-500">Total Cost</p>
        <p class="text-xl font-semibold text-gray-900">${{ tracesStore.totalCost.toFixed(4) }}</p>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="tracesStore.isLoading" class="text-center py-12">
      <div class="animate-spin w-8 h-8 border-4 border-indigo-600 border-t-transparent rounded-full mx-auto" />
      <p class="mt-4 text-gray-500">Loading traces...</p>
    </div>

    <!-- Empty State -->
    <div v-else-if="filteredTraces.length === 0" class="text-center py-12 bg-white rounded-lg border border-gray-200">
      <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
      </svg>
      <h3 class="text-lg font-medium text-gray-900">No traces found</h3>
      <p class="text-gray-500 mt-1">Execute a flow to see its trace here</p>
    </div>

    <!-- Traces List -->
    <div v-else class="space-y-3">
      <div
        v-for="trace in filteredTraces"
        :key="trace.id"
        class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden"
      >
        <!-- Trace Header (always visible) -->
        <div
          @click="toggleTrace(trace.id)"
          class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-gray-50 transition-colors"
        >
          <div class="flex items-center space-x-4">
            <!-- Status indicator -->
            <span
              class="w-3 h-3 rounded-full flex-shrink-0"
              :class="{
                'bg-green-500': trace.status === 'completed',
                'bg-blue-500 animate-pulse': trace.status === 'running',
                'bg-red-500': trace.status === 'failed',
                'bg-gray-300': trace.status === 'pending'
              }"
            />

            <div>
              <h4 class="text-sm font-medium text-gray-900">
                {{ trace.flow_name || trace.name || `Trace #${trace.id}` }}
              </h4>
              <p class="text-xs text-gray-500 mt-0.5">
                {{ formatDateTime(trace.started_at) }}
              </p>
            </div>
          </div>

          <div class="flex items-center space-x-6">
            <!-- Duration -->
            <div class="text-right">
              <p class="text-sm font-medium text-gray-900">{{ formatDuration(trace.duration_ms) }}</p>
              <p class="text-xs text-gray-500">duration</p>
            </div>

            <!-- Cost -->
            <div class="text-right">
              <p class="text-sm font-medium text-gray-900">${{ (trace.cost || 0).toFixed(4) }}</p>
              <p class="text-xs text-gray-500">cost</p>
            </div>

            <!-- Status badge -->
            <span
              class="px-2.5 py-1 text-xs font-medium rounded-full"
              :class="traceStatusClass(trace.status)"
            >
              {{ trace.status }}
            </span>

            <!-- Expand icon -->
            <svg
              class="w-5 h-5 text-gray-400 transition-transform"
              :class="{ 'rotate-180': expandedTraces.has(trace.id) }"
              fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
          </div>
        </div>

        <!-- Expanded Trace Detail -->
        <div v-if="expandedTraces.has(trace.id)" class="border-t border-gray-200 p-5">
          <!-- Node states -->
          <div v-if="trace.nodes && trace.nodes.length > 0" class="mb-4">
            <h5 class="text-sm font-medium text-gray-700 mb-2">Node States</h5>
            <div class="space-y-2">
              <div
                v-for="node in trace.nodes"
                :key="node.id"
                class="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2"
              >
                <div class="flex items-center space-x-3">
                  <span
                    class="w-2 h-2 rounded-full"
                    :class="{
                      'bg-green-500': node.status === 'completed',
                      'bg-blue-500': node.status === 'running',
                      'bg-red-500': node.status === 'failed',
                      'bg-gray-300': node.status === 'pending'
                    }"
                  />
                  <span class="text-sm text-gray-700">{{ node.name || node.id }}</span>
                  <span class="text-xs text-gray-400">{{ node.type }}</span>
                </div>
                <span class="text-xs text-gray-500">{{ formatDuration(node.duration_ms) }}</span>
              </div>
            </div>
          </div>

          <!-- Execution Timeline -->
          <div v-if="trace.events && trace.events.length > 0" class="mb-4">
            <h5 class="text-sm font-medium text-gray-700 mb-2">Execution Timeline</h5>
            <div class="relative pl-6 space-y-3">
              <div class="absolute left-2 top-1 bottom-1 w-0.5 bg-gray-200" />
              <div
                v-for="(event, idx) in trace.events.slice(0, 10)"
                :key="idx"
                class="relative flex items-start space-x-3"
              >
                <div
                  class="absolute left-[-16px] top-1.5 w-2 h-2 rounded-full z-10"
                  :class="{
                    'bg-green-500': event.type === 'completed',
                    'bg-blue-500': event.type === 'started',
                    'bg-red-500': event.type === 'error',
                    'bg-gray-400': true
                  }"
                />
                <div>
                  <p class="text-sm text-gray-700">{{ event.message || event.type }}</p>
                  <p class="text-xs text-gray-400">{{ formatDateTime(event.timestamp) }}</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Replay Button -->
          <div class="flex items-center space-x-3 pt-3 border-t border-gray-100">
            <button
              @click="startReplay(trace)"
              class="px-4 py-2 text-sm font-medium bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors flex items-center space-x-2"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span>Replay Execution</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Replay Modal -->
    <div v-if="replayTrace" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[80vh] flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
          <h2 class="text-lg font-semibold text-gray-900">
            Replay: {{ replayTrace.flow_name || replayTrace.name }}
          </h2>
          <button @click="stopReplay" class="p-1 text-gray-400 hover:text-gray-600 rounded">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
          <!-- Replay progress -->
          <div class="mb-4">
            <div class="flex items-center justify-between mb-1">
              <span class="text-sm text-gray-600">
                Event {{ tracesStore.replayIndex + 1 }} of {{ tracesStore.traceEvents.length }}
              </span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2">
              <div
                class="bg-indigo-600 h-2 rounded-full transition-all duration-300"
                :style="{ width: replayProgress + '%' }"
              />
            </div>
          </div>

          <!-- Current replay event -->
          <div v-if="currentReplayEvent" class="bg-gray-50 rounded-lg p-4">
            <div class="flex items-center space-x-2 mb-2">
              <span class="text-xs font-medium text-indigo-600 uppercase">{{ currentReplayEvent.type }}</span>
              <span class="text-xs text-gray-400">{{ formatDateTime(currentReplayEvent.timestamp) }}</span>
            </div>
            <p class="text-sm text-gray-700">{{ currentReplayEvent.message || JSON.stringify(currentReplayEvent.data, null, 2) }}</p>
          </div>
        </div>

        <div class="flex items-center justify-between px-6 py-4 border-t border-gray-200">
          <button
            @click="stopReplay"
            class="px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-lg transition-colors"
          >
            Close
          </button>
          <button
            @click="stepReplayForward"
            :disabled="!tracesStore.isReplaying"
            class="px-4 py-2 text-sm font-medium bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors"
          >
            {{ tracesStore.replayIndex >= tracesStore.traceEvents.length - 1 ? 'Done' : 'Next Step' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, reactive, onMounted } from 'vue'
import { useTracesStore } from '../stores/traces.js'

const tracesStore = useTracesStore()

const searchQuery = ref('')
const statusFilter = ref('')
const dateFrom = ref('')
const dateTo = ref('')
const expandedTraces = reactive(new Set())
const replayTrace = ref(null)

const filteredTraces = computed(() => {
  let result = tracesStore.traces

  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(t =>
      (t.flow_name || t.name || '').toLowerCase().includes(query) ||
      String(t.id).includes(query)
    )
  }

  if (statusFilter.value) {
    result = result.filter(t => t.status === statusFilter.value)
  }

  if (dateFrom.value) {
    const from = new Date(dateFrom.value)
    result = result.filter(t => new Date(t.started_at) >= from)
  }

  if (dateTo.value) {
    const to = new Date(dateTo.value)
    to.setHours(23, 59, 59, 999)
    result = result.filter(t => new Date(t.started_at) <= to)
  }

  return result
})

const replayProgress = computed(() => {
  if (tracesStore.traceEvents.length === 0) return 0
  return Math.round(((tracesStore.replayIndex + 1) / tracesStore.traceEvents.length) * 100)
})

const currentReplayEvent = computed(() => {
  if (tracesStore.replayIndex < 0 || tracesStore.replayIndex >= tracesStore.traceEvents.length) return null
  return tracesStore.traceEvents[tracesStore.replayIndex]
})

onMounted(() => {
  tracesStore.fetchTraces()
})

function refreshTraces() {
  tracesStore.fetchTraces()
}

function toggleTrace(traceId) {
  if (expandedTraces.has(traceId)) {
    expandedTraces.delete(traceId)
  } else {
    expandedTraces.add(traceId)
    // Fetch detailed trace data
    tracesStore.fetchTrace(traceId)
  }
}

async function startReplay(trace) {
  replayTrace.value = trace
  await tracesStore.fetchTrace(trace.id)
  tracesStore.startReplay()
}

function stepReplayForward() {
  tracesStore.stepReplay()
  if (!tracesStore.isReplaying) {
    // Replay finished
  }
}

function stopReplay() {
  tracesStore.stopReplay()
  replayTrace.value = null
}

function traceStatusClass(status) {
  const map = {
    completed: 'bg-green-100 text-green-700',
    running: 'bg-blue-100 text-blue-700',
    failed: 'bg-red-100 text-red-700',
    pending: 'bg-gray-100 text-gray-700'
  }
  return map[status] || 'bg-gray-100 text-gray-700'
}

function formatDuration(ms) {
  if (!ms && ms !== 0) return '-'
  if (ms < 1000) return `${ms}ms`
  const seconds = Math.floor(ms / 1000)
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  const remainingSeconds = seconds % 60
  return `${minutes}m ${remainingSeconds}s`
}

function formatDateTime(timestamp) {
  if (!timestamp) return ''
  return new Date(timestamp).toLocaleString()
}
</script>
