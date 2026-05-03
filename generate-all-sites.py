#!/usr/bin/env python3
"""
SpiderNetOS Multi-Site Generator - Self-Contained
No external prompts file needed. Run on Vast.ai instance.
"""
import asyncio
import base64
import json
import os
import time
import httpx

OLLAMA_URL = "http://localhost:11434"
MODEL = "gemma4:31b"
OUTPUT_BASE = "/workspace/SpiderNetOS/sites"
LOG_FILE = "/workspace/SpiderNetOS/logs/site-generation.log"

THEME = {
    "primary": "#FF6B2C", "secondary": "#FF8C42", "teal": "#00E5C8",
    "purple": "#B967FF", "bg": "#0A0A0F", "panel": "#12121A",
    "card": "#1A1A24", "text": "#E8E8EF"
}

DESIGN = f"""DARK THEME ONLY: bg {THEME['bg']}, cards {THEME['card']}, panels {THEME['panel']}
Colors: primary {THEME['primary']}, teal {THEME['teal']}, purple {THEME['purple']}
Font: Inter body, JetBrains Mono for data. Glassmorphism: blur(12px), bg-white/5.
GPU animations only (transform, opacity). No decorative elements."""


def log(msg):
    ts = time.strftime("%H:%M:%S")
    line = f"[{ts}] {msg}"
    print(line)
    os.makedirs(os.path.dirname(LOG_FILE), exist_ok=True)
    with open(LOG_FILE, "a") as f:
        f.write(line + "\n")


def clean(raw, ext):
    lines = raw.split("\n")
    s, e = 0, len(lines)
    for i, l in enumerate(lines):
        if l.strip().startswith("```"):
            s = i + 1
            break
    for i in range(len(lines) - 1, s - 1, -1):
        if lines[i].strip().startswith("```"):
            e = i
            break
    code = "\n".join(lines[s:e]).strip()
    if ext == ".vue" and not code.startswith(("<template>", "<script", "<style")):
        if "<div" in code:
            code = f"<template>\n{code}\n</template>"
    return code


async def gen(prompt, model=MODEL, t=300):
    system = f"""Expert Vue 3 + Tailwind CSS developer. {DESIGN}
Generate COMPLETE production code. Vue 3 Composition API <script setup>. Tailwind CSS.
ONLY code. No explanations, no markdown fences. Start immediately with <template>, import, or CSS."""

    async with httpx.AsyncClient(timeout=t) as c:
        try:
            r = await c.post(f"{OLLAMA_URL}/api/generate", json={
                "model": model, "prompt": f"{system}\n\n{prompt}\n\nGenerate ONLY code:",
                "stream": False, "options": {"temperature": 0.2, "num_predict": 4000}
            })
            r.raise_for_status()
            return r.json().get("response", "")
        except Exception as e:
            log(f"  ERROR: {e}")
            return ""


