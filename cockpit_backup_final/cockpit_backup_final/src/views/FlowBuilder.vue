<template>
  <div class="flow-builder h-screen flex flex-col">
    <!-- Header -->
    <div class="flex items-center justify-between px-6 py-3 bg-white border-b border-gray-200">
      <div class="flex items-center space-x-4">
        <button @click="$router.push('/flows')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </button>
        <div>
          <input
            v-model="flowName"
            type="text"
            placeholder="Flow Name"
            class="font-semibold text-lg border-none focus:ring-0 p-0"
          />
          <input
            v-model="flowSlug"
            type="text"
            placeholder="slug"
            class="text-sm text-gray-500 border-none focus:ring-0 p-0 block"
          />
        </div>
      </div>
      <div class="flex items-center space-x-3">
        <span v-if="saveStatus" class="text-sm" :class="saveStatus.type === 'error' ? 'text-red-600' : 'text-green-600'">
          {{ saveStatus.message }}
        </span>
        <button
          @click="saveFlow"
          :disabled="isSaving"
          class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 disabled:opacity-50"
        >
          {{ isSaving ? 'Saving...' : 'Save Draft' }}
        </button>
        <button
          @click="publishFlow"
          :disabled="isPublishing || nodes.length === 0"
          class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50"
        >
          {{ isPublishing ? 'Publishing...' : 'Publish' }}
        </button>
      </div>
    </div>

    <!-- Toolbar -->
    <div class="flex items-center px-6 py-2 bg-gray-50 border-b border-gray-200 space-x-2">
      <span class="text-sm text-gray-500 mr-2">Add Node:</span>
      <button
        v-for="type in nodeTypes"
        :key="type.value"
        @click="addNode(type.value)"
        class="px-3 py-1.5 text-sm bg-white border border-gray-300 rounded hover:bg-gray-50 flex items-center space-x-1"
      >
        <span>{{ type.icon }}</span>
        <span>{{ type.label }}</span>
      </button>
    </div>

    <!-- Canvas -->
    <div class="flex-1 flex overflow-hidden">
      <!-- Flow Canvas -->
      <div 
        ref="canvas"
        class="flex-1 bg-gray-100 relative overflow-auto"
        @click="deselectAll"
      >
        <div class="absolute inset-0" style="min-width: 2000px; min-height: 2000px;">
          <!-- Grid Pattern -->
          <div 
            class="absolute inset-0 opacity-10"
            style="background-image: radial-gradient(#000 1px, transparent 1px); background-size: 20px 20px;"
          />

          <!-- Nodes -->
          <div
            v-for="node in nodes"
            :key="node.id"
            class="absolute bg-white rounded-lg shadow-md border-2 cursor-move select-none"
            :class="{
              'border-indigo-500': selectedNode?.id === node.id,
              'border-gray-300': selectedNode?.id !== node.id,
              'border-green-500': node.type === 'trigger',
              'border-blue-500': node.type === 'agent',
              'border-yellow-500': node.type === 'condition',
              'border-purple-500': node.type === 'action',
              'border-gray-500': node.type === 'output'
            }"
            :style="{ left: `${node.x}px`, top: `${node.y}px`, width: '180px' }"
            @mousedown.stop="startDrag($event, node)"
            @click.stop="selectNode(node)"
          >
            <div class="p-3">
              <div class="flex items-center space-x-2 mb-2">
                <span>{{ getNodeIcon(node.type) }}</span>
                <span class="font-medium text-sm capitalize">{{ node.type }}</span>
              </div>
              <p class="text-xs text-gray-500 truncate">{{ node.label || 'Untitled' }}</p>
              <p v-if="node.agentId" class="text-xs text-indigo-600 truncate">@{{ node.agentId }}</p>
            </div>
            <!-- Connection Points -->
            <div 
              class="absolute -left-2 top-1/2 w-4 h-4 bg-gray-400 rounded-full cursor-pointer hover:bg-indigo-500"
              @mousedown.stop="startConnection($event, node, 'input')"
            />
            <div 
              class="absolute -right-2 top-1/2 w-4 h-4 bg-gray-400 rounded-full cursor-pointer hover:bg-indigo-500"
              @mousedown.stop="startConnection($event, node, 'output')"
            />
          </div>

          <!-- Edges (SVG) -->
          <svg class="absolute inset-0 pointer-events-none" style="width: 2000px; height: 2000px;">
            <path
              v-for="edge in edges"
              :key="edge.id"
              :d="getEdgePath(edge)"
              stroke="#6366f1"
              stroke-width="2"
              fill="none"
              marker-end="url(#arrowhead)"
            />
            <defs>
              <marker id="arrowhead" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">
                <polygon points="0 0, 10 3.5, 0 7" fill="#6366f1" />
              </marker>
            </defs>
          </svg>
        </div>
      </div>

      <!-- Properties Panel -->
      <div v-if="selectedNode" class="w-80 bg-white border-l border-gray-200 p-4 overflow-y-auto">
        <h3 class="font-semibold text-gray-900 mb-4">Node Properties</h3>
        
        <div class="space-y-4">
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-sm font-medium text-gray-700">Label</label>
              <EnhancePromptButton
                v-model="selectedNode.label"
                surface="flow_builder"
                mode="concise"
                label="Enhance"
                applyMode="replace"
                variant="ghost"
              />
            </div>
            <input
              v-model="selectedNode.label"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500"
            />
          </div>

          <div v-if="selectedNode.type === 'agent'">
            <label class="block text-sm font-medium text-gray-700 mb-1">Agent</label>
            <select
              v-model="selectedNode.agentId"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">Select Agent</option>
              <option v-for="agent in agentsStore.agents" :key="agent.id" :value="agent.slug">
                {{ agent.name }}
              </option>
            </select>
          </div>

          <div v-if="selectedNode.type === 'condition'">
            <label class="block text-sm font-medium text-gray-700 mb-1">Condition</label>
            <input
              v-model="selectedNode.condition"
              type="text"
              placeholder="e.g., status === 'success'"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Configuration (JSON)</label>
            <textarea
              v-model="nodeConfigJson"
              rows="6"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 font-mono text-xs"
            />
          </div>

          <button
            @click="deleteSelectedNode"
            class="w-full py-2 bg-red-50 text-red-700 rounded-lg hover:bg-red-100"
          >
            Delete Node
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useFlowsStore } from '../stores/flows.js'
import { useAgentsStore } from '../stores/agents.js'
import EnhancePromptButton from '../components/prompt/EnhancePromptButton.vue'

