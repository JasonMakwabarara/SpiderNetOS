#!/usr/bin/env python3
"""SpiderNetOS Mechanic - Autonomous Health Monitor"""
import json, os, re, subprocess, sys, time, urllib.request, socket

OLLAMA = "http://localhost:11434"
MODEL = "deepseek-coder:33b"  # Upgraded from gemma4 for better code generation
FALLBACK_MODEL = "gemma4:31b"  # Fallback if deepseek unavailable
BASE = "/workspace/SpiderNetOS"
CHECK_SEC = 30

SITES = {
    3000: ("landing", f"{BASE}/sites/landing/dist", "SpiderNetOS"),
    3001: ("customer", f"{BASE}/sites/customer-cockpit/dist", "SpiderNetOS"),
    3002: ("internal", f"{BASE}/cockpit/dist", "Cockpit"),
}

LOG = f"{BASE}/logs/mechanic.log"
os.makedirs(os.path.dirname(LOG), exist_ok=True)

def log(msg, lvl="INFO"):
    line = f"[{time.strftime('%H:%M:%S')}] [{lvl}] {msg}"
    print(line, flush=True)
    open(LOG, "a").write(line + "\n")

def check(port):
    try:
        r = urllib.request.urlopen(f"http://localhost:{port}", timeout=3)
        h = r.read(800).decode()
        t = re.search(r'<title>(.*?)</title>', h, re.I)
        return r.getcode(), t.group(1) if t else "no title"
    except Exception as e:
        return None, str(e)

def is_free(port):
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.settimeout(1)
        ok = s.connect_ex(("localhost", port)) != 0
        s.close()
        return ok
    except: return False

def kill_port(port):
    subprocess.run(["fuser", "-k", f"{port}/tcp"], capture_output=True)
    time.sleep(1)

def start(port, dist_dir):
    if not os.path.exists(dist_dir):
        return None, "no dist"
    lf = f"/tmp/server-{port}.log"
    subprocess.Popen(
        ["python3", "-m", "http.server", str(port), "--directory", dist_dir],
        stdout=open(lf, "w"), stderr=subprocess.STDOUT,
        start_new_session=True
    )
    time.sleep(1)
    return check(port)

def check_model_available():
    """Check if preferred model is available"""
    try:
        req = urllib.request.Request(f"{OLLAMA}/api/tags")
        r = urllib.request.urlopen(req, timeout=5)
        data = json.loads(r.read().decode())
        models = [m["name"] for m in data.get("models", [])]
        
        if MODEL in models:
            return MODEL
        elif FALLBACK_MODEL in models:
            log(f"Using fallback model: {FALLBACK_MODEL}")
            return FALLBACK_MODEL
        else:
            log(f"No preferred models found, using first available: {models[0] if models else 'unknown'}")
            return models[0] if models else FALLBACK_MODEL
    except Exception as e:
        log(f"Model check failed: {e}", "WARN")
        return FALLBACK_MODEL

def ollama(prompt, tokens=3000, model=None):
    """Generate code using DeepSeek (or fallback)"""
    use_model = model or check_model_available()
    
    system = """You are the SpiderNetOS Code Architect. 
Generate production-ready Vue 3 components using <script setup> Composition API.
Use Tailwind CSS with dark theme (bg #0A0A0F, primary #FF6B2C).
CRITICAL: Every HTML tag must have matching closing tag. No unclosed elements.
Generate ONLY code - no markdown fences, no explanations."""
    
    data = json.dumps({
        "model": use_model, 
        "prompt": f"{system}\n\n{prompt}\n\nGenerate ONLY the code:",
        "stream": False, 
        "options": {"temperature": 0.03, "num_predict": tokens}  # Lower temp for code
    }).encode()
    
    req = urllib.request.Request(f"{OLLAMA}/api/generate", data=data, headers={"Content-Type": "application/json"})
    
    try:
        r = urllib.request.urlopen(req, timeout=300)
        result = json.loads(r.read().decode())
        raw = result.get("response", "")
        
        # Log model used
        log(f"Generated code using {use_model} ({len(raw)} chars)")
        return raw
        
    except Exception as e:
        log(f"Ollama error with {use_model}: {e}", "ERROR")
        
        # Try fallback
        if use_model != FALLBACK_MODEL:
            log(f"Trying fallback model...")
            return ollama(prompt, tokens, FALLBACK_MODEL)
        
        return ""

