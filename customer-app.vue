<template>
  <div class="min-h-screen bg-[#0F0F14] text-[#E8E8EF] flex">
    <!-- Sidebar -->
    <aside class="w-64 bg-[#12121A] border-r border-white/5 flex flex-col">
      <div class="p-6 border-b border-white/5">
        <h1 class="text-xl font-bold text-[#FF6B2C]">SpiderNetOS</h1>
        <p class="text-xs text-[#6B6B7B] mt-1">Customer Control Plane</p>
      </div>
      <nav class="flex-1 p-4 space-y-1">
        <button
          v-for="item in navItems"
          :key="item.id"
          @click="currentView = item.id"
          :class="[
            'w-full text-left px-4 py-3 rounded-lg text-sm font-medium transition-all',
            currentView === item.id
              ? 'bg-[#FF6B2C]/10 text-[#FF6B2C] border border-[#FF6B2C]/20'
              : 'text-[#8A8A95] hover:bg-white/5 hover:text-[#E8E8EF]'
          ]"
        >
          <span class="mr-3">{{ item.icon }}</span>
          {{ item.label }}
          <span
            v-if="item.badge"
            class="ml-auto inline-block px-2 py-0.5 text-xs bg-[#FF6B2C] text-white rounded-full"
          >{{ item.badge }}</span>
        </button>
      </nav>
      <div class="p-4 border-t border-white/5">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-full bg-[#FF6B2C]/20 flex items-center justify-center text-sm">🧑</div>
          <div class="text-sm">
            <div class="font-medium">Admin User</div>
            <div class="text-xs text-[#6B6B7B]">Pro Plan</div>
          </div>
        </div>
      </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 overflow-auto">
      <!-- Top Bar -->
      <header class="h-16 bg-[#12121A]/80 backdrop-blur border-b border-white/5 flex items-center justify-between px-6 sticky top-0 z-10">
        <h2 class="text-lg font-semibold">{{ currentNav.label }}</h2>
        <div class="flex items-center gap-4">
          <div class="text-right">
            <div class="text-xs text-[#6B6B7B]">Daily Budget</div>
            <div class="text-sm font-mono" :class="budgetPct > 90 ? 'text-[#F44336]' : 'text-[#00E5C8]'">
              ${{ spent.toFixed(2) }} / ${{ budget }}
            </div>
          </div>
          <div class="w-32 h-2 bg-white/10 rounded-full overflow-hidden">
            <div
              class="h-full rounded-full transition-all"
              :class="budgetPct > 90 ? 'bg-[#F44336]' : budgetPct > 70 ? 'bg-[#FFA726]' : 'bg-[#00E5C8]'"
              :style="{ width: budgetPct + '%' }"
            ></div>
          </div>
        </div>
      </header>

      <!-- View Content -->
      <div class="p-6">
        <Dashboard v-if="currentView === 'dashboard'" />
        <AgentBuilder v-if="currentView === 'agents'" />
        <Approvals v-if="currentView === 'approvals'" />
        <div v-if="currentView === 'flows'" class="text-center py-20 text-[#6B6B7B]">
          <div class="text-4xl mb-4">🔄</div>
          <p>Flows view coming soon</p>
        </div>
        <div v-if="currentView === 'traces'" class="text-center py-20 text-[#6B6B7B]">
          <div class="text-4xl mb-4">📊</div>
          <p>Traces view coming soon</p>
        </div>
        <div v-if="currentView === 'usage'" class="text-center py-20 text-[#6B6B7B]">
          <div class="text-4xl mb-4">💰</div>
          <p>Usage analytics coming soon</p>
        </div>
        <div v-if="currentView === 'memory'" class="text-center py-20 text-[#6B6B7B]">
          <div class="text-4xl mb-4">🧠</div>
          <p>Memory graph coming soon</p>
        </div>
        <div v-if="currentView === 'settings'" class="text-center py-20 text-[#6B6B7B]">
          <div class="text-4xl mb-4">⚙️</div>
          <p>Settings coming soon</p>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import Dashboard from './views/Dashboard.vue'
import AgentBuilder from './views/AgentBuilder.vue'
import Approvals from './views/Approvals.vue'

const currentView = ref('dashboard')

const navItems = [
  { id: 'dashboard', label: 'Dashboard', icon: '📈' },
  { id: 'agents', label: 'Agents', icon: '🤖' },
  { id: 'flows', label: 'Flows', icon: '🔄' },
  { id: 'approvals', label: 'Approvals', icon: '✅', badge: 3 },
  { id: 'traces', label: 'Traces', icon: '📊' },
  { id: 'usage', label: 'Usage', icon: '💰' },
  { id: 'memory', label: 'Memory', icon: '🧠' },
  { id: 'settings', label: 'Settings', icon: '⚙️' }
]

const currentNav = computed(() => navItems.find(n => n.id === currentView.value) || navItems[0])

const spent = ref(34.50)
const budget = ref(50.00)
const budgetPct = computed(() => (spent.value / budget.value) * 100)
</script>