const route = useRoute()
const router = useRouter()
const flowsStore = useFlowsStore()
const agentsStore = useAgentsStore()

const flowId = computed(() => route.params.id)
const isNew = computed(() => !flowId.value)

const flowName = ref('New Flow')
const flowSlug = ref('new-flow')
const nodes = ref([])
const edges = ref([])
const selectedNode = ref(null)
const isSaving = ref(false)
const isPublishing = ref(false)
const saveStatus = ref(null)

const nodeTypes = [
  { value: 'trigger', label: 'Trigger', icon: '⚡' },
  { value: 'agent', label: 'Agent', icon: '🤖' },
  { value: 'condition', label: 'Condition', icon: '❓' },
  { value: 'action', label: 'Action', icon: '⚙️' },
  { value: 'output', label: 'Output', icon: '📤' }
]

const nodeConfigJson = computed({
  get: () => JSON.stringify(selectedNode.value?.config || {}, null, 2),
  set: (val) => {
    try {
      if (selectedNode.value) {
        selectedNode.value.config = JSON.parse(val)
      }
    } catch (e) {
      // Invalid JSON, ignore
    }
  }
})

onMounted(() => {
  agentsStore.fetchAgents()
  
  if (flowId.value) {
    loadFlow()
  } else {
    // Add default trigger for new flow
    addNode('trigger')
  }
})