def clean(raw, is_vue=False):
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
    if is_vue and not code.startswith(("<template>", "<script", "<style")):
        if "<div" in code or "<section" in code:
            code = f"<template>\n{code}\n</template>"
    return code

def fix_and_build(name, site_dir):
    log(f"Rebuilding {name} via {MODEL}...")
    # Ensure base files
    os.makedirs(os.path.join(site_dir, "src/styles"), exist_ok=True)
    css = ":root { --color-primary: #FF6B2C; --color-bg: #0A0A0F; --color-card: #1A1A24; }\n"
    open(os.path.join(site_dir, "src/styles/orange-theme.css"), "w").write(css)
    main = "import { createApp } from 'vue'\nimport App from './App.vue'\ncreateApp(App).mount('#app')\n"
    open(os.path.join(site_dir, "src/main.js"), "w").write(main)

    if "landing" in name:
        # Enhanced prompt for DeepSeek with stricter structure
        p = """Create a Vue 3 landing page component named App.vue using <script setup>.

STRUCTURE REQUIREMENTS:
1. <template> section with:
   - Full-screen hero section (h-screen, flex, items-center, justify-center)
   - Canvas element for particle animation (id="particles", absolute, inset-0)
   - Centered content: h1 "SpiderNetOS" (text-6xl, font-bold, bg-gradient-to-r from-orange-500 to-orange-400)
   - p subtitle about autonomous scaling (text-xl, text-gray-300, mt-4)
   - button "Run Simulation" (bg-orange-500, px-8, py-3, rounded)
   - 3 feature cards in grid (BUILD, SELL, SCALE) with emoji icons
   - Metrics row: 2.4M Agents, $840M Revenue, 99.97% Uptime
   - Footer with version

2. <script setup> section with:
   - onMounted lifecycle hook
   - Canvas particle animation: 40 dots, connect lines when distance < 150px
   - Refs for canvas and animation state

3. <style> section: scoped, minimal

DESIGN TOKENS:
- Background: bg-[#0A0A0F]
- Cards: bg-[#1A1A24] border border-gray-800
- Primary: text-orange-500, bg-orange-500
- Text: text-gray-100 (headings), text-gray-400 (body)

CRITICAL: Every div, section, h1, p, button must have closing tag."""
        
        raw = ollama(p, 4000)
        if raw: 
            open(os.path.join(site_dir, "src/App.vue"), "w").write(clean(raw, True))
            log(f"Landing App.vue generated ({len(raw)} chars)")
    elif "customer" in name:
        # DeepSeek-optimized prompts with explicit structure
        p = """Create a Vue 3 customer dashboard shell (App.vue) using <script setup>.

LAYOUT:
- Flex container (h-screen, bg-[#0F0F14])
- Left sidebar: fixed width w-64, bg-[#12121A], flex flex-col
  * Logo area at top
  * Nav items with icons: 🏠 Dashboard, 🤖 Agents, 🔄 Flows, ⚡ Approvals (with badge showing 3), 📊 Traces, 💰 Usage, 🧠 Memory, ⚙️ Settings
  * Active item: bg-orange-500/20 border-l-2 border-orange-500
  * Bottom: Budget display $34.50 / $50.00 with orange progress bar
- Main content area: flex-1, overflow-auto
  * Top bar with page title
  * Dynamic component area using <component :is="currentView">

SCRIPT:
- import { ref, computed } from 'vue'
- import Dashboard from './views/Dashboard.vue'
- currentView ref for switching
- navItems array with icon, label, badge properties

CRITICAL: Every element must close. Use self-closing only for input/img."""
        
        raw = ollama(p, 4000)
        if raw: 
            open(os.path.join(site_dir, "src/App.vue"), "w").write(clean(raw, True))
            log(f"Customer App.vue generated ({len(raw)} chars)")
        
        # Dashboard component
        p2 = """Create a Vue 3 Dashboard.vue component using <script setup>.

CONTENT:
- Padding: p-6
- Grid with 3 stat cards (grid-cols-3, gap-6):
  1. System Status: green dot (w-3 h-3 rounded-full bg-green-500), text "Operational", icon
  2. Active Agents: number "12", text "+3 today", orange icon
  3. Revenue Today: "$2,847", text "+12%", green trend icon
- Card styling: bg-[#1A1A24], rounded-lg, p-6, border border-gray-800
- Below cards: Recent Events section
  * Header: "Recent Events" with View All link
  * 4 event rows with colored status dots and timestamps:
    - Agent completed deal (green)
    - Cost threshold warning (yellow) 
    - New flow deployed (blue)
    - Inference scaling up (purple)

DESIGN: Dark theme, orange accents #FF6B2C, Tailwind CSS only."""
        
        raw2 = ollama(p2, 3000)
        if raw2:
            os.makedirs(os.path.join(site_dir, "src/views"), exist_ok=True)
            open(os.path.join(site_dir, "src/views/Dashboard.vue"), "w").write(clean(raw2, True))
            log(f"Customer Dashboard.vue generated ({len(raw2)} chars)")

    # Build
    if not os.path.exists(os.path.join(site_dir, "node_modules")):
        r = subprocess.run(["npm", "install"], cwd=site_dir, capture_output=True, text=True, timeout=120)
        if r.returncode != 0:
            log(f"npm install failed: {r.stderr[-300:]}", "ERROR")
            return False
    r = subprocess.run(["npm", "run", "build"], cwd=site_dir, capture_output=True, text=True, timeout=120)
    if r.returncode != 0:
        log(f"Build failed: {r.stderr[-500:]}", "ERROR")
        return False
    log(f"{name}: Build OK")
    return True