SITES = [
    {
        "name": "spidernetos.com",
        "dir": "/workspace/SpiderNetOS/sites/landing",
        "port": 3000,
        "pages": [
            ("App", "src/App.vue", """
Create Vue 3 single-page landing for 'SpiderNetOS - Agent-Native Operating System'.
HERO: Full-screen dark (#0A0A0F) with animated HTML5 canvas particle network (nodes connected by lines, subtle movement). Center: 'What if your business didn't scale linearly—but autonomously?' No navbar.
Scroll reveals 3 zones: BUILD 'Create AI systems', SELL 'Automate revenue', SCALE 'Deploy operations'. Each: parallax scroll, orange (#FF6B2C) glow on hover, click opens narrative detail (expandable section, not new page). 
Proof: animated counting metrics (2.4M agents, $840M automated, 99.97% uptime). 
CTA: 'Run Your First Simulation' button opens inline terminal demo (mock output animation).
Footer: minimal links. Tailwind CSS. Dark only. Mobile responsive.
"""),
        ]
    },
    {
        "name": "cockpit.spidernetos.com",
        "dir": "/workspace/SpiderNetOS/sites/customer-cockpit",
        "port": 3001,
        "pages": [
            ("Dashboard", "src/views/Dashboard.vue", """
Customer Dashboard for SpiderNetOS. Sidebar: Dashboard, Agents, Flows, Approvals, Traces, Usage, Memory, Settings.
Bento grid cards: SYSTEM STATE (active agents pulsing green dot, revenue sparkline SVG, workflow count, opportunities), AGENT SWARM (colored status dots by agent), REVENUE GRAPH (7-day SVG line chart orange gradient), QUICK ACTIONS (Approve, Deploy, View buttons), RECENT EVENTS (scrollable list). Dark #0F0F14. Cards #1A1A24, border-white/8. Orange #FF6B2C accents. JetBrains Mono for numbers. Mock data. Vue 3, Tailwind.
"""),
            ("AgentBuilder", "src/views/AgentBuilder.vue", """
Agent Builder. Split: Left canvas with central node + Trigger/Action/Memory/Output connected nodes. Animated SVG data flow. Right panel: Name input, Description textarea, Capability tags (chips: Sales, Support, Research, Code), Automation slider 0-100, Cost ceiling, Generate button. Preview: estimated response time, cost, success. Dark. Vue 3, Tailwind.
"""),
            ("Approvals", "src/views/Approvals.vue", """
Approvals. Top stats: Pending(orange), Approved(green), Rejected(red), Total. Cards: Agent name, action description, estimated impact, risk badge Low/Medium/High, cost, Approve/Reject buttons, timestamp. Filter bar. Tabs: Pending, Approved, Rejected. Left border colored by status. Bulk select. Dark. Vue 3, Tailwind.
"""),
        ]
    },
    {
        "name": "cockpit.internal.spidernetos.com",
        "dir": "/workspace/SpiderNetOS/cockpit",
        "port": 80,
        "pages": [
            ("Observability", "src/views/Observability.vue", """
NASA mission control observability dashboard. 3 columns.
LEFT(25%): PLATFORM HEALTH (services list with status dots green/yellow/red, latency ms, uptime %), GPU CLUSTER (2x RTX 5090: util bar, VRAM bar, temp color-coded <75 green 75-85 yellow >85 red, power W), COST GOVERNOR (spend vs budget bar, per-tenant top 5).
CENTER(50%): AGENT SWARM (force-directed SVG graph, nodes colored by type Atlas=orange Forge=blue Sentinel=red Prism=purple Nexus=green, sized by compute, click for details), EVENT STREAM (scrolling log, color-coded status, filter All/Errors/HighCost/Agent).
RIGHT(25%): CPL HEATMAP (2D grid novelty vs emotional, color intensity = utility, Pareto frontier line), EXPLORATION vs EXPLOITATION gauge, SYSTEM THROUGHPUT (requests/min, agent execs/min sparklines).
Dark #0A0A0F. Panels #12121A. JetBrains Mono all data. Real-time via WebSocket. Keyboard: g=GPU, a=agents, e=events. Vue 3, Tailwind.
"""),
            ("RLTraining", "src/views/RLTraining.vue", """
RL Training view for CPL engine. Top: status, episode count, steps, elapsed, policy summary, GPU allocation.
LEFT(60%): REWARD CURVE (SVG line, episode vs cumulative reward, orange, smoothed), PARETO FRONT (SVG scatter, novelty/emotional/utility axes, frontier line), LOSS DECOMPOSITION (stacked area SVG: policy/value/entropy).
RIGHT(40%): HYPERPARAMETERS (sliders: LR, batch size, gamma, GAE lambda, entropy coef), ACTION DISTRIBUTION (SVG bar chart: deploy/modify/pause/query/explore/exploit), AGENT PERFORMANCE TABLE (sortable: Agent, Win Rate, Avg Reward, Drift, Updated).
BOTTOM: Start/Pause/Save/Load/Reset buttons, checkpoint history.
Technical aesthetic. Grid lines. Orange/teal/purple. Monospace numbers. Vue 3, Tailwind. Mock realistic RL curves.
"""),
            ("AgentDebugger", "src/views/AgentDebugger.vue", """
Agent Debugger. Top: Agent selector, status, confidence, Inject Command button.
LEFT: Execution Trace (vertical timeline, timestamped steps with action and collapsible reasoning, connected by vertical line).
CENTER: State Visualization (JSON tree, expandable, syntax highlight: orange strings, teal numbers, purple booleans, search/filter).
RIGHT: Decision Logic (SVG bar chart action probabilities, chosen highlighted, value estimate, uncertainty warning if confidence < 0.7).
BOTTOM: Log Stream (last 100 lines, filter INFO/DEBUG/WARN/ERROR, searchable, auto-scroll).
Terminal aesthetic. Monospace everywhere. Indent guides. Vue 3, Tailwind.
"""),
        ]
    }
]

