<template>
  <div class="px-6 py-7 max-w-6xl mx-auto" data-testid="ent-aios">
    <div class="mb-7">
      <div class="sn-eyebrow">Enterprise · AIOS Bundles</div>
      <h1 class="text-2xl font-semibold tracking-tight mt-1" style="color: var(--text-primary);">
        AIOS Downloads
      </h1>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">
        Generate, verify, and lifecycle-manage signed AIOS bundles for your tenants.
      </p>
    </div>

    <div class="grid lg:grid-cols-3 gap-5">
      <!-- New bundle -->
      <div class="sn-card p-5 lg:col-span-1">
        <h2 class="font-medium mb-4" style="color: var(--text-primary);">New bundle</h2>
        <label class="block mb-3">
          <div class="sn-label">Target</div>
          <select class="sn-input" v-model="target" data-testid="ent-aios-target">
            <option value="linux-x86_64">Linux x86_64</option>
            <option value="linux-arm64">Linux ARM64</option>
            <option value="windows-x86_64">Windows x86_64</option>
            <option value="docker">Docker</option>
          </select>
        </label>
        <div class="mb-4">
          <div class="sn-label">Components</div>
          <div class="flex flex-wrap gap-2 mt-1">
            <button
              v-for="c in allComponents"
              :key="c"
              type="button"
              @click="toggleComponent(c)"
              class="px-3 py-1.5 rounded-full text-xs border transition-colors"
              :class="components.includes(c) ? 'is-on' : 'is-off'"
            >
              {{ c }}
            </button>
          </div>
        </div>
        <button
          @click="create"
          :disabled="busy"
          data-testid="ent-aios-create"
          class="sn-btn-primary w-full"
        >
          {{ busy ? 'Generating…' : 'Generate signed bundle' }}
        </button>
      </div>

      <!-- Bundles list -->
      <div class="lg:col-span-2 space-y-4">
        <div v-if="loading && !bundles.length" class="sn-card p-8 text-center" style="color: var(--text-muted);">
          Loading…
        </div>
        <div v-else-if="!bundles.length" class="sn-card p-10 text-center" style="color: var(--text-secondary);">
          No bundles yet. Create your first signed bundle on the left.
        </div>
        <div
          v-for="b in bundles"
          :key="b.bundle_id"
          class="sn-card p-5"
          :data-testid="`ent-bundle-${b.bundle_id}`"
        >
          <div class="flex items-start justify-between flex-wrap gap-3">
            <div>
              <div class="sn-label">Bundle ID</div>
              <div class="mono text-base mt-0.5" style="color: var(--accent);">{{ b.bundle_id }}</div>
              <div class="flex gap-2 flex-wrap mt-2">
                <span class="sn-pill sn-pill-success">Signed</span>
                <span class="sn-pill sn-pill-cyan">{{ b.target }}</span>
                <span v-for="c in (b.components || [])" :key="c" class="sn-pill">{{ c }}</span>
              </div>
            </div>
            <a
              class="sn-btn-primary"
              :href="downloadUrl(b)"
              target="_blank"
              rel="noopener"
            >
              ↓ Download
            </a>
          </div>
          <div class="mt-4 grid sm:grid-cols-2 gap-3 text-sm">
            <div class="kv"><span>SHA-256</span><code class="mono truncate">{{ b.sha256 }}</code></div>
            <div class="kv"><span>Signature</span><code class="mono truncate">{{ b.signature }}</code></div>
            <div class="kv"><span>Size</span><code class="mono">{{ (b.size_bytes / 1024).toFixed(1) }} KB</code></div>
            <div class="kv"><span>Expires</span><code class="mono">{{ formatDate(b.expires_at) }}</code></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Installer guide -->
    <div class="sn-card p-6 mt-7">
      <h2 class="font-medium mb-4" style="color: var(--text-primary);">Installer guide</h2>
      <div class="grid md:grid-cols-3 gap-4 mono text-xs">
        <div v-for="o in installers" :key="o.os" class="p-4 rounded-lg"
             style="background: var(--bg-subtle); border: 1px solid var(--divider);">
          <div class="mb-2" style="color: var(--accent); letter-spacing: 0.18em; font-size: 10px;">
            {{ o.os }}
          </div>
          <pre class="whitespace-pre-wrap leading-relaxed" style="color: var(--text-secondary);">{{ o.cmd }}</pre>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '../../services/api.js'
