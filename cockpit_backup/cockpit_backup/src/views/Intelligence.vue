<template>
  <div class="intelligence p-6 space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Intelligence</h1>
        <p class="text-sm text-gray-500 mt-1">
          Daily brief, anomaly detection, and system learning
        </p>
      </div>
      <div class="flex items-center space-x-3">
        <span
          v-if="intelStore.anomalyCount > 0"
          class="px-3 py-1 text-sm font-medium bg-red-100 text-red-700 rounded-full"
        >
          {{ intelStore.anomalyCount }} unresolved
        </span>
        <button
          @click="refreshAll"
          class="p-2 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition-colors"
          title="Refresh"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
        </button>
      </div>
    </div>

    <!-- System Health Overview -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="flex items-center space-x-3">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center" :class="healthScoreColor">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
            </svg>
          </div>
          <div>
            <p class="text-sm text-gray-500">Health Score</p>
            <p class="text-xl font-semibold text-gray-900">
              {{ intelStore.healthScore !== null ? intelStore.healthScore + '%' : '--' }}
            </p>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="flex items-center space-x-3">
          <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center">
            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z" />
            </svg>
          </div>
          <div>
            <p class="text-sm text-gray-500">Critical Alerts</p>
            <p class="text-xl font-semibold text-red-600">{{ intelStore.criticalAnomalies.length }}</p>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="flex items-center space-x-3">
          <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center">
            <svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
          </div>
          <div>
            <p class="text-sm text-gray-500">Total Anomalies</p>
            <p class="text-xl font-semibold text-gray-900">{{ intelStore.anomalies.length }}</p>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="flex items-center space-x-3">
          <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center">
            <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
            </svg>
          </div>
          <div>
            <p class="text-sm text-gray-500">Learnings Recorded</p>
            <p class="text-xl font-semibold text-gray-900">{{ intelStore.learningLog.length }}</p>
          </div>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Daily Brief (2/3 width) -->
      <div class="lg:col-span-2 space-y-6">
        <!-- Daily Brief -->
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm">
          <div class="px-5 py-4 border-b border-gray-200">
            <h3 class="font-semibold text-gray-900">Daily Brief</h3>
            <p class="text-xs text-gray-500 mt-0.5">OODA Intelligence Summary</p>
          </div>

          <div v-if="intelStore.isLoading" class="p-6 text-center">
            <div class="animate-spin w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full mx-auto" />
          </div>

          <div v-else-if="intelStore.dailyBrief" class="divide-y divide-gray-100">
            <!-- Observe -->
            <div class="p-5">
              <div class="flex items-center space-x-2 mb-3">
                <div class="w-7 h-7 bg-blue-100 rounded-full flex items-center justify-center">
                  <span class="text-xs font-bold text-blue-700">O</span>
                </div>
                <h4 class="text-sm font-semibold text-gray-900">Observe</h4>
              </div>
              <div class="pl-9 text-sm text-gray-600 space-y-2">
                <p v-for="(item, i) in (intelStore.dailyBrief.observe || [])" :key="'o'+i">{{ item }}</p>
                <p v-if="!(intelStore.dailyBrief.observe || []).length" class="text-gray-400 italic">No observations recorded</p>
              </div>
            </div>

            <!-- Orient -->
            <div class="p-5">
              <div class="flex items-center space-x-2 mb-3">
                <div class="w-7 h-7 bg-purple-100 rounded-full flex items-center justify-center">
                  <span class="text-xs font-bold text-purple-700">O</span>
                </div>
                <h4 class="text-sm font-semibold text-gray-900">Orient</h4>
              </div>
              <div class="pl-9 text-sm text-gray-600 space-y-2">
                <p v-for="(item, i) in (intelStore.dailyBrief.orient || [])" :key="'or'+i">{{ item }}</p>
                <p v-if="!(intelStore.dailyBrief.orient || []).length" class="text-gray-400 italic">No analysis available</p>
              </div>
            </div>

            <!-- Decide -->
            <div class="p-5">
              <div class="flex items-center space-x-2 mb-3">
                <div class="w-7 h-7 bg-orange-100 rounded-full flex items-center justify-center">
                  <span class="text-xs font-bold text-orange-700">D</span>
                </div>
                <h4 class="text-sm font-semibold text-gray-900">Decide</h4>
              </div>
              <div class="pl-9 text-sm text-gray-600 space-y-2">
                <p v-for="(item, i) in (intelStore.dailyBrief.decide || [])" :key="'d'+i">{{ item }}</p>
                <p v-if="!(intelStore.dailyBrief.decide || []).length" class="text-gray-400 italic">No decisions pending</p>
              </div>
            </div>

            <!-- Act -->
            <div class="p-5">
              <div class="flex items-center space-x-2 mb-3">
                <div class="w-7 h-7 bg-green-100 rounded-full flex items-center justify-center">
                  <span class="text-xs font-bold text-green-700">A</span>
                </div>
                <h4 class="text-sm font-semibold text-gray-900">Act</h4>
              </div>
              <div class="pl-9 text-sm text-gray-600 space-y-2">
                <p v-for="(item, i) in (intelStore.dailyBrief.act || [])" :key="'a'+i">{{ item }}</p>
                <p v-if="!(intelStore.dailyBrief.act || []).length" class="text-gray-400 italic">No actions recommended</p>
              </div>
            </div>
          </div>

          <div v-else class="p-6 text-center text-gray-400">
            <p>No daily brief available yet</p>
          </div>
        </div>

        <!-- Learning Log Section -->
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm">
          <div class="px-5 py-4 border-b border-gray-200">
            <h3 class="font-semibold text-gray-900">Learning Log</h3>
            <p class="text-xs text-gray-500 mt-0.5">Recorded outcomes and system adaptations</p>
          </div>

          <div v-if="intelStore.learningLog.length === 0" class="p-6 text-center text-gray-400">
            <p>No learning entries recorded yet</p>
          </div>

          <div v-else class="divide-y divide-gray-100">
            <div
              v-for="entry in intelStore.learningLog.slice(0, 8)"
              :key="entry.id"
              class="px-5 py-3 hover:bg-gray-50 transition-colors"
            >
              <div class="flex items-start justify-between">
                <div class="min-w-0 flex-1">
                  <p class="text-sm text-gray-900">{{ entry.summary || entry.description }}</p>
                  <div class="flex items-center space-x-3 mt-1">
                    <span v-if="entry.category" class="text-xs px-2 py-0.5 bg-gray-100 text-gray-600 rounded-full">
                      {{ entry.category }}
                    </span>
                    <span v-if="entry.outcome" class="text-xs" :class="entry.outcome === 'positive' ? 'text-green-600' : 'text-red-600'">
                      {{ entry.outcome }}
                    </span>
                    <span class="text-xs text-gray-400">{{ formatTime(entry.created_at) }}</span>
                  </div>
                </div>
                <span
                  v-if="entry.confidence"
                  class="flex-shrink-0 text-xs font-medium px-2 py-0.5 rounded-full ml-3"
                  :class="entry.confidence >= 0.8 ? 'bg-green-100 text-green-700' : entry.confidence >= 0.5 ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600'"
                >
                  {{ Math.round(entry.confidence * 100) }}%
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Anomaly Alerts (1/3 width) -->
      <div class="space-y-6">
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm">
          <div class="px-5 py-4 border-b border-gray-200">
            <h3 class="font-semibold text-gray-900">Anomaly Alerts</h3>
          </div>

          <div v-if="intelStore.anomalies.length === 0" class="p-6 text-center text-gray-400">
            <svg class="w-12 h-12 mx-auto mb-2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p class="text-sm">No anomalies detected</p>
          </div>

          <div v-else class="divide-y divide-gray-100 max-h-[500px] overflow-y-auto">
            <div
              v-for="anomaly in intelStore.anomalies"
              :key="anomaly.id"
              class="px-5 py-3 hover:bg-gray-50 transition-colors"
            >
              <div class="flex items-start space-x-3">
                <!-- Severity badge -->
                <span
                  class="flex-shrink-0 mt-0.5 px-2 py-0.5 text-xs font-medium rounded-full"
                  :class="severityClass(anomaly.severity)"
                >
                  {{ anomaly.severity }}
                </span>
                <div class="min-w-0 flex-1">
                  <p class="text-sm text-gray-900">{{ anomaly.title || anomaly.message }}</p>
                  <p v-if="anomaly.description" class="text-xs text-gray-500 mt-0.5 line-clamp-2">
                    {{ anomaly.description }}
                  </p>
                  <div class="flex items-center space-x-3 mt-1.5">
                    <span class="text-xs text-gray-400">{{ formatTime(anomaly.detected_at || anomaly.created_at) }}</span>
                    <button
                      v-if="!anomaly.resolved"
                      @click="acknowledgeAnomaly(anomaly.id)"
                      class="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
                    >
                      Acknowledge
                    </button>
                    <span v-else class="text-xs text-green-600">Resolved</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Additional System Health Details -->
        <div v-if="intelStore.systemHealth" class="bg-white rounded-lg border border-gray-200 shadow-sm">
          <div class="px-5 py-4 border-b border-gray-200">
            <h3 class="font-semibold text-gray-900">System Components</h3>
          </div>
          <div class="divide-y divide-gray-100">
            <div
              v-for="(status, component) in (intelStore.systemHealth.components || {})"
              :key="component"
              class="flex items-center justify-between px-5 py-3"
            >
              <span class="text-sm text-gray-700 capitalize">{{ component.replace(/_/g, ' ') }}</span>
              <span
                class="px-2.5 py-0.5 text-xs font-medium rounded-full"
                :class="{
                  'bg-green-100 text-green-700': status === 'healthy' || status === 'up',
                  'bg-yellow-100 text-yellow-700': status === 'degraded' || status === 'warning',
                  'bg-red-100 text-red-700': status === 'down' || status === 'critical'
                }"
              >
                {{ status }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useIntelligenceStore } from '../stores/intelligence.js'

