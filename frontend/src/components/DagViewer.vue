<template>
  <div class="dag-viewer relative w-full overflow-auto bg-gray-50 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700">
    <svg
      :width="svgWidth"
      :height="svgHeight"
      :viewBox="`0 0 ${svgWidth} ${svgHeight}`"
      class="dag-svg"
    >
      <!-- Defs: arrow marker -->
      <defs>
        <marker
          id="arrowhead"
          markerWidth="10"
          markerHeight="7"
          refX="10"
          refY="3.5"
          orient="auto"
          markerUnits="strokeWidth"
        >
          <polygon
            points="0 0, 10 3.5, 0 7"
            class="fill-gray-400 dark:fill-gray-500"
          />
        </marker>
      </defs>

      <!-- Edges -->
      <g class="dag-edges">
        <path
          v-for="edge in computedEdges"
          :key="`${edge.from}-${edge.to}`"
          :d="edge.path"
          fill="none"
          stroke-width="2"
          marker-end="url(#arrowhead)"
          class="stroke-gray-400 dark:stroke-gray-500"
        />
      </g>

      <!-- Nodes -->
      <g
        v-for="node in layoutNodes"
        :key="node.id"
        class="dag-node cursor-pointer"
        :transform="`translate(${node.x}, ${node.y})`"
        @click="$emit('node-click', node.id)"
      >
        <!-- Background rect -->
        <rect
          :width="nodeWidth"
          :height="nodeHeight"
          rx="8"
          ry="8"
          :class="nodeRectClasses(node)"
        />

        <!-- Pulse animation for running nodes -->
        <rect
          v-if="getNodeStatus(node.id) === 'running'"
          :width="nodeWidth"
          :height="nodeHeight"
          rx="8"
          ry="8"
          class="fill-transparent stroke-blue-400 stroke-2 animate-pulse"
        />

        <!-- Type icon (emoji shorthand) -->
        <text
          :x="16"
          :y="nodeHeight / 2 + 1"
          dominant-baseline="middle"
          class="text-base select-none"
        >
          {{ nodeTypeIcon(node.type) }}
        </text>

        <!-- Label -->
        <text
          :x="38"
          :y="nodeHeight / 2 + 1"
          dominant-baseline="middle"
          class="text-sm font-medium"
          :class="nodeLabelClasses(node)"
        >
          {{ truncateLabel(node.label || node.id) }}
        </text>

        <!-- Status indicator dot -->
        <circle
          :cx="nodeWidth - 16"
          :cy="nodeHeight / 2"
          r="5"
          :class="statusDotClasses(node)"
        />
      </g>
    </svg>
  </div>
</template>

<script setup>
import { computed } from 'vue'

// -------------------------------------------------------------------------- //
//  Props & Emits
// -------------------------------------------------------------------------- //

const props = defineProps({
  /** Array of node objects: { id, label, type } */
  nodes: {
    type: Array,
    required: true,
    default: () => [],
  },
  /** Array of edge objects: { from, to } */
  edges: {
    type: Array,
    required: true,
    default: () => [],
  },
  /**
   * Optional map of nodeId -> status string.
   * Recognised statuses: pending, running, completed, failed, waiting_approval.
   */
  nodeStates: {
    type: Object,
    default: () => ({}),
  },
})

defineEmits(['node-click'])

// -------------------------------------------------------------------------- //
//  Layout constants
// -------------------------------------------------------------------------- //

const nodeWidth = 200
const nodeHeight = 48
const horizontalGap = 80
const verticalGap = 32
const paddingX = 40
const paddingY = 40

// -------------------------------------------------------------------------- //
//  Topological layout (left-to-right)
// -------------------------------------------------------------------------- //

/**
 * Compute the topological depth (column) for every node so that each node
 * appears to the right of all its dependencies.
 */
const depthMap = computed(() => {
  const depths = {}
  const adjacency = {}

  // Build adjacency list (from -> [to]).
  for (const node of props.nodes) {
    adjacency[node.id] = []
    depths[node.id] = 0
  }
  for (const edge of props.edges) {
    if (adjacency[edge.from]) {
      adjacency[edge.from].push(edge.to)
    }
  }

  // Build reverse adjacency (to -> [from]).
  const reverseAdj = {}
  for (const node of props.nodes) {
    reverseAdj[node.id] = []
  }
  for (const edge of props.edges) {
    if (reverseAdj[edge.to]) {
      reverseAdj[edge.to].push(edge.from)
    }
  }

  // Kahn's algorithm for topological ordering.
  const inDegree = {}
  for (const node of props.nodes) {
    inDegree[node.id] = (reverseAdj[node.id] || []).length
  }

  const queue = Object.keys(inDegree).filter((id) => inDegree[id] === 0)

  while (queue.length > 0) {
    const current = queue.shift()
    for (const neighbor of adjacency[current] || []) {
      const candidateDepth = depths[current] + 1
      if (candidateDepth > depths[neighbor]) {
        depths[neighbor] = candidateDepth
      }
      inDegree[neighbor]--
      if (inDegree[neighbor] === 0) {
        queue.push(neighbor)
      }
    }
  }

  return depths
})

