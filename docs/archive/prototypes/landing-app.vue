<template>
  <div class="min-h-screen bg-[#0A0A0F] text-[#E8E8EF] font-sans overflow-x-hidden">
    <!-- Hero -->
    <section class="relative h-screen flex items-center justify-center">
      <canvas ref="heroCanvas" class="absolute inset-0 w-full h-full opacity-40"></canvas>
      <div class="relative z-10 text-center px-4 max-w-4xl">
        <h1 class="text-5xl md:text-7xl font-bold mb-6 bg-gradient-to-r from-[#FF6B2C] to-[#FF8C42] bg-clip-text text-transparent">
          SpiderNetOS
        </h1>
        <p class="text-xl md:text-2xl text-[#8A8A95] mb-8">
          What if your business didn't scale linearly—but autonomously?
        </p>
        <button
          @click="scrollToDemo"
          class="px-8 py-4 bg-[#FF6B2C] hover:bg-[#FF8C42] rounded-lg font-semibold transition-all shadow-[0_0_20px_rgba(255,107,44,0.3)]"
        >
          Run Your First Simulation
        </button>
      </div>
    </section>

    <!-- Three Zones -->
    <section class="py-20 px-4">
      <div class="max-w-6xl mx-auto grid md:grid-cols-3 gap-8">
        <div
          v-for="zone in zones"
          :key="zone.name"
          @click="zone.expanded = !zone.expanded"
          class="bg-[#12121A] border border-white/5 rounded-xl p-8 cursor-pointer transition-all hover:-translate-y-1 hover:border-[#FF6B2C]/30"
        >
          <div class="text-4xl mb-4">{{ zone.icon }}</div>
          <h3 class="text-2xl font-bold text-[#FF6B2C] mb-2">{{ zone.name }}</h3>
          <p class="text-[#8A8A95]">{{ zone.desc }}</p>
          <div
            v-if="zone.expanded"
            class="mt-4 pt-4 border-t border-white/5 text-sm text-[#6B6B7B] animate-fade-in"
          >
            {{ zone.detail }}
          </div>
        </div>
      </div>
    </section>

    <!-- Metrics -->
    <section class="py-20 px-4 bg-[#12121A]/50">
      <div class="max-w-4xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-8 text-center">
        <div v-for="m in metrics" :key="m.label">
          <div class="text-4xl md:text-5xl font-bold text-[#FF6B2C] font-mono">{{ m.value }}</div>
          <div class="text-[#8A8A95] mt-2">{{ m.label }}</div>
        </div>
      </div>
    </section>

    <!-- Terminal Demo -->
    <section id="demo" class="py-20 px-4">
      <div class="max-w-3xl mx-auto">
        <div class="bg-[#12121A] border border-white/10 rounded-xl overflow-hidden">
          <div class="flex items-center gap-2 px-4 py-3 bg-[#1A1A24] border-b border-white/5">
            <div class="w-3 h-3 rounded-full bg-[#F44336]"></div>
            <div class="w-3 h-3 rounded-full bg-[#FFA726]"></div>
            <div class="w-3 h-3 rounded-full bg-[#00E5C8]"></div>
            <span class="ml-2 text-xs text-[#6B6B7B]">spidernet-os — simulation</span>
          </div>
          <div class="p-6 font-mono text-sm text-[#8A8A95] space-y-2">
            <div v-for="(line, i) in terminalLines" :key="i" :class="line.color">
              <span class="text-[#6B6B7B]">$</span> {{ line.text }}
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Footer -->
    <footer class="py-12 px-4 border-t border-white/5 text-center text-[#6B6B7B]">
      <p>SpiderNetOS v3.2 — Agent-Native Operating System</p>
      <div class="mt-4 space-x-6">
        <a href="#" class="hover:text-[#FF6B2C] transition-colors">Docs</a>
        <a href="#" class="hover:text-[#FF6B2C] transition-colors">GitHub</a>
        <a href="#" class="hover:text-[#FF6B2C] transition-colors">Status</a>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const heroCanvas = ref(null)

const zones = ref([
  {
    name: 'BUILD',
    icon: '⚡',
    desc: 'Create AI-driven systems',
    detail: 'Design autonomous agent swarms that handle complex business workflows without human intervention.',
    expanded: false
  },
  {
    name: 'SELL',
    icon: '💰',
    desc: 'Automate revenue generation',
    detail: 'Deploy sales agents that qualify leads, negotiate deals, and close contracts 24/7.',
    expanded: false
  },
  {
    name: 'SCALE',
    icon: '🚀',
    desc: 'Deploy autonomous operations',
    detail: 'Infrastructure that self-heals, auto-scales, and optimizes costs in real-time.',
    expanded: false
  }
])

const metrics = ref([
  { value: '2.4M', label: 'Agents Deployed' },
  { value: '$840M', label: 'Revenue Automated' },
  { value: '99.97%', label: 'Uptime' }
])

const terminalLines = ref([
  { text: 'Initializing SpiderNetOS kernel...', color: 'text-[#00E5C8]' },
  { text: 'Loading agent swarm: 47 nodes', color: 'text-[#8A8A95]' },
  { text: 'Connecting to inference plane... OK', color: 'text-[#00E5C8]' },
  { text: 'Cost governor: budget $50.00/day', color: 'text-[#FFA726]' },
  { text: 'AtlasAgent ready. Awaiting command.', color: 'text-[#FF6B2C]' }
])

function scrollToDemo() {
  document.getElementById('demo')?.scrollIntoView({ behavior: 'smooth' })
}

onMounted(() => {
  const canvas = heroCanvas.value
  if (!canvas) return
  const ctx = canvas.getContext('2d')
  const resize = () => {
    canvas.width = window.innerWidth
    canvas.height = window.innerHeight
  }
  resize()
  window.addEventListener('resize', resize)

  const particles = Array.from({ length: 50 }, () => ({
    x: Math.random() * canvas.width,
    y: Math.random() * canvas.height,
    vx: (Math.random() - 0.5) * 0.5,
    vy: (Math.random() - 0.5) * 0.5
  }))

  function draw() {
    ctx.clearRect(0, 0, canvas.width, canvas.height)
    ctx.strokeStyle = 'rgba(255, 107, 44, 0.15)'
    ctx.lineWidth = 1

    particles.forEach((p, i) => {
      p.x += p.vx
      p.y += p.vy
      if (p.x < 0 || p.x > canvas.width) p.vx *= -1
      if (p.y < 0 || p.y > canvas.height) p.vy *= -1

      ctx.beginPath()
      ctx.arc(p.x, p.y, 2, 0, Math.PI * 2)
      ctx.fillStyle = '#FF6B2C'
      ctx.fill()

      particles.slice(i + 1).forEach(p2 => {
        const d = Math.hypot(p.x - p2.x, p.y - p2.y)
        if (d < 150) {
          ctx.beginPath()
          ctx.moveTo(p.x, p.y)
          ctx.lineTo(p2.x, p2.y)
          ctx.stroke()
        }
      })
    })
    requestAnimationFrame(draw)
  }
  draw()
})
</script>

<style>
@keyframes fade-in {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}
.animate-fade-in {
  animation: fade-in 0.3s ease-out;
}
</style>