SHARED = [
    ("WebSocket", "src/services/ws.js", """
WebSocket client for Vue 3/Pinia. Auto-connect on mount. Exponential backoff reconnect 1s,2s,4s,8s,max30s. Heartbeat 30s. Message routing by type to stores. Connection status. Graceful disconnect. Dynamic URL ws://{hostname}:8002/ws. Handle: gpu→systemStore.setGpu(), event→systemStore.addEvent(), cpl_score→cplStore.addScore(), service_status→systemStore.updateService(). Export: initWebSocket(), closeWebSocket(), sendMessage(data), getConnectionStatus(). Plain JS module.
"""),
    ("GlassCard", "src/components/GlassCard.vue", """
Reusable GlassCard Vue component. Props: title(String), subtitle(String, optional), icon(String), accent(String orange/teal/purple/red), loading(Boolean), collapsible(Boolean), defaultCollapsed(Boolean). Styling: bg rgba(255,255,255,0.03) or #1A1A24, backdrop-blur 12px, border 1px solid rgba(255,255,255,0.06), radius 12px, padding 20px, top border accent 2px, header flex title/collapse icon, content slot, footer slot, hover translateY -1px, loading shimmer gradient. Vue 3, Tailwind.
"""),
    ("OrangeTheme", "src/styles/orange-theme.css", """
CSS theme for SpiderNetOS. Variables: --color-primary #FF6B2C, --primary-light #FF8C42, --primary-dark #E55A1E, --teal #00E5C8, --purple #B967FF, --success #00E5C8, --warning #FFA726, --danger #F44336, --bg-base #0A0A0F, --bg-panel #12121A, --bg-card #1A1A24, --bg-elevated #252530, --text-primary #E8E8EF, --text-secondary #6B6B7B, --text-muted #4A4A5A, --border rgba(255,255,255,0.06).
Utilities: .glass (blur 12px), .glass-strong (blur 20px), .glow-orange, .glow-teal, .text-glow-orange.
Gradients: .gradient-primary (135deg #FF6B2C to #FF8C42), .gradient-dark (180deg #12121A to #0A0A0F).
Animations: @keyframes pulse-glow, shimmer, fade-in, slide-in.
Typography: body Inter, mono JetBrains Mono. Scrollbar thin 6px.
"""),
]


def write_build_files(site_dir, port, domain):
    pkg = {
        "name": f"spidernet-{domain.split('.')[0]}", "version": "1.0.0", "type": "module",
        "scripts": {"dev": f"vite --host 0.0.0.0 --port {port}", "build": "vite build", "preview": "vite preview"},
        "dependencies": {"vue": "^3.4.0", "vue-router": "^4.2.0", "pinia": "^2.1.0", "lucide-vue-next": "^0.400.0"},
        "devDependencies": {"@vitejs/plugin-vue": "^5.0.0", "vite": "^5.0.0", "tailwindcss": "^3.4.0", "postcss": "^8.4.0", "autoprefixer": "^10.4.0"}
    }
    for path, data in [
        ("package.json", json.dumps(pkg, indent=2)),
        ("vite.config.js", f"""import {{ defineConfig }} from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'
export default defineConfig({{
  plugins: [vue()],
  server: {{ host: '0.0.0.0', port: {port}, allowedHosts: ['{domain}', '.spidernetos.com', 'localhost'] }},
  resolve: {{ alias: {{ '@': path.resolve(__dirname, './src') }} }},
  build: {{ outDir: 'dist', sourcemap: false, minify: 'terser' }}
}})"""),
        ("tailwind.config.js", """/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js,ts}'],
  theme: { extend: { colors: { spider: { orange: '#FF6B2C', 'orange-light': '#FF8C42', teal: '#00E5C8', purple: '#B967FF', dark: '#0A0A0F', panel: '#12121A', card: '#1A1A24' } }, fontFamily: { mono: ['JetBrains Mono', 'monospace'], sans: ['Inter', 'sans-serif'] } } },
  plugins: []
}"""),
        ("postcss.config.js", "export default { plugins: { tailwindcss: {}, autoprefixer: {} } }"),
        ("index.html", f"""<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/><title>SpiderNetOS - {domain}</title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"></head><body class="bg-spider-dark"><div id="app"></div><script type="module" src="/src/main.js"></script></body></html>"""),
        ("src/main.js", "import { createApp } from 'vue'\nimport { createPinia } from 'pinia'\nimport App from './App.vue'\nimport './styles/orange-theme.css'\nconst app = createApp(App)\napp.use(createPinia())\napp.mount('#app')\n"),
    ]:
        p = os.path.join(site_dir, path)
        os.makedirs(os.path.dirname(p), exist_ok=True)
        if not os.path.exists(p):
            with open(p, "w") as f:
                f.write(data)
            log(f"  Config: {path}")