import { useAuthStore } from '../../stores/auth.js'

const authStore = useAuthStore()
const tenantId = computed(() => authStore.tenant?.id || 'tnt_demo')
const tenantSlug = computed(() => authStore.tenant?.slug || 'demo')

const target = ref('linux-x86_64')
const allComponents = ['runtime', 'connectors', 'cockpit-agent', 'observability']
const components = ref(['runtime', 'connectors', 'cockpit-agent'])
const bundles = ref([])
const busy = ref(false)
const loading = ref(true)

function toggleComponent(c) {
  const idx = components.value.indexOf(c)
  if (idx >= 0) components.value.splice(idx, 1)
  else components.value.push(c)
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get(`/api/enterprise/aios/bundles?tenant_id=${tenantId.value}`)
    bundles.value = data.data || []
  } finally {
    loading.value = false
  }
}

async function create() {
  busy.value = true
  try {
    await api.post('/api/enterprise/aios/bundle/create', {
      tenant_id: tenantId.value,
      target: target.value,
      components: components.value,
    })
    await load()
  } finally {
    busy.value = false
  }
}

function downloadUrl(b) {
  const base = (import.meta.env.VITE_API_URL || '').replace(/\/$/, '')
  return `${base}${b.download_path}`
}

function formatDate(iso) {
  try { return new Date(iso).toLocaleString() } catch { return iso }
}

const installers = computed(() => [
  {
    os: 'LINUX',
    cmd: `unzip bdl_*.zip\nsha256sum -c bdl_*.sha256\n./install.sh --tenant ${tenantSlug.value}`,
  },
  {
    os: 'WINDOWS',
    cmd: `Expand-Archive bdl_*.zip\nGet-FileHash bdl_*.zip\n.\\install.ps1 -Tenant ${tenantSlug.value}`,
  },
  {
    os: 'DOCKER',
    cmd: `docker load < aios-runtime.tar\ndocker run -d \\\n  -e SN_TENANT=${tenantSlug.value} \\\n  spidernetos/aios:latest`,
  },
])

onMounted(load)
</script>

<style scoped>
.sn-eyebrow { font-family: 'JetBrains Mono', monospace; font-size: 11px; letter-spacing: 0.18em; text-transform: uppercase; color: var(--accent); opacity: 0.85; }
.sn-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; }
.sn-label { font-family: 'JetBrains Mono', monospace; font-size: 11px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px; }
.sn-input { width: 100%; background: var(--bg-elevated); border: 1px solid var(--border); padding: 0.55rem 0.85rem; border-radius: 8px; color: var(--text-primary); outline: none; }
.sn-input:focus { border-color: var(--accent); }
.sn-btn-primary { background: var(--accent-warm); color: #fff; padding: 0.55rem 1.1rem; border-radius: 999px; font-weight: 500; font-size: 0.875rem; transition: filter .15s; display: inline-flex; align-items: center; gap: 6px; }
.sn-btn-primary:hover { filter: brightness(1.1); }
.sn-btn-primary:disabled { opacity: 0.55; cursor: not-allowed; }
.mono { font-family: 'JetBrains Mono', monospace; }
.is-on { border-color: rgba(0,214,201,0.6); background: rgba(0,214,201,0.1); color: var(--accent); }
.is-off { border-color: var(--border); color: var(--text-secondary); }
.is-off:hover { border-color: rgba(255,255,255,0.3); }
.kv { display: flex; flex-direction: column; gap: 2px; }
.kv > span { font-family: 'JetBrains Mono', monospace; font-size: 10px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-muted); }
.kv > code { color: var(--text-primary); }
.sn-pill { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 999px; font-family: 'JetBrains Mono', monospace; font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; border: 1px solid var(--border); color: var(--text-secondary); }
.sn-pill-success { color: var(--success); border-color: rgba(49,214,123,0.4); background: rgba(49,214,123,0.06); }
.sn-pill-cyan { color: var(--accent); border-color: rgba(0,214,201,0.4); background: rgba(0,214,201,0.06); }
</style>