def ensure_server(port, name, dist_dir, expected_title):
    code, title = check(port)
    healthy = code == 200 and expected_title.lower() in title.lower()
    if healthy:
        return True, title

    log(f"{name}: Port {port} unhealthy (code={code}, title=\"{title}\")", "WARN")

    if not is_free(port):
        log(f"{name}: Killing old process on port {port}")
        kill_port(port)

    if not os.path.exists(os.path.join(dist_dir, "index.html")):
        log(f"{name}: No dist, rebuilding...")
        site_dir = os.path.dirname(dist_dir)
        if not fix_and_build(name, site_dir):
            return False, "build failed"

    code, title = start(port, dist_dir)
    if code == 200:
        log(f"{name}: Server started on {port} - \"{title}\"")
        return True, title
    return False, title

def main_loop():
    log("=" * 50)
    log("SPIDERNETOS MECHANIC AGENT - STARTING")
    log("=" * 50)

    # Check Ollama
    try:
        r = urllib.request.urlopen(f"{OLLAMA}/api/tags", timeout=5)
        models = json.loads(r.read().decode())
        names = [m["name"] for m in models.get("models", [])]
        log(f"Ollama ready: {', '.join(names)}")
    except Exception as e:
        log(f"Ollama unavailable: {e}", "WARN")

    log(f"Monitoring every {CHECK_SEC}s. Press Ctrl+C to stop.")
    log("=" * 50)

    while True:
        all_ok = True
        for port, (name, dist_dir, expected) in SITES.items():
            ok, msg = ensure_server(port, name, dist_dir, expected)
            if not ok:
                all_ok = False
                log(f"{name}: CRITICAL - {msg}", "ERROR")
            elif msg:
                pass  # Server is healthy
        
        if all_ok:
            log("All systems nominal")
        
        time.sleep(CHECK_SEC)

if __name__ == "__main__":
    try:
        main_loop()
    except KeyboardInterrupt:
        log("Mechanic stopped by user")
        sys.exit(0)