const intelStore = useIntelligenceStore()

const healthScoreColor = computed(() => {
  const score = intelStore.healthScore
  if (score === null) return 'bg-gray-100 text-gray-500'
  if (score >= 80) return 'bg-green-100 text-green-600'
  if (score >= 60) return 'bg-yellow-100 text-yellow-600'
  return 'bg-red-100 text-red-600'
})

onMounted(() => {
  refreshAll()
})

function refreshAll() {
  intelStore.fetchDailyBrief()
  intelStore.fetchAnomalies()
  intelStore.fetchLearningLog()
  intelStore.fetchSystemHealth()
}

function acknowledgeAnomaly(id) {
  intelStore.acknowledgeAnomaly(id)
}

function severityClass(severity) {
  const map = {
    critical: 'bg-red-100 text-red-700',
    high: 'bg-orange-100 text-orange-700',
    medium: 'bg-yellow-100 text-yellow-700',
    low: 'bg-blue-100 text-blue-700'
  }
  return map[severity] || 'bg-gray-100 text-gray-700'
}

function formatTime(timestamp) {
  if (!timestamp) return ''
  const date = new Date(timestamp)
  const now = new Date()
  const diff = Math.floor((now - date) / 1000)

  if (diff < 60) return 'just now'
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
  return date.toLocaleDateString()
}
</script>