async def generate_one(site):
    name, sdir, port = site["name"], site["dir"], site["port"]
    log(f"\n{'='*60}")
    log(f"SITE: {name}")
    log(f"{'='*60}")
    os.makedirs(sdir, exist_ok=True)

    write_build_files(sdir, port, name)

    files = []
    for pname, pfile, pprompt in site["pages"]:
        fpath = os.path.join(sdir, pfile)
        log(f"\n  Generating: {pname}")

        if os.path.exists(fpath) and os.path.getsize(fpath) > 500:
            log(f"  Exists ({os.path.getsize(fpath)} bytes), skipping")
            files.append(fpath)
            continue

        raw = await gen(pprompt)
        if not raw:
            log(f"  Failed to generate {pname}")
            continue

        _, ext = os.path.splitext(fpath)
        code = clean(raw, ext)
        os.makedirs(os.path.dirname(fpath), exist_ok=True)
        with open(fpath, "w") as f:
            f.write(code)
        log(f"  Generated: {len(code)} chars")
        files.append(fpath)
        await asyncio.sleep(2)

    # Shared components for this site
    for cname, cfile, cprompt in SHARED:
        fpath = os.path.join(sdir, cfile)
        log(f"\n  Generating shared: {cname}")

        if os.path.exists(fpath) and os.path.getsize(fpath) > 200:
            log(f"  Exists, skipping")
            continue

        raw = await gen(cprompt)
        if not raw:
            log(f"  Failed")
            continue

        _, ext = os.path.splitext(fpath)
        code = clean(raw, ext)
        os.makedirs(os.path.dirname(fpath), exist_ok=True)
        with open(fpath, "w") as f:
            f.write(code)
        log(f"  Generated: {len(code)} chars")
        await asyncio.sleep(1)

    return files


async def build_site(sdir):
    log(f"\n  Building...")
    if not os.path.exists(os.path.join(sdir, "node_modules")):
        log("  Installing deps...")
        proc = await asyncio.create_subprocess_shell(
            f"cd {sdir} && npm install 2>&1",
            stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE
        )
        stdout, stderr = await proc.communicate()
        if proc.returncode != 0:
            log(f"  npm install failed: {stderr.decode()[:200]}")
            return False
        log("  Deps installed")

    proc = await asyncio.create_subprocess_shell(
        f"cd {sdir} && npm run build 2>&1",
        stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE
    )
    stdout, stderr = await proc.communicate()
    if proc.returncode == 0:
        dist = os.path.join(sdir, "dist")
        if os.path.exists(dist):
            log(f"  Build OK: {len(os.listdir(dist))} files")
            return True
    log(f"  Build failed: {stderr.decode()[:500]}")
    return False


async def main():
    log(f"\n{'#'*60}")
    log("SPIDERNETOS SITE GENERATOR")
    log(f"{'#'*60}")

    try:
        async with httpx.AsyncClient(timeout=10) as c:
            r = await c.get(f"{OLLAMA_URL}/api/tags")
            if r.status_code == 200:
                models = r.json().get("models", [])
                names = [m.get("name", m.get("model", "?")) for m in models]
                log(f"Ollama ready. Models: {', '.join(names[:3])}")
    except Exception as e:
        log(f"Cannot reach Ollama: {e}")
        return

    all_files = []
    for site in SITES:
        files = await generate_one(site)
        all_files.extend(files)
        ok = await build_site(site["dir"])
        if ok:
            log(f"  ✓ {site['name']} built successfully")
        else:
            log(f"  ✗ {site['name']} build failed")

    log(f"\n{'#'*60}")
    log("DONE")
    log(f"Files: {len(all_files)}")
    for f in all_files:
        size = os.path.getsize(f) if os.path.exists(f) else 0
        log(f"  {os.path.basename(f)} ({size} bytes)")
    log(f"\nNext: Configure Nginx for all three sites")


if __name__ == "__main__":
    asyncio.run(main())
