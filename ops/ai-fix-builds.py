#!/usr/bin/env python3
"""Use Ollama to fix and complete all three site builds."""
import json, os, subprocess, sys, time, urllib.request

OLLAMA = "http://localhost:11434"
MODEL = "gemma4:31b"

def log(msg):
    print(f"[{time.strftime('%H:%M:%S')}] {msg}", flush=True)

def ollama_generate(prompt, max_tokens=4000):
    system = ("You are an expert Vue 3 + Tailwind CSS developer. "
              "Generate COMPLETE, valid, production-ready code. "
              "Use Vue 3 Composition API with <script setup>. "
              "Use Tailwind CSS for all styling. "
              "Dark theme only: bg #0A0A0F, cards #1A1A24, orange #FF6B2C. "
              "Generate ONLY the code. No explanations, no markdown fences. "
              "Start immediately with <template>, import, or CSS.")
    data = json.dumps({
        "model": MODEL,
        "prompt": f"{system}\n\n{prompt}\n\nGenerate ONLY code:",
        "stream": False,
        "options": {"temperature": 0.15, "num_predict": max_tokens}
    }).encode()
    req = urllib.request.Request(f"{OLLAMA}/api/generate", data=data, headers={"Content-Type": "application/json"})
    try:
        resp = urllib.request.urlopen(req, timeout=300)
        return json.loads(resp.read().decode()).get("response", "")
    except Exception as e:
        log(f"Ollama error: {e}")
        return ""

def clean_code(raw, ext):
    lines = raw.split("\n")
    start, end = 0, len(lines)
    for i, l in enumerate(lines):
        if l.strip().startswith("```"):
            start = i + 1
            break
    for i in range(len(lines) - 1, start - 1, -1):
        if lines[i].strip().startswith("```"):
            end = i
            break
    code = "\n".join(lines[start:end]).strip()
    # Remove common preamble phrases
    preambles = ["here is", "here's", "below is", "generated", "i have created", "the code"]
    lower = code.lower()
    for p in preambles:
        if lower.startswith(p):
            # Find first real code line
            for i, line in enumerate(code.split("\n")):
                s = line.strip()
                if s and (s.startswith(("<", "import", "export", "const", "function", "//", "/*", ".", "#", "from ")) or s.startswith("*")):
                    code = "\n".join(code.split("\n")[i:])
                    break
            break
    if ext == ".vue" and not code.startswith(("<template>", "<script", "<style")):
        if "<div" in code or "<section" in code:
            code = f"<template>\n{code}\n</template>"
    return code

def write_file(path, content):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w") as f:
        f.write(content)
    log(f"Wrote {path} ({len(content)} chars)")

def npm_build(site_dir):
    log(f"Building {site_dir}...")
    if not os.path.exists(os.path.join(site_dir, "node_modules")):
        r = subprocess.run(["npm", "install"], cwd=site_dir, capture_output=True, text=True)
        if r.returncode != 0:
            log(f"npm install failed: {r.stderr[:200]}")
            return False
    r = subprocess.run(["npm", "run", "build"], cwd=site_dir, capture_output=True, text=True)
    if r.returncode != 0:
        log(f"BUILD FAILED: {r.stderr[-500:]}")
        return False
    dist = os.path.join(site_dir, "dist")
    files = os.listdir(dist) if os.path.exists(dist) else []
    log(f"Build OK: {len(files)} files in dist/")
    return True

# === 1. FIX LANDING APP.VUE ===
log("=== FIXING LANDING APP.VUE ===")
landing_app = ollama_generate(
    "Create a complete Vue 3 landing page for 'SpiderNetOS - Agent-Native Operating System'. "
    "Single file App.vue. Fullscreen dark hero (#0A0A0F) with HTML5 canvas particle network (animated dots connected by lines). "
    "Center text: 'What if your business did not scale linearly but autonomously?' Big orange #FF6B2C CTA button. "
    "Below: 3 cards in a row: BUILD (create AI systems), SELL (automate revenue), SCALE (deploy operations). "
    "Each card has emoji icon, title, description, expands on click to show more detail. "
    "Metrics section with 3 big numbers: 2.4M Agents, $840M Revenue, 99.97% Uptime. "
    "Terminal-style demo section with fake command outputs. "
    "Minimal footer. Tailwind CSS classes only. No external libraries. Mobile responsive grid. "
    "CRITICAL: Every HTML tag must have a matching closing tag. Use self-closing for empty tags only."
)
if landing_app:
    write_file("/workspace/SpiderNetOS/sites/landing/src/App.vue", clean_code(landing_app, ".vue"))

# === 2. FIX CUSTOMER COCKPIT ===
log("=== FIXING CUSTOMER COCKPIT ===")

# App.vue shell
app_shell = ollama_generate(
    "Create a Vue 3 App.vue shell for a customer dashboard. Layout: left sidebar (250px) with navigation items "
    "(Dashboard, Agents, Flows, Approvals-with badge 3, Traces, Usage, Memory, Settings). "
    "Main area with top bar showing page title and budget indicator. "
    "The main content area uses <component :is='currentView'> to switch between views. "
    "Import Dashboard, AgentBuilder, Approvals from ./views/. "
    "Dark theme #0F0F14 background, sidebar #12121A, orange #FF6B2C accents. Tailwind CSS."
)
if app_shell:
    write_file("/workspace/SpiderNetOS/sites/customer-cockpit/src/App.vue", clean_code(app_shell, ".vue"))