/**
 * Position each node based on its topological column and its index within
 * that column.
 */
const layoutNodes = computed(() => {
  const columns = {}

  for (const node of props.nodes) {
    const col = depthMap.value[node.id] ?? 0
    if (!columns[col]) columns[col] = []
    columns[col].push(node)
  }

  const positioned = []
  for (const [col, nodesInCol] of Object.entries(columns)) {
    const colIndex = Number(col)
    nodesInCol.forEach((node, rowIndex) => {
      positioned.push({
        ...node,
        x: paddingX + colIndex * (nodeWidth + horizontalGap),
        y: paddingY + rowIndex * (nodeHeight + verticalGap),
      })
    })
  }

  return positioned
})

/** Map of nodeId -> { x, y } for quick lookup. */
const nodePositions = computed(() => {
  const map = {}
  for (const node of layoutNodes.value) {
    map[node.id] = { x: node.x, y: node.y }
  }
  return map
})

// -------------------------------------------------------------------------- //
//  Edge paths
// -------------------------------------------------------------------------- //

const computedEdges = computed(() => {
  return props.edges.map((edge) => {
    const from = nodePositions.value[edge.from]
    const to = nodePositions.value[edge.to]

    if (!from || !to) return { ...edge, path: '' }

    // Start at the right-centre of the source node.
    const x1 = from.x + nodeWidth
    const y1 = from.y + nodeHeight / 2

    // End at the left-centre of the target node.
    const x2 = to.x
    const y2 = to.y + nodeHeight / 2

    // Cubic Bézier for a smooth curve.
    const cpOffset = (x2 - x1) / 2
    const path = `M ${x1} ${y1} C ${x1 + cpOffset} ${y1}, ${x2 - cpOffset} ${y2}, ${x2} ${y2}`

    return { ...edge, path }
  })
})

// -------------------------------------------------------------------------- //
//  SVG dimensions
// -------------------------------------------------------------------------- //

const svgWidth = computed(() => {
  if (layoutNodes.value.length === 0) return 400
  const maxX = Math.max(...layoutNodes.value.map((n) => n.x))
  return maxX + nodeWidth + paddingX * 2
})

const svgHeight = computed(() => {
  if (layoutNodes.value.length === 0) return 200
  const maxY = Math.max(...layoutNodes.value.map((n) => n.y))
  return maxY + nodeHeight + paddingY * 2
})

// -------------------------------------------------------------------------- //
//  Helpers
// -------------------------------------------------------------------------- //

function getNodeStatus(nodeId) {
  return props.nodeStates[nodeId] || 'pending'
}

function nodeRectClasses(node) {
  const status = getNodeStatus(node.id)
  const base = 'stroke-2 transition-colors duration-200'
  const map = {
    pending: 'fill-gray-100 dark:fill-gray-800 stroke-gray-300 dark:stroke-gray-600',
    running: 'fill-blue-50 dark:fill-blue-900/30 stroke-blue-400 dark:stroke-blue-500',
    completed: 'fill-green-50 dark:fill-green-900/30 stroke-green-400 dark:stroke-green-500',
    failed: 'fill-red-50 dark:fill-red-900/30 stroke-red-400 dark:stroke-red-500',
    waiting_approval: 'fill-amber-50 dark:fill-amber-900/30 stroke-amber-400 dark:stroke-amber-500',
  }
  return `${base} ${map[status] || map.pending}`
}

function nodeLabelClasses(node) {
  const status = getNodeStatus(node.id)
  const map = {
    pending: 'fill-gray-600 dark:fill-gray-300',
    running: 'fill-blue-700 dark:fill-blue-300',
    completed: 'fill-green-700 dark:fill-green-300',
    failed: 'fill-red-700 dark:fill-red-300',
    waiting_approval: 'fill-amber-700 dark:fill-amber-300',
  }
  return map[status] || map.pending
}

function statusDotClasses(node) {
  const status = getNodeStatus(node.id)
  const map = {
    pending: 'fill-gray-400',
    running: 'fill-blue-500 animate-pulse',
    completed: 'fill-green-500',
    failed: 'fill-red-500',
    waiting_approval: 'fill-amber-500 animate-pulse',
  }
  return map[status] || map.pending
}

function nodeTypeIcon(type) {
  const icons = {
    agent: '\u{1F916}',        // robot
    llm: '\u{1F9E0}',          // brain
    tool: '\u{1F527}',         // wrench
    approval: '\u{1F6D1}',     // stop sign
    transform: '\u{1F504}',    // arrows cycle
    condition: '\u{2696}',     // scales
    webhook: '\u{1F310}',      // globe
    notify: '\u{1F514}',       // bell
  }
  return icons[type] || '\u{25C6}' // diamond fallback
}

function truncateLabel(label, maxLength = 18) {
  return label.length > maxLength ? label.slice(0, maxLength - 1) + '\u2026' : label
}
</script>

<style scoped>
.dag-viewer {
  min-height: 200px;
}

.dag-node:hover rect:first-child {
  filter: brightness(0.95);
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}
</style>
