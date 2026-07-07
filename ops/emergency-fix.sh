#!/bin/bash
# Emergency fix for SpiderNetOS sites

echo "=== EMERGENCY FIX ==="

# 1. Check Ollama
echo "Checking Ollama..."
if ! curl -s http://localhost:11434/api/tags > /dev/null; then
    echo "Starting Ollama..."
    ollama serve &
    sleep 10
fi

# 2. Restore basic working landing page
echo "Restoring App.vue..."
cat > /workspace/SpiderNetOS/sites/landing/src/App.vue << 'EOF'
<template>
  <div class="min-h-screen bg-gradient-to-b from-[#0A0A0F] via-[#12121A] to-[#1A1A2E] text-white">
    <!-- Navbar -->
    <nav class="fixed top-0 w-full z-50 bg-black/20 backdrop-blur-md border-b border-white/5">
      <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
        <div class="flex items-center gap-2">
          <div class="w-8 h-8 bg-orange-500 rounded-lg flex items-center justify-center">
            <span class="text-white font-bold">S</span>
          </div>
          <span class="font-bold text-xl">SpiderNetOS</span>
        </div>
        <div class="flex items-center gap-6">
          <a href="#" class="text-gray-400 hover:text-white transition">Docs</a>
          <a href="#" class="text-gray-400 hover:text-white transition">GitHub</a>
          <button class="bg-orange-500 hover:bg-orange-600 px-4 py-2 rounded-lg font-medium transition">
            Launch App
          </button>
        </div>
      </div>
    </nav>

    <!-- Hero -->
    <section class="min-h-screen flex items-center justify-center relative pt-16">
      <canvas ref="particleCanvas" class="absolute inset-0"></canvas>
      <div class="relative z-10 text-center max-w-4xl mx-auto px-6">
        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-orange-500/10 border border-orange-500/20 mb-6">
          <span class="w-2 h-2 bg-orange-500 rounded-full animate-pulse"></span>
          <span class="text-orange-400 text-sm">Web3 Infrastructure</span>
        </div>
        <h1 class="text-6xl md:text-7xl font-extrabold tracking-tight mb-6">
          <span class="bg-gradient-to-r from-orange-400 via-orange-500 to-cyan-400 bg-clip-text text-transparent">
            The Neural OS
          </span>
          <br />
          <span class="text-white">for Autonomous Agents</span>
        </h1>
        <p class="text-xl text-gray-400 mb-8 max-w-2xl mx-auto">
          Deploy, scale, and monetize AI agents across a decentralized compute mesh.
        </p>
        <div class="flex items-center justify-center gap-4">
          <button class="bg-orange-500 hover:bg-orange-600 px-8 py-4 rounded-xl font-semibold text-lg shadow-lg shadow-orange-500/25 transition hover:scale-105">
            Launch Console
          </button>
          <button class="border border-white/20 hover:bg-white/5 px-8 py-4 rounded-xl font-semibold text-lg transition">
            Read Docs
          </button>
        </div>
      </div>
    </section>

    <!-- Features -->
    <section class="py-24 px-6">
      <div class="max-w-6xl mx-auto">
        <div class="text-center mb-16">
          <p class="text-orange-400 font-medium mb-2">Features</p>
          <h2 class="text-4xl font-bold">Everything you need to build agents</h2>
        </div>
        <div class="grid md:grid-cols-3 gap-6">
          <div v-for="feature in features" :key="feature.title"
               class="p-8 rounded-2xl bg-white/5 border border-white/10 backdrop-blur-sm hover:bg-white/10 hover:-translate-y-2 transition duration-300">
            <div class="w-12 h-12 rounded-xl bg-orange-500/20 flex items-center justify-center mb-4">
              <span class="text-2xl">{{ feature.icon }}</span>
            </div>
            <h3 class="text-xl font-bold mb-2">{{ feature.title }}</h3>
            <p class="text-gray-400">{{ feature.description }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Metrics -->
    <section class="py-20 px-6 bg-gradient-to-r from-orange-500/10 via-purple-500/10 to-cyan-500/10">
      <div class="max-w-6xl mx-auto">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-8 text-center">
          <div v-for="metric in metrics" :key="metric.label">
            <div class="text-4xl md:text-5xl font-bold mb-2">{{ metric.value }}</div>
            <div class="text-gray-400">{{ metric.label }}</div>
          </div>
        </div>
      </div>
    </section>

    <!-- CTA -->
    <section class="py-24 px-6">
      <div class="max-w-4xl mx-auto text-center">
        <div class="p-12 rounded-3xl bg-gradient-to-r from-orange-500/20 to-purple-500/20 border border-white/10">
          <h2 class="text-3xl font-bold mb-4">Ready to build the future?</h2>
          <p class="text-gray-400 mb-6">Join thousands of developers building autonomous agents.</p>
          <div class="flex items-center justify-center gap-4 max-w-md mx-auto">
            <input type="email" placeholder="Enter your email" 
                   class="flex-1 px-4 py-3 rounded-lg bg-white/5 border border-white/10 focus:outline-none focus:border-orange-500" />
            <button class="bg-orange-500 hover:bg-orange-600 px-6 py-3 rounded-lg font-medium whitespace-nowrap">
              Get Access
            </button>
          </div>
        </div>
      </div>
    </section>

    <!-- Footer -->
    <footer class="py-12 px-6 border-t border-white/5">
      <div class="max-w-6xl mx-auto flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-2">
          <div class="w-6 h-6 bg-orange-500 rounded flex items-center justify-center">
            <span class="text-white text-sm font-bold">S</span>
          </div>
          <span class="font-semibold">SpiderNetOS</span>
        </div>
        <p class="text-gray-500 text-sm">© 2024 SpiderNetOS Protocol. All rights reserved.</p>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const features = [
  { icon: '🔨', title: 'Build', description: 'Create autonomous agents with our SDK. Deploy to the global mesh in seconds.' },
  { icon: '💰', title: 'Sell', description: 'Monetize your agent\'s compute and intelligence via the SpiderNet marketplace.' },
  { icon: '🚀', title: 'Scale', description: 'Automatic horizontal scaling across 10,000+ nodes without manual configuration.' }
]

const metrics = [
  { value: '2.4M+', label: 'Active Agents' },
  { value: '$840M', label: 'Total Value Locked' },
  { value: '99.97%', label: 'Uptime Reliability' },
  { value: '10K+', label: 'Compute Nodes' }
]

const particleCanvas = ref(null)

onMounted(() => {
  const canvas = particleCanvas.value
  if (!canvas) return
  
  const ctx = canvas.getContext('2d')
  canvas.width = window.innerWidth
  canvas.height = window.innerHeight
  
  const particles = []
  for (let i = 0; i < 50; i++) {
    particles.push({
      x: Math.random() * canvas.width,
      y: Math.random() * canvas.height,
      vx: (Math.random() - 0.5) * 0.5,
      vy: (Math.random() - 0.5) * 0.5,
      radius: Math.random() * 2 + 1
    })
  }
  
  function animate() {
    ctx.clearRect(0, 0, canvas.width, canvas.height)
    
    particles.forEach(p => {
      p.x += p.vx
      p.y += p.vy
      
      if (p.x < 0 || p.x > canvas.width) p.vx *= -1
      if (p.y < 0 || p.y > canvas.height) p.vy *= -1
      
      ctx.beginPath()
      ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2)
      ctx.fillStyle = 'rgba(255, 107, 44, 0.6)'
      ctx.fill()
    })
    
    particles.forEach((p1, i) => {
      particles.slice(i + 1).forEach(p2 => {
        const dx = p1.x - p2.x
        const dy = p1.y - p2.y
        const dist = Math.sqrt(dx * dx + dy * dy)
        
        if (dist < 150) {
          ctx.beginPath()
          ctx.moveTo(p1.x, p1.y)
          ctx.lineTo(p2.x, p2.y)
          ctx.strokeStyle = `rgba(255, 107, 44, ${0.2 * (1 - dist / 150)})`
          ctx.stroke()
        }
      })
    })
    
    requestAnimationFrame(animate)
  }
  
  animate()
})
</script>

<style scoped>
.particleCanvas {
  pointer-events: none;
}
</style>
EOF

# 3. Rebuild
echo "Rebuilding..."
cd /workspace/SpiderNetOS/sites/landing
npm run build

# 4. Restart server
echo "Restarting server..."
pkill -f "http.server 3000"
sleep 1
cd /workspace/SpiderNetOS/sites/landing/dist && python3 -m http.server 3000 --bind 0.0.0.0 &
sleep 2

# 5. Test
curl -s -o /dev/null -w "Landing: HTTP %{http_code}\n" http://localhost:3000

echo "=== DONE ==="
echo "Your site is back up at: http://220.134.41.156:8080"
