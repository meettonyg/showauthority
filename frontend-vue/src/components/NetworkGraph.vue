<template>
  <div class="network-graph" ref="containerRef">
    <svg :width="width" :height="height">
      <!-- Connection Lines -->
      <g class="links">
        <line
          v-for="link in links"
          :key="`${link.source.id}-${link.target.id}`"
          :x1="link.source.x"
          :y1="link.source.y"
          :x2="link.target.x"
          :y2="link.target.y"
          :class="['link', `degree-${link.degree}`]"
        />
      </g>

      <!-- Node Groups -->
      <g class="nodes">
        <g
          v-for="node in nodes"
          :key="node.id"
          :transform="`translate(${node.x}, ${node.y})`"
          :class="['node', { center: node.isCenter, 'first-degree': node.degree === 1, 'second-degree': node.degree === 2 }]"
          @click="handleNodeClick(node)"
        >
          <!-- Node Circle -->
          <circle
            :r="node.isCenter ? 30 : (node.degree === 1 ? 22 : 16)"
            :class="['node-circle', { verified: node.is_verified }]"
          />

          <!-- Avatar or Initials -->
          <clipPath :id="`clip-${node.id}`">
            <circle :r="node.isCenter ? 28 : (node.degree === 1 ? 20 : 14)" />
          </clipPath>

          <image
            v-if="node.avatar_url"
            :href="node.avatar_url"
            :x="-(node.isCenter ? 28 : (node.degree === 1 ? 20 : 14))"
            :y="-(node.isCenter ? 28 : (node.degree === 1 ? 20 : 14))"
            :width="(node.isCenter ? 56 : (node.degree === 1 ? 40 : 28))"
            :height="(node.isCenter ? 56 : (node.degree === 1 ? 40 : 28))"
            :clip-path="`url(#clip-${node.id})`"
          />

          <text
            v-else
            class="node-initials"
            text-anchor="middle"
            dominant-baseline="central"
            :font-size="node.isCenter ? 14 : (node.degree === 1 ? 10 : 8)"
          >
            {{ node.initials }}
          </text>

          <!-- Verified Badge -->
          <g v-if="node.is_verified" :transform="`translate(${node.isCenter ? 20 : (node.degree === 1 ? 14 : 10)}, ${node.isCenter ? -20 : (node.degree === 1 ? -14 : -10)})`">
            <circle r="8" fill="#10b981" />
            <path d="M-3 0 L-1 2 L3 -2" fill="none" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
          </g>

          <!-- Node Label -->
          <text
            :y="node.isCenter ? 45 : (node.degree === 1 ? 35 : 28)"
            class="node-label"
            text-anchor="middle"
            :font-size="node.isCenter ? 12 : 10"
          >
            {{ truncateName(node.name, node.isCenter ? 20 : 15) }}
          </text>

          <!-- Connection Type Label -->
          <text
            v-if="!node.isCenter && node.connection_type"
            :y="node.isCenter ? 58 : (node.degree === 1 ? 47 : 38)"
            class="connection-label"
            text-anchor="middle"
            font-size="8"
          >
            {{ node.connection_type }}
          </text>
        </g>
      </g>
    </svg>

    <!-- Legend -->
    <div class="graph-legend">
      <div class="legend-item">
        <span class="legend-dot center"></span>
        <span>Current Guest</span>
      </div>
      <div class="legend-item">
        <span class="legend-dot first"></span>
        <span>1st Degree</span>
      </div>
      <div class="legend-item">
        <span class="legend-dot second"></span>
        <span>2nd Degree</span>
      </div>
    </div>

    <!-- Empty State -->
    <div v-if="!connections || connections.length === 0" class="empty-graph">
      <p>No network connections to display</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter } from 'vue-router'

const props = defineProps({
  connections: {
    type: Array,
    default: () => []
  },
  centerGuest: {
    type: Object,
    required: true
  }
})

const router = useRouter()
const containerRef = ref(null)
const width = ref(600)
const height = ref(300)

// Compute nodes and links from connections data
const nodes = computed(() => {
  if (!props.centerGuest) return []

  const nodeList = []
  const centerX = width.value / 2
  const centerY = height.value / 2

  // Add center node
  nodeList.push({
    id: props.centerGuest.id,
    name: props.centerGuest.full_name,
    initials: getInitials(props.centerGuest.full_name),
    avatar_url: props.centerGuest.avatar_url,
    is_verified: props.centerGuest.is_verified,
    isCenter: true,
    degree: 0,
    x: centerX,
    y: centerY
  })

  if (!props.connections || props.connections.length === 0) {
    return nodeList
  }

  // Separate by degree
  const firstDegree = props.connections.filter(c => c.degree === 1)
  const secondDegree = props.connections.filter(c => c.degree === 2)

  // Position first-degree connections in inner circle
  const innerRadius = Math.min(width.value, height.value) * 0.25
  firstDegree.forEach((conn, index) => {
    const angle = (2 * Math.PI * index) / firstDegree.length - Math.PI / 2
    nodeList.push({
      id: conn.connected_guest_id,
      name: conn.connected_guest_name,
      initials: getInitials(conn.connected_guest_name),
      avatar_url: conn.connected_guest_avatar,
      is_verified: conn.is_verified,
      isCenter: false,
      degree: 1,
      connection_type: conn.connection_type,
      x: centerX + innerRadius * Math.cos(angle),
      y: centerY + innerRadius * Math.sin(angle)
    })
  })

  // Position second-degree connections in outer circle
  const outerRadius = Math.min(width.value, height.value) * 0.4
  secondDegree.forEach((conn, index) => {
    const angle = (2 * Math.PI * index) / secondDegree.length - Math.PI / 2 + (Math.PI / secondDegree.length)
    nodeList.push({
      id: conn.connected_guest_id,
      name: conn.connected_guest_name,
      initials: getInitials(conn.connected_guest_name),
      avatar_url: conn.connected_guest_avatar,
      is_verified: conn.is_verified,
      isCenter: false,
      degree: 2,
      connection_type: conn.connection_type,
      x: centerX + outerRadius * Math.cos(angle),
      y: centerY + outerRadius * Math.sin(angle)
    })
  })

  return nodeList
})

