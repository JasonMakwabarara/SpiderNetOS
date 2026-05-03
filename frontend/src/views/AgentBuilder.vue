<template>
  <div class="agent-builder p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Agent Builder</h1>
        <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }">
          {{ mode === 'templates' ? 'Choose a template or build from scratch' : 'Configure your new agent' }}
        </p>
      </div>
      <div class="flex items-center space-x-3">
        <button
          v-if="mode === 'builder'"
          @click="resetToTemplates"
          class="px-4 py-2 rounded-xl transition-colors"
          :style="{ background: 'var(--surface-low)', color: 'var(--text-secondary)' }"
        >
          Back to Templates
        </button>
        <button
          v-if="mode === 'templates'"
          @click="startFromScratch"
          class="dct-btn-primary"
        >
          Build from Scratch
        </button>
      </div>
    </div>

    <!-- ═══ TEMPLATE MODE ═══ -->
    <div v-if="mode === 'templates'" class="space-y-6">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <div
          v-for="tmpl in templates"
          :key="tmpl.id"
          class="dct-card p-6 flex flex-col cursor-pointer group"
          @click="useTemplate(tmpl)"
        >
          <!-- Icon -->
          <div
            class="w-12 h-12 rounded-xl flex items-center justify-center mb-4"
            :style="{ background: tmpl.iconBg }"
          >
            <svg class="w-6 h-6" :style="{ color: tmpl.iconColor }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                :d="tmpl.iconPath"
              />
            </svg>
          </div>

          <!-- Name & Description -->
          <h3 class="text-lg font-semibold mb-1" :style="{ color: 'var(--text-primary)' }">
            {{ tmpl.name }}
          </h3>
          <p class="text-sm mb-4 line-clamp-2" :style="{ color: 'var(--text-muted)' }">
            {{ tmpl.description }}
          </p>

          <!-- Capabilities pills -->
          <div class="flex flex-wrap gap-1.5 mb-4">
            <span
              v-for="cap in tmpl.capabilities.slice(0, 4)"
              :key="cap"
              :class="tmpl.pillClass"
            >
              {{ cap }}
            </span>
            <span
              v-if="tmpl.capabilities.length > 4"
              class="dct-pill-cyan"
            >
              +{{ tmpl.capabilities.length - 4 }} more
            </span>
          </div>

          <div class="mt-auto">
            <button
              class="dct-btn-primary w-full text-center text-sm"
              @click.stop="useTemplate(tmpl)"
            >
              Use Template
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ BUILDER MODE ═══ -->
    <div v-else class="space-y-6">
      <!-- Error Banner -->
      <div
        v-if="agentsStore.error"
        class="p-4 rounded-xl border"
        :style="{ background: 'color-mix(in srgb, #FF6EB4 10%, transparent)', borderColor: 'var(--dusk-vivid)' }"
      >
        <p class="text-sm font-medium" :style="{ color: 'var(--dusk-dark)' }">{{ agentsStore.error }}</p>
      </div>

      <form @submit.prevent="handleSave" class="space-y-6">
        <!-- ── Section: Identity ── -->
        <div class="dct-card p-6 space-y-5">
          <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Identity</h2>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <!-- Name -->
            <div>
              <label class="block text-sm font-medium mb-1" :style="{ color: 'var(--text-secondary)' }">Name</label>
              <input
                v-model="form.name"
                @input="autoSlug"
                type="text"
                required
                placeholder="e.g. Executive Assistant"
                class="w-full px-4 py-2.5 rounded-xl border focus:ring-2 focus:outline-none transition-colors"
                :style="{
                  background: 'var(--surface-low)',
                  borderColor: 'var(--border)',
                  color: 'var(--text-primary)',
                  '--tw-ring-color': 'var(--dusk-vivid)'
                }"
              />
            </div>

            <!-- Slug (auto-generated) -->
            <div>
              <label class="block text-sm font-medium mb-1" :style="{ color: 'var(--text-secondary)' }">Slug</label>
              <input
                v-model="form.slug"
                type="text"
                required
                placeholder="executive-assistant"
                class="w-full px-4 py-2.5 rounded-xl border focus:ring-2 focus:outline-none font-mono text-sm transition-colors"
                :style="{
                  background: 'var(--surface-low)',
                  borderColor: 'var(--border)',
                  color: 'var(--text-muted)',
                  '--tw-ring-color': 'var(--dusk-vivid)'
                }"
              />
            </div>
          </div>

          <!-- Description -->
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-sm font-medium" :style="{ color: 'var(--text-secondary)' }">Description</label>
              <EnhancePromptButton
                v-model="form.description"
                surface="agent_builder"
                mode="concise"
                label="Enhance"
                applyMode="preview"
                variant="ghost"
              />
            </div>
            <textarea
              v-model="form.description"
              rows="3"
              placeholder="What does this agent do? What's its primary role?"
              class="w-full px-4 py-2.5 rounded-xl border focus:ring-2 focus:outline-none resize-none transition-colors"
              :style="{
                background: 'var(--surface-low)',
                borderColor: 'var(--border)',
                color: 'var(--text-primary)',
                '--tw-ring-color': 'var(--dusk-vivid)'
              }"
            />
          </div>

          <!-- Avatar Selector -->
          <div>
            <label class="block text-sm font-medium mb-2" :style="{ color: 'var(--text-secondary)' }">Avatar</label>
            <div class="flex items-center space-x-3">
              <button
                v-for="av in avatarOptions"
                :key="av"
                type="button"
                @click="form.avatar = av"
                class="w-12 h-12 rounded-xl flex items-center justify-center text-xl border-2 transition-all"
                :class="form.avatar === av ? 'scale-110' : 'opacity-60 hover:opacity-100'"
                :style="{
                  background: form.avatar === av ? 'color-mix(in srgb, var(--dusk-vivid) 15%, transparent)' : 'var(--surface-low)',
                  borderColor: form.avatar === av ? 'var(--dusk-vivid)' : 'var(--border)'
                }"
              >
                {{ av }}
              </button>
            </div>
          </div>
        </div>

        <!-- ── Section: Instructions ── -->
        <div class="dct-card p-6 space-y-4">
          <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Instructions</h2>
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-sm font-medium" :style="{ color: 'var(--text-secondary)' }">System Prompt</label>
              <EnhancePromptButton
                v-model="form.systemPrompt"
                surface="agent_builder"
                mode="deep"
                label="Enhance"
                applyMode="preview"
              />
            </div>
            <textarea
              v-model="form.systemPrompt"
              rows="8"
              placeholder="You are a helpful assistant... (Markdown supported)"
              class="w-full px-4 py-3 rounded-xl border focus:ring-2 focus:outline-none resize-y font-mono text-sm transition-colors"
              :style="{
                background: 'var(--surface-low)',
                borderColor: 'var(--border)',
                color: 'var(--text-primary)',
                '--tw-ring-color': 'var(--dusk-vivid)'
              }"
            />
            <p class="mt-1 text-xs" :style="{ color: 'var(--text-muted)' }">
              Supports Markdown formatting. Define the agent's personality, instructions, and constraints.
            </p>
          </div>
        </div>

        <!-- ── Section: Model Configuration ── -->
        <div class="dct-card p-6 space-y-5">
          <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Model Configuration</h2>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <!-- Model Select -->
            <div>
              <label class="block text-sm font-medium mb-1" :style="{ color: 'var(--text-secondary)' }">Model</label>
              <select
                v-model="form.model"
                class="w-full px-4 py-2.5 rounded-xl border focus:ring-2 focus:outline-none transition-colors appearance-none"
                :style="{
                  background: 'var(--surface-low)',
                  borderColor: 'var(--border)',
                  color: 'var(--text-primary)',
                  '--tw-ring-color': 'var(--dusk-vivid)'
                }"
              >
                <option v-for="m in modelOptions" :key="m.value" :value="m.value">
                  {{ m.label }}
                </option>
              </select>
            </div>

            <!-- Temperature Slider -->
            <div>
              <label class="block text-sm font-medium mb-1" :style="{ color: 'var(--text-secondary)' }">
                Temperature: <span class="font-mono">{{ form.temperature.toFixed(2) }}</span>
              </label>
              <input
                v-model.number="form.temperature"
                type="range"
                min="0"
                max="1"
                step="0.01"
                class="w-full mt-2 accent-[var(--dusk-vivid)]"
              />
              <div class="flex justify-between text-xs mt-1" :style="{ color: 'var(--text-muted)' }">
                <span>Precise</span>
                <span>Creative</span>
              </div>
            </div>

            <!-- Max Tokens -->
            <div>
              <label class="block text-sm font-medium mb-1" :style="{ color: 'var(--text-secondary)' }">Max Tokens</label>
              <input
                v-model.number="form.maxTokens"
                type="number"
                min="256"
                max="128000"
                step="256"
                class="w-full px-4 py-2.5 rounded-xl border focus:ring-2 focus:outline-none transition-colors"
                :style="{
                  background: 'var(--surface-low)',
                  borderColor: 'var(--border)',
                  color: 'var(--text-primary)',
                  '--tw-ring-color': 'var(--dusk-vivid)'
                }"
              />
            </div>
          </div>
        </div>

        <!-- ── Section: Capabilities ── -->
        <div class="dct-card p-6 space-y-4">
          <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Capabilities</h2>
          <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
            <label
              v-for="cap in availableCapabilities"
              :key="cap"
              class="flex items-center gap-2 px-3 py-2 rounded-xl border cursor-pointer transition-all"
              :style="{
                background: form.capabilities.includes(cap)
                  ? 'color-mix(in srgb, var(--charge-vivid) 12%, transparent)'
                  : 'var(--surface-low)',
                borderColor: form.capabilities.includes(cap)
                  ? 'var(--charge-vivid)'
                  : 'var(--border)'
              }"
            >
              <input
                v-model="form.capabilities"
                type="checkbox"
                :value="cap"
                class="w-4 h-4 rounded accent-[var(--charge-vivid)]"
              />
              <span
                class="text-sm font-medium"
                :style="{ color: form.capabilities.includes(cap) ? 'var(--charge-dark)' : 'var(--text-secondary)' }"
              >
                {{ cap }}
              </span>
            </label>
          </div>
        </div>

        <!-- ── Section: Tools ── -->
        <div class="dct-card p-6 space-y-4">
          <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Tools</h2>
          <p class="text-sm" :style="{ color: 'var(--text-muted)' }">
            Select which tools this agent can access during execution.
          </p>
          <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
            <label
              v-for="tool in availableTools"
              :key="tool"
              class="flex items-center gap-2 px-3 py-2 rounded-xl border cursor-pointer transition-all"
              :style="{
                background: form.tools.includes(tool)
                  ? 'color-mix(in srgb, var(--tealime-vivid) 12%, transparent)'
                  : 'var(--surface-low)',
                borderColor: form.tools.includes(tool)
                  ? 'var(--tealime-vivid)'
                  : 'var(--border)'
              }"
            >
              <input
                v-model="form.tools"
                type="checkbox"
                :value="tool"
                class="w-4 h-4 rounded accent-[var(--tealime-vivid)]"
              />
              <span
                class="text-sm font-mono"
                :style="{ color: form.tools.includes(tool) ? 'var(--tealime-dark)' : 'var(--text-secondary)' }"
              >
                {{ tool }}
              </span>
            </label>
          </div>
        </div>

        <!-- ── Section: Delegation ── -->
        <div class="dct-card p-6 space-y-4">
          <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Delegation</h2>
          <p class="text-sm" :style="{ color: 'var(--text-muted)' }">
            Select which existing agents this agent can delegate tasks to.
          </p>

          <div v-if="agentsStore.agents.length === 0" class="text-center py-6">
            <p class="text-sm" :style="{ color: 'var(--text-muted)' }">No existing agents to delegate to.</p>
          </div>

          <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <label
              v-for="agent in agentsStore.agents"
              :key="agent.id"
              class="flex items-center gap-3 px-4 py-3 rounded-xl border cursor-pointer transition-all"
              :style="{
                background: form.delegateTo.includes(agent.id)
                  ? 'color-mix(in srgb, var(--dusk-vivid) 10%, transparent)'
                  : 'var(--surface-low)',
                borderColor: form.delegateTo.includes(agent.id)
                  ? 'var(--dusk-vivid)'
                  : 'var(--border)'
              }"
            >
              <input
                v-model="form.delegateTo"
                type="checkbox"
                :value="agent.id"
                class="w-4 h-4 rounded accent-[var(--dusk-vivid)]"
              />
              <div class="min-w-0">
                <p class="text-sm font-semibold truncate" :style="{ color: 'var(--text-primary)' }">{{ agent.name }}</p>
                <p class="text-xs truncate" :style="{ color: 'var(--text-muted)' }">@{{ agent.slug }}</p>
              </div>
              <span
                class="ml-auto text-xs px-2 py-0.5 rounded-full font-medium whitespace-nowrap"
                :class="{
                  'dct-pill-lime': agent.status === 'active',
                  'dct-pill-pink': agent.status === 'paused',
                  'dct-pill-cyan': agent.status === 'draft'
                }"
              >
                {{ agent.status }}
              </span>
            </label>
          </div>
        </div>

        <!-- ── Section: Test Panel ── -->
        <div class="dct-card p-6 space-y-4">
          <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Test Panel</h2>
          <p class="text-sm" :style="{ color: 'var(--text-muted)' }">
            Try your agent before publishing. Send a test message and see the response.
          </p>

          <div class="flex items-end gap-3">
            <div class="flex-1">
              <label class="block text-sm font-medium mb-1" :style="{ color: 'var(--text-secondary)' }">Test Message</label>
              <input
                v-model="testInput"
                type="text"
                placeholder="Ask your agent something..."
                @keydown.enter.prevent="testAgent"
                class="w-full px-4 py-2.5 rounded-xl border focus:ring-2 focus:outline-none transition-colors"
                :style="{
                  background: 'var(--surface-low)',
                  borderColor: 'var(--border)',
                  color: 'var(--text-primary)',
                  '--tw-ring-color': 'var(--dusk-vivid)'
                }"
              />
            </div>
            <button
              type="button"
              @click="testAgent"
              :disabled="isTesting || !testInput.trim()"
              class="dct-btn-primary px-6 py-2.5 disabled:opacity-50 whitespace-nowrap"
            >
              {{ isTesting ? 'Testing...' : 'Try It' }}
            </button>
          </div>

          <!-- Response -->
          <div
            v-if="testResponse !== null"
            class="rounded-xl p-4 border"
            :style="{
              background: 'var(--surface-high)',
              borderColor: 'var(--border)'
            }"
          >
            <p class="text-xs font-semibold uppercase tracking-wider mb-2" :style="{ color: 'var(--text-muted)' }">
              Agent Response
            </p>
            <div
              v-if="testResponse"
              class="text-sm whitespace-pre-wrap"
              :style="{ color: 'var(--text-primary)' }"
            >
              {{ testResponse }}
            </div>
            <div v-else class="flex items-center gap-2">
              <div class="w-4 h-4 border-2 border-t-transparent rounded-full animate-spin" :style="{ borderColor: 'var(--dusk-vivid)', borderTopColor: 'transparent' }" />
              <span class="text-sm" :style="{ color: 'var(--text-muted)' }">Thinking...</span>
            </div>
          </div>
        </div>

        <!-- ── Action Buttons ── -->
        <div class="flex items-center justify-end gap-4 pt-2">
          <button
            type="button"
            @click="resetToTemplates"
            class="px-6 py-2.5 rounded-xl font-medium transition-colors"
            :style="{ color: 'var(--text-secondary)', background: 'var(--surface-low)' }"
          >
            Cancel
          </button>
          <button
            type="submit"
            :disabled="isSaving"
            class="dct-btn-primary px-8 py-2.5 disabled:opacity-50"
          >
            {{ isSaving ? 'Saving...' : 'Save Agent' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAgentsStore } from '../stores/agents.js'
import EnhancePromptButton from '../components/prompt/EnhancePromptButton.vue'

const route = useRoute()
const router = useRouter()
const agentsStore = useAgentsStore()

// ─── State ──────────────────────────────────────────────────
const mode = ref('templates') // 'templates' | 'builder'
const isSaving = ref(false)
const isTesting = ref(false)
const testInput = ref('')
const testResponse = ref(null)

const avatarOptions = ['🤖', '🧠', '🛡️', '⚙️', '📊', '📚', '🔮', '🎯']

const modelOptions = [
  { value: 'gemma3:9b', label: 'Gemma 3 — 9B' },
  { value: 'gemma3:27b', label: 'Gemma 3 — 27B' },
  { value: 'gemma-4-31b', label: 'Gemma 4 — 31B' },
  { value: 'gpt-4o-mini', label: 'GPT-4o Mini' },
  { value: 'gpt-4o', label: 'GPT-4o' }
]

const availableCapabilities = [
  'chat', 'task_planning', 'task_delegation', 'content_generation',
  'data_analysis', 'research', 'monitoring', 'anomaly_detection',
  'alerting', 'flow_creation', 'dag_building', 'automation',
  'memory_access', 'teaching', 'tutoring', 'onboarding'
]

const availableTools = [
  'memory_search', 'flow_execution', 'data_analysis',
  'system_status', 'create_flow', 'web_search',
  'file_read', 'file_write', 'code_execution',
  'notification_send', 'calendar_access', 'api_call'
]

// ─── Templates ──────────────────────────────────────────────
const templates = [
  {
    id: 'executive-assistant',
    name: 'Executive Assistant',
    description: 'Like Kilo/Atlas — natural language interface for task planning, delegation, and workflow orchestration across your agent fleet.',
    capabilities: ['chat', 'task_planning', 'task_delegation', 'content_generation', 'memory_access'],
    tools: ['memory_search', 'flow_execution', 'notification_send', 'calendar_access'],
    model: 'gpt-4o',
    temperature: 0.7,
    maxTokens: 4096,
    systemPrompt: 'You are an executive assistant AI agent. You help users plan tasks, delegate work to other agents, and manage workflows. Be proactive, concise, and action-oriented. Always confirm before executing irreversible operations.',
    iconPath: 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
    iconBg: 'color-mix(in srgb, #FF6EB4 15%, transparent)',
    iconColor: '#FF6EB4',
    pillClass: 'dct-pill-pink'
  },
  {
    id: 'analyst',
    name: 'Analyst',
    description: 'Data analysis specialist — research, reporting, insight extraction. Crunches numbers, generates reports, and surfaces patterns.',
    capabilities: ['data_analysis', 'research', 'content_generation', 'memory_access'],
    tools: ['memory_search', 'data_analysis', 'web_search', 'file_read'],
    model: 'gpt-4o',
    temperature: 0.3,
    maxTokens: 8192,
    systemPrompt: 'You are a data analyst AI agent. You analyze datasets, identify patterns, generate reports, and provide actionable insights. Always cite your data sources. Present findings clearly with supporting evidence.',
    iconPath: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
    iconBg: 'color-mix(in srgb, #39FF14 15%, transparent)',
    iconColor: '#39FF14',
    pillClass: 'dct-pill-lime'
  },
  {
    id: 'monitor',
    name: 'Monitor',
    description: 'System watchdog — health checks, anomaly detection, alerting. Keeps your infrastructure and agent fleet running smoothly.',
    capabilities: ['monitoring', 'anomaly_detection', 'alerting', 'memory_access'],
    tools: ['system_status', 'memory_search', 'notification_send', 'api_call'],
    model: 'gemma3:9b',
    temperature: 0.1,
    maxTokens: 2048,
    systemPrompt: 'You are a system monitor AI agent. You perform health checks, detect anomalies, and trigger alerts when thresholds are breached. Be precise and minimize false positives. Prioritize critical issues over informational notices.',
    iconPath: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
    iconBg: 'color-mix(in srgb, #00E5C8 15%, transparent)',
    iconColor: '#00E5C8',
    pillClass: 'dct-pill-cyan'
  },
  {
    id: 'builder',
    name: 'Builder',
    description: 'Automation architect — creates flows, designs DAGs, and builds automated pipelines. The engineer of your agent ecosystem.',
    capabilities: ['flow_creation', 'dag_building', 'automation', 'task_planning'],
    tools: ['create_flow', 'flow_execution', 'code_execution', 'file_write'],
    model: 'gemma3:27b',
    temperature: 0.4,
    maxTokens: 8192,
    systemPrompt: 'You are a builder AI agent. You create automated flows, design DAG pipelines, and build automation workflows. Think step-by-step when designing complex flows. Validate configurations before deploying. Prefer composable, reusable patterns.',
    iconPath: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
    iconBg: 'color-mix(in srgb, #FF6EB4 10%, color-mix(in srgb, #39FF14 10%, transparent))',
    iconColor: '#FF6EB4',
    pillClass: 'dct-pill-pink'
  },
  {
    id: 'tutor',
    name: 'Tutor',
    description: 'Learning companion — help documentation, onboarding walkthroughs, and guided learning paths for your team and users.',
    capabilities: ['teaching', 'tutoring', 'onboarding', 'content_generation', 'memory_access'],
    tools: ['memory_search', 'web_search', 'file_read', 'notification_send'],
    model: 'gemma3:27b',
    temperature: 0.6,
    maxTokens: 4096,
    systemPrompt: 'You are a tutor AI agent. You guide users through learning paths, explain concepts clearly, and help with onboarding. Adapt your teaching style to the user\'s level. Use examples and analogies. Break complex topics into digestible steps.',
    iconPath: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253',
    iconBg: 'color-mix(in srgb, #00E5C8 10%, color-mix(in srgb, #39FF14 10%, transparent))',
    iconColor: '#2DD4BF',
    pillClass: 'dct-pill-cyan'
  }
]

// ─── Form ───────────────────────────────────────────────────
const form = reactive({
  name: '',
  slug: '',
  description: '',
  avatar: '🤖',
  systemPrompt: '',
  model: 'gemma3:27b',
  temperature: 0.7,
  maxTokens: 4096,
  capabilities: [],
  tools: [],
  delegateTo: [],
  status: 'draft'
})

// ─── Lifecycle ──────────────────────────────────────────────
onMounted(() => {
  agentsStore.fetchAgents()

  // If a templateId was passed via route query, pre-fill
  const templateId = route.query.templateId
  if (templateId) {
    const tmpl = templates.find(t => t.id === templateId)
    if (tmpl) {
      useTemplate(tmpl)
    }
  }
})

// ─── Methods ────────────────────────────────────────────────
function autoSlug() {
  form.slug = form.name
    .toLowerCase()
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
}

function useTemplate(tmpl) {
  form.name = tmpl.name
  form.slug = tmpl.id
  form.description = tmpl.description
  form.systemPrompt = tmpl.systemPrompt
  form.model = tmpl.model
  form.temperature = tmpl.temperature
  form.maxTokens = tmpl.maxTokens
  form.capabilities = [...tmpl.capabilities]
  form.tools = [...tmpl.tools]
  form.delegateTo = []
  mode.value = 'builder'
}

function startFromScratch() {
  form.name = ''
  form.slug = ''
  form.description = ''
  form.avatar = '🤖'
  form.systemPrompt = ''
  form.model = 'gemma3:27b'
  form.temperature = 0.7
  form.maxTokens = 4096
  form.capabilities = []
  form.tools = []
  form.delegateTo = []
  mode.value = 'builder'
}

function resetToTemplates() {
  mode.value = 'templates'
  testResponse.value = null
  testInput.value = ''
}

async function testAgent() {
  if (!testInput.value.trim()) return

  isTesting.value = true
  testResponse.value = '' // show loading spinner

  try {
    // Attempt a real test dispatch; fallback to simulated response
    const result = await agentsStore.dispatchAgent(null, 'test', {
      message: testInput.value,
      model: form.model,
      system_prompt: form.systemPrompt,
      temperature: form.temperature,
      max_tokens: form.maxTokens
    })

    if (result?.success && result.result?.data?.response) {
      testResponse.value = result.result.data.response
    } else {
      // Simulated fallback for pre-publish testing
      testResponse.value = `[Test Mode] Agent "${form.name || 'Untitled'}" would respond to: "${testInput.value}"\n\nModel: ${form.model} | Temp: ${form.temperature} | Max Tokens: ${form.maxTokens}\nCapabilities: ${form.capabilities.join(', ') || 'none'}\nTools: ${form.tools.join(', ') || 'none'}`
    }
  } catch {
    testResponse.value = `[Test Mode] Agent "${form.name || 'Untitled'}" would respond to: "${testInput.value}"\n\nModel: ${form.model} | Temp: ${form.temperature} | Max Tokens: ${form.maxTokens}\nCapabilities: ${form.capabilities.join(', ') || 'none'}\nTools: ${form.tools.join(', ') || 'none'}`
  } finally {
    isTesting.value = false
  }
}

async function handleSave() {
  isSaving.value = true

  const agentData = {
    name: form.name,
    slug: form.slug,
    description: form.description,
    avatar: form.avatar,
    system_prompt: form.systemPrompt,
    model: form.model,
    temperature: form.temperature,
    max_tokens: form.maxTokens,
    capabilities: form.capabilities,
    tools: form.tools,
    delegate_to: form.delegateTo,
    status: form.status
  }

  try {
    const result = await agentsStore.createAgent(agentData)
    if (result.success) {
      router.push('/agents')
    }
  } finally {
    isSaving.value = false
  }
}
</script>