async function loadFlow() {
  const flow = await flowsStore.fetchFlow(flowId.value)
  if (flow) {
    flowName.value = flow.name
    flowSlug.value = flow.slug
    nodes.value = flow.dag?.nodes?.map(n => ({
      id: n.id,
      type: n.node_type,
      label: n.config?.label || n.node_type,
      agentId: n.agent_id,
      config: n.config,
      x: n.position_x || 100,
      y: n.position_y || 100
    })) || []
    edges.value = flow.dag?.edges?.map(e => ({
      id: e.id,
      source: e.source_node_id,
      target: e.target_node_id,
      condition: e.condition
    })) || []
  }
}

function addNode(type) {
  const id = `node_${Date.now()}`
  const node = {
    id,
    type,
    label: type.charAt(0).toUpperCase() + type.slice(1),
    config: {},
    x: 100 + nodes.value.length * 50,
    y: 100 + nodes.value.length * 30
  }
  if (type === 'agent') {
    node.agentId = ''
  }
  if (type === 'condition') {
    node.condition = ''
  }
  nodes.value.push(node)
}

function selectNode(node) {
  selectedNode.value = node
}

function deselectAll() {
  selectedNode.value = null
}

function deleteSelectedNode() {
  if (!selectedNode.value) return
  const nodeId = selectedNode.value.id
  nodes.value = nodes.value.filter(n => n.id !== nodeId)
  edges.value = edges.value.filter(e => e.source !== nodeId && e.target !== nodeId)
  selectedNode.value = null
}

function getNodeIcon(type) {
  const icons = { trigger: '⚡', agent: '🤖', condition: '❓', action: '⚙️', output: '📤' }
  return icons[type] || '⬜'
}

function getEdgePath(edge) {
  const source = nodes.value.find(n => n.id === edge.source)
  const target = nodes.value.find(n => n.id === edge.target)
  if (!source || !target) return ''
  
  const sx = source.x + 180
  const sy = source.y + 40
  const tx = target.x
  const ty = target.y + 40
  
  return `M ${sx} ${sy} C ${sx + 50} ${sy}, ${tx - 50} ${ty}, ${tx} ${ty}`
}

// Drag functionality
let draggedNode = null
let dragOffset = { x: 0, y: 0 }

function startDrag(e, node) {
  draggedNode = node
  dragOffset.x = e.clientX - node.x
  dragOffset.y = e.clientY - node.y
  
  document.addEventListener('mousemove', onDrag)
  document.addEventListener('mouseup', stopDrag)
}

function onDrag(e) {
  if (!draggedNode) return
  draggedNode.x = e.clientX - dragOffset.x
  draggedNode.y = e.clientY - dragOffset.y
}

function stopDrag() {
  draggedNode = null
  document.removeEventListener('mousemove', onDrag)
  document.removeEventListener('mouseup', stopDrag)
}

// Connection functionality (simplified)
function startConnection(e, node, type) {
  // Would implement connection dragging here
  console.log('Start connection from', node.id, type)
}

async function saveFlow() {
  isSaving.value = true
  saveStatus.value = null
  
  const flowData = {
    name: flowName.value,
    slug: flowSlug.value,
    description: '',
    dag: {
      nodes: nodes.value.map(n => ({
        id: n.id,
        node_type: n.type,
        agent_id: n.agentId,
        config: { ...n.config, label: n.label },
        position_x: n.x,
        position_y: n.y
      })),
      edges: edges.value.map(e => ({
        id: e.id,
        source_node_id: e.source,
        target_node_id: e.target,
        condition: e.condition
      }))
    },
    status: 'draft'
  }
  
  try {
    if (isNew.value) {
      const result = await flowsStore.createFlow(flowData)
      if (result.success) {
        router.push(`/flows/${result.flow.id}`)
      }
    } else {
      await flowsStore.updateFlow(flowId.value, flowData)
    }
    saveStatus.value = { type: 'success', message: 'Saved!' }
  } catch (err) {
    saveStatus.value = { type: 'error', message: 'Failed to save' }
  } finally {
    isSaving.value = false
    setTimeout(() => saveStatus.value = null, 3000)
  }
}

async function publishFlow() {
  await saveFlow()
  if (!isNew.value) {
    isPublishing.value = true
    await flowsStore.publishFlow(flowId.value)
    isPublishing.value = false
  }
}
</script>