const links = computed(() => {
  if (!props.connections || props.connections.length === 0) return []

  const linkList = []
  const centerNode = nodes.value.find(n => n.isCenter)

  if (!centerNode) return []

  // Create links from connections
  props.connections.forEach(conn => {
    const targetNode = nodes.value.find(n => n.id === conn.connected_guest_id)
    if (targetNode) {
      // For 1st degree, connect to center
      if (conn.degree === 1) {
        linkList.push({
          source: centerNode,
          target: targetNode,
          degree: 1
        })
      }
      // For 2nd degree, connect to the intermediary 1st degree if available
      // Otherwise connect to center
      else if (conn.degree === 2) {
        const intermediary = nodes.value.find(n =>
          n.degree === 1 && conn.via_guest_id === n.id
        )
        linkList.push({
          source: intermediary || centerNode,
          target: targetNode,
          degree: 2
        })
      }
    }
  })

  return linkList
})

function getInitials(name) {
  if (!name) return '??'
  const parts = name.split(' ')
  if (parts.length >= 2) {
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
  }
  return name.substring(0, 2).toUpperCase()
}

function truncateName(name, maxLength) {
  if (!name) return ''
  return name.length > maxLength ? name.substring(0, maxLength) + '...' : name
}

function handleNodeClick(node) {
  if (!node.isCenter) {
    router.push(`/guests/${node.id}`)
  }
}

function updateDimensions() {
  if (containerRef.value) {
    width.value = containerRef.value.clientWidth
    height.value = Math.max(300, containerRef.value.clientHeight)
  }
}

onMounted(() => {
  updateDimensions()
  window.addEventListener('resize', updateDimensions)
})

onUnmounted(() => {
  window.removeEventListener('resize', updateDimensions)
})

watch(() => props.connections, () => {
  // Force recalculation when connections change
}, { deep: true })
</script>

<style scoped>
.network-graph {
  position: relative;
  width: 100%;
  height: 100%;
  min-height: 300px;
  background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
  border-radius: 10px;
  overflow: hidden;
}

svg {
  display: block;
}

/* Links */
.link {
  stroke: #cbd5e1;
  stroke-width: 2;
  stroke-opacity: 0.6;
}

.link.degree-1 {
  stroke: #667eea;
  stroke-width: 2.5;
  stroke-opacity: 0.8;
}

.link.degree-2 {
  stroke: #94a3b8;
  stroke-width: 1.5;
  stroke-dasharray: 4 2;
}

/* Nodes */
.node {
  cursor: pointer;
  transition: transform 0.2s ease;
}

.node:not(.center):hover {
  transform: scale(1.1);
}

.node-circle {
  fill: #f1f5f9;
  stroke: #cbd5e1;
  stroke-width: 2;
  transition: all 0.2s ease;
}

.node.center .node-circle {
  fill: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  fill: #667eea;
  stroke: #5568d3;
  stroke-width: 3;
}

.node.first-degree .node-circle {
  fill: #e0e7ff;
  stroke: #667eea;
}

.node.second-degree .node-circle {
  fill: #f1f5f9;
  stroke: #94a3b8;
}

.node:hover .node-circle {
  stroke-width: 3;
  filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.15));
}

.node-circle.verified {
  stroke: #10b981;
}

/* Node Text */
.node-initials {
  fill: #667eea;
  font-weight: 600;
  pointer-events: none;
}

.node.center .node-initials {
  fill: white;
}

.node-label {
  fill: #1e293b;
  font-weight: 500;
  pointer-events: none;
}

.connection-label {
  fill: #64748b;
  pointer-events: none;
}

/* Legend */
.graph-legend {
  position: absolute;
  bottom: 10px;
  left: 10px;
  display: flex;
  gap: 1rem;
  padding: 0.5rem 0.75rem;
  background: rgba(255, 255, 255, 0.9);
  border-radius: 6px;
  font-size: 0.75rem;
}

.legend-item {
  display: flex;
  align-items: center;
  gap: 0.375rem;
  color: #64748b;
}

.legend-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
}

.legend-dot.center {
  background: #667eea;
}

.legend-dot.first {
  background: #e0e7ff;
  border: 2px solid #667eea;
}

.legend-dot.second {
  background: #f1f5f9;
  border: 2px solid #94a3b8;
}

/* Empty State */
.empty-graph {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  text-align: center;
  color: #94a3b8;
}
</style>
