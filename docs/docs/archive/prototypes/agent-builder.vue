<template>
  <div class="h-full flex gap-6">
    <!-- Canvas -->
    <div class="flex-1 bg-[#12121A] rounded-xl border border-white/5 p-4 relative overflow-hidden">
      <h3 class="text-sm text-[#FF6B2C] mb-4 font-semibold">Agent Canvas</h3>
      <svg viewBox="0 0 500 400" class="w-full h-full max-h-[500px]">
        <!-- Connections -->
        <line v-for="conn in connections" :key="conn.id"
          :x1="conn.x1" :y1="conn.y1" :x2="conn.x2" :y2="conn.y2"
          stroke="rgba(255,107,44,0.3)" stroke-width="2" stroke-dasharray="5,5"
        />
        <!-- Animated flow particles -->
        <circle v-for="p in particles" :key="p.id"
          :cx="p.x" :cy="p.y" r="3" fill="#FF6B2C" opacity="0.8"
        >
          <animate :attributeName="p.axis" :from="p.from" :to="p.to" dur="2s" repeatCount="indefinite" />
        </circle>
        <!-- Nodes -->
        <g v-for="node in nodes" :key="node.id" :transform="`translate(${node.x},${node.y})`">
          <circle r="40" :fill="node.color" opacity="0.15" />
          <circle r="35" :fill="node.color" opacity="0.3" stroke="rgba(255,255,255,0.1)" stroke-width="1" />
          <text text-anchor="middle" y="5" fill="white" font-size="12" font-family="monospace">{{ node.label }}</text>
        </g>
      </svg>
    </div>

    <!-- Config Panel -->
    <div class="w-96 space-y-4">
      <div class="bg-[#12121A] rounded-xl border border-white/5 p-4">
        <h3 class="text-sm text-[#FF6B2C] mb-4 font-semibold">Configuration</h3>

        <div class="space-y-4">
          <div>
            <label class="block text-xs text-[#8A8A95] mb-1">Agent Name</label>
            <input v-model="config.name" type="text" placeholder="e.g. SalesQualifier"
              class="w-full bg-[#0F0F14] border border-white/10 rounded-lg px-3 py-2 text-sm focus:border-[#FF6B2C] focus:outline-none"
            />
          </div>

          <div>
            <label class="block text-xs text-[#8A8A95] mb-1">Description</label>
            <textarea v-model="config.description" rows="3" placeholder="Describe what this agent should do in plain English..."
              class="w-full bg-[#0F0F14] border border-white/10 rounded-lg px-3 py-2 text-sm focus:border-[#FF6B2C] focus:outline-none resize-none"
            ></textarea>
          </div>

          <div>
            <label class="block text-xs text-[#8A8A95] mb-2">Capabilities</label>
            <div class="flex flex-wrap gap-2">
              <button v-for="cap in capabilities" :key="cap"
                @click="toggleCap(cap)"
                :class="[
                  'px-3 py-1 rounded-full text-xs transition-all',
                  config.caps.includes(cap)
                    ? 'bg-[#FF6B2C] text-white'
                    : 'bg-white/5 text-[#8A8A95] hover:bg-white/10'
                ]"
              >{{ cap }}</button>
            </div>
          </div>

          <div>
            <label class="block text-xs text-[#8A8A95] mb-1">
              Automation Level: <span class="text-[#FF6B2C]">{{ config.automation }}%</span>
            </label>
            <input v-model="config.automation" type="range" min="0" max="100"
              class="w-full accent-[#FF6B2C]"
            />
            <div class="flex justify-between text-[10px] text-[#6B6B7B] mt-1">
              <span>Human-in-loop</span>
              <span>Autonomous</span>
            </div>
          </div>

          <div>
            <label class="block text-xs text-[#8A8A95] mb-1">Cost Ceiling ($/day)</label>
            <input v-model="config.costCeiling" type="number" min="1" max="1000"
              class="w-full bg-[#0F0F14] border border-white/10 rounded-lg px-3 py-2 text-sm focus:border-[#FF6B2C] focus:outline-none"
            />
          </div>

          <button
            @click="generateAgent"
            :disabled="!canGenerate"
            class="w-full py-3 bg-[#FF6B2C] hover:bg-[#FF8C42] disabled:bg-white/10 disabled:text-[#6B6B7B] rounded-lg font-semibold transition-all"
          >
            Generate Agent
          </button>
        </div>
      </div>

      <!-- Preview -->
      <div class="bg-[#12121A] rounded-xl border border-white/5 p-4">
        <h3 class="text-sm text-[#8A8A95] mb-3">Estimated Performance</h3>
        <div class="space-y-3 text-sm">
          <div class="flex justify-between">
            <span class="text-[#6B6B7B]">Response Time</span>
            <span class="font-mono text-[#00E5C8]">{{ estimates.responseTime }}ms</span>
          </div>
          <div class="flex justify-between">
            <span class="text-[#6B6B7B]">Cost / Agent</span>
            <span class="font-mono text-[#00E5C8]">${{ estimates.costPerAgent }}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-[#6B6B7B]">Success Probability</span>
            <span class="font-mono text-[#00E5C8]">{{ estimates.successProb }}%</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const config = ref({
  name: '',
  description: '',
  caps: [],
  automation: 50,
  costCeiling: 25
})

const capabilities = ['Sales', 'Support', 'Research', 'Code', 'Design', 'Data']

function toggleCap(cap) {
  const idx = config.value.caps.indexOf(cap)
  if (idx > -1) config.value.caps.splice(idx, 1)
  else config.value.caps.push(cap)
}

const canGenerate = computed(() => config.value.name && config.value.description)

const estimates = computed(() => {
  const base = config.value.caps.length * 15
  return {
    responseTime: 120 + base,
    costPerAgent: (0.02 + config.value.caps.length * 0.005).toFixed(3),
    successProb: Math.min(95, 70 + config.value.automation * 0.2 + config.value.caps.length * 2).toFixed(1)
  }
})

const nodes = ref([
  { id: 'agent', x: 250, y: 200, label: 'AGENT', color: '#FF6B2C' },
  { id: 'trigger', x: 100, y: 100, label: 'TRIGGER', color: '#00E5C8' },
  { id: 'action', x: 400, y: 100, label: 'ACTION', color: '#B967FF' },
  { id: 'memory', x: 100, y: 300, label: 'MEMORY', color: '#FFA726' },
  { id: 'output', x: 400, y: 300, label: 'OUTPUT', color: '#00E5C8' }
])

const connections = ref([
  { id: 1, x1: 140, y1: 100, x2: 210, y2: 170 },
  { id: 2, x1: 290, y1: 170, x2: 360, y2: 100 },
  { id: 3, x1: 140, y1: 300, x2: 210, y2: 230 },
  { id: 4, x1: 290, y1: 230, x2: 360, y2: 300 }
])

const particles = ref([
  { id: 1, x: 140, y: 100, axis: 'x', from: 140, to: 210 },
  { id: 2, x: 290, y: 170, axis: 'x', from: 290, to: 360 },
  { id: 3, x: 140, y: 300, axis: 'x', from: 140, to: 210 },
  { id: 4, x: 290, y: 230, axis: 'x', from: 290, to: 360 }
])

function generateAgent() {
  alert(`Agent "${config.value.name}" would be generated here.\n\nIn production, this sends the configuration to the MetaPlanner which creates the agent workflow.`)
}
</script>