# Dashboard
log("Generating Dashboard...")
dash = ollama_generate(
    "Create a Vue 3 Dashboard component for an AI agent platform customer panel. "
    "Bento grid layout with cards: System Status (green pulsing dot, 'All systems operational'), "
    "Active Agents (count with trend), Revenue Today ($ with sparkline SVG), Recent Events scrollable list. "
    "Quick action buttons: Deploy Agent, Create Flow, View Analytics. "
    "All mock data. Dark cards #1A1A24 on #0F0F14 bg. Orange #FF6B2C accents. JetBrains Mono font for numbers. Tailwind CSS."
)
if dash:
    write_file("/workspace/SpiderNetOS/sites/customer-cockpit/src/views/Dashboard.vue", clean_code(dash, ".vue"))

# AgentBuilder (was missing)
log("Generating AgentBuilder...")
builder = ollama_generate(
    "Create a Vue 3 AgentBuilder component. Split view: left side shows a visual canvas with 5 nodes "
    "(Agent center, Trigger top-left, Action top-right, Memory bottom-left, Output bottom-right) connected by lines. "
    "Use SVG for the canvas. Right side: form with Name input, Description textarea, "
    "capability tags (Sales, Support, Research, Code, Design) as clickable pills, "
    "Automation Level slider 0-100, Cost Ceiling input, and Generate button. "
    "Preview section below showing estimated metrics. Dark #0F0F14 bg. Orange #FF6B2C. Tailwind CSS."
)
if builder:
    write_file("/workspace/SpiderNetOS/sites/customer-cockpit/src/views/AgentBuilder.vue", clean_code(builder, ".vue"))

# Approvals
log("Generating Approvals...")
approvals = ollama_generate(
    "Create a Vue 3 Approvals component for agent action approvals. Top row: stat cards "
    "(Pending 3 orange, Approved 12 green, Rejected 1 red, Total 16). "
    "Below: list of approval cards. Each card shows: agent name, action description, "
    "risk level badge (Low/Medium/High with colors), cost estimate, Approve (green) and Reject (red) buttons. "
    "Filter tabs: Pending, Approved, Rejected. Dark #0F0F14. Cards #1A1A24. Orange/green/red accents. Tailwind CSS."
)
if approvals:
    write_file("/workspace/SpiderNetOS/sites/customer-cockpit/src/views/Approvals.vue", clean_code(approvals, ".vue"))

# === 3. FIX INTERNAL COCKPIT ===
log("=== FIXING INTERNAL COCKPIT ===")

# Observability
log("Generating Observability...")
obs = ollama_generate(
    "Create a Vue 3 Observability dashboard (NASA mission control style). 3-column layout on dark #0A0A0F bg. "
    "LEFT: Service Health list (Inference online green, Atlas API online green, Intelligence online green, WebSocket online green) with latency ms. "
    "GPU Cluster section showing 2x RTX 5090 with utilization bars (78%, 45%), temp (72C green, 68C green), power draw. "
    "CENTER: Agent Swarm - SVG force-directed-like visualization with 5 colored circles (Atlas orange, Forge blue, Sentinel red, Prism purple, Nexus green). "
    "Event Stream below - scrolling log of recent events with timestamps, agent names, actions, status colors. "
    "RIGHT: CPL Reward heatmap (10x10 grid of orange squares with varying opacity), "
    "Exploration vs Exploitation gauge (80/20), throughput sparkline. "
    "Use mock data. All numbers in monospace. Tailwind CSS."
)
if obs:
    write_file("/workspace/SpiderNetOS/cockpit/src/views/Observability.vue", clean_code(obs, ".vue"))

# RLTraining
log("Generating RLTraining...")
rl = ollama_generate(
    "Create a Vue 3 RL Training dashboard for reinforcement learning monitoring. "
    "Top bar: status 'Training', episode 1,247, steps 8.4M, GPU RTX 5090. "
    "Left side (60%): SVG line chart showing reward curve going up over episodes (orange line). "
    "Below: Pareto front scatter plot with ~20 points and a frontier line. "
    "Right side (40%): hyperparameter sliders (Learning Rate, Batch Size, Gamma, GAE Lambda), "
    "action distribution bar chart (6 bars: deploy/modify/pause/query/explore/exploit), "
    "agent performance table (3 rows: AtlasAgent 87%, ForgeAgent 92%, SentinelAgent 78%). "
    "Bottom: Start/Pause/Save/Reset buttons. Dark bg. Orange/teal/purple accents. Tailwind CSS."
)
if rl:
    write_file("/workspace/SpiderNetOS/cockpit/src/views/RLTraining.vue", clean_code(rl, ".vue"))

# AgentDebugger (already generated, but ensure it exists)
if not os.path.exists("/workspace/SpiderNetOS/cockpit/src/views/AgentDebugger.vue"):
    log("Generating AgentDebugger...")
    dbg = ollama_generate(
        "Create a Vue 3 Agent Debugger interface. Top: agent selector dropdown and status. "
        "Left: Execution Trace - vertical timeline with 5 steps connected by a line, each step expandable. "
        "Center: State Visualization - JSON tree display with syntax colors (orange keys, teal values). "
        "Right: Decision Logic - bar chart showing 4 action probabilities, with the chosen one highlighted. "
        "Bottom: Log stream (last 20 lines), filterable by INFO/WARN/ERROR. Terminal aesthetic. Monospace. Dark. Tailwind CSS."
    )
    if dbg:
        write_file("/workspace/SpiderNetOS/cockpit/src/views/AgentDebugger.vue", clean_code(dbg, ".vue"))

# === BUILD ALL ===
log("\n=== BUILDING ALL SITES ===")
for site, name in [
    ("/workspace/SpiderNetOS/sites/landing", "Landing"),
    ("/workspace/SpiderNetOS/sites/customer-cockpit", "Customer Cockpit"),
    ("/workspace/SpiderNetOS/cockpit", "Internal Cockpit"),
]:
    log(f"\n--- {name} ---")
    npm_build(site)

log("\n=== DONE ===")
