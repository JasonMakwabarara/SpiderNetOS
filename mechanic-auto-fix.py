#!/usr/bin/env python3
"""
SpiderNetOS Mechanic Agent - Autonomous Build & Deploy Fixer
Uses local Ollama models to diagnose and repair build issues.
"""
import json, os, re, shutil, subprocess, sys, time, urllib.request
from pathlib import Path

OLLAMA_URL = "http://localhost:11434"
MODEL = "gemma4:31b"
BASE_DIR = "/workspace/SpiderNetOS"
SITES = {
    "landing": {
        "dir": f"{BASE_DIR}/sites/landing",
        "port": 3000,
        "domain": "spidernetos.com",
        "files_to_check": ["src/App.vue", "src/main.js", "src/styles/orange-theme.css"],
    },
    "customer-cockpit": {
        "dir": f"{BASE_DIR}/sites/customer-cockpit",
        "port": 3001,
        "domain": "cockpit.spidernetos.com",
        "files_to_check": ["src/App.vue", "src/main.js", "src/styles/orange-theme.css"],
    },
    "internal-cockpit": {
        "dir": f"{BASE_DIR}/cockpit",
        "port": 3002,
        "domain": "cockpit.internal.spidernetos.com",
        "files_to_check": ["src/App.vue", "src/main.js"],
    },
}

LOG_FILE = f"{BASE_DIR}/logs/mechanic-fix.log"
os.makedirs(os.path.dirname(LOG_FILE), exist_ok=True)

def log(msg, level="INFO"):
    ts = time.strftime("%H:%M:%S")
    line = f"[{ts}] [{level}] {msg}"
    print(line, flush=True)
    with open(LOG_FILE, "a") as f:
        f.write(line + "\n")

def ollama_generate(prompt, max_tokens=4000):
    """Call Ollama to generate code fixes."""
    system = (
        "You are the SpiderNetOS Mechanic - an autonomous build repair agent. "
        "Generate ONLY clean, valid, production-ready code. No explanations, no markdown fences. "
        "Use Vue 3 Composition API with <script setup>. Tailwind CSS. Dark theme: bg #0A0A0F, cards #1A1A24, orange #FF6B2C. "
        "CRITICAL: Every HTML tag must have matching closing tag."
    )
    data = json.dumps({
        "model": MODEL,
        "prompt": f"{system}\n\n{prompt}\n\nGenerate ONLY code (no markdown, no explanations):",
        "stream": False,
        "options": {"temperature": 0.05, "num_predict": max_tokens}
    }).encode()
    req = urllib.request.Request(f"{OLLAMA_URL}/api/generate", data=data, headers={"Content-Type": "application/json"})
    try:
        resp = urllib.request.urlopen(req, timeout=300)
        return json.loads(resp.read().decode()).get("response", "")
    except Exception as e:
        log(f"Ollama call failed: {e}", "ERROR")
        return ""

def clean_code(raw, is_vue=False):
    """Strip markdown fences and fix common AI generation issues."""
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
    preambles = ["here is", "here's", "below is", "generated", "i have created", "the code", "sure", "okay"]
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
    # Ensure Vue files start with <template> or <script>
    if is_vue and not code.startswith(("<template>", "<script", "<style")):
        if "<div" in code or "<section" in code:
            code = f"<template>\n{code}\n</template>"
    return code

def write_file(path, content):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w") as f:
        f.write(content)
    log(f"Wrote {path} ({len(content)} chars)")

def npm_build(site_dir):
    """Run npm build and capture errors."""
    log(f"Building {site_dir}...")
    # Ensure node_modules exists
    if not os.path.exists(os.path.join(site_dir, "node_modules")):
        r = subprocess.run(["npm", "install"], cwd=site_dir, capture_output=True, text=True, timeout=120)
        if r.returncode != 0:
            log(f"npm install failed: {r.stderr[-300:]}", "ERROR")
            return False, r.stderr
    r = subprocess.run(["npm", "run", "build"], cwd=site_dir, capture_output=True, text=True, timeout=120)
    if r.returncode != 0:
        err = r.stderr[-800:]
        log(f"BUILD FAILED: {err}", "ERROR")
        return False, err
    dist = os.path.join(site_dir, "dist")
    files = os.listdir(dist) if os.path.exists(dist) else []
    log(f"Build OK: {len(files)} files in dist/")
    return True, ""

def diagnose_and_fix(site_key, site_info):
    """Diagnose build issues and use Ollama to fix them."""
    site_dir = site_info["dir"]
    log(f"\n=== DIAGNOSING {site_key.upper()} ===")

    # Check for missing critical files
    missing = []
    for f in site_info["files_to_check"]:
        path = os.path.join(site_dir, f)
        if not os.path.exists(path):
            missing.append(f)
            log(f"Missing file: {f}")

    # Try build to get actual error
    ok, error = npm_build(site_dir)
    if ok and not missing:
        log(f"{site_key} build OK, no fixes needed")
        return True

    # Use Ollama to fix
    log(f"Using {MODEL} to fix {site_key}...")

    if "landing" in site_key:
        fix_landing(site_dir, error)
    elif "customer" in site_key:
        fix_customer(site_dir, error)
    elif "internal" in site_key:
        fix_internal(site_dir, error)

    # Retry build
    ok, error2 = npm_build(site_dir)
    if ok:
        log(f"{site_key} FIXED successfully")
        return True
    else:
        log(f"{site_key} still failing: {error2[-300:]}", "ERROR")
        return False

def fix_landing(site_dir, error):
    """Generate/fix landing site files."""
    # Fix main.js
    main_js = "import { createApp } from 'vue'\nimport App from './App.vue'\ncreateApp(App).mount('#app')\n"
    write_file(os.path.join(site_dir, "src/main.js"), main_js)

    # Fix CSS
    css = ":root { --color-primary: #FF6B2C; --color-bg: #0A0A0F; --color-card: #12121A; }\n"
    write_file(os.path.join(site_dir, "src/styles/orange-theme.css"), css)

    # Generate App.vue via Ollama
    prompt = (
        "Create a complete Vue 3 App.vue landing page for SpiderNetOS. "
        "Dark background #0A0A0F, text #E8E8EF. "
        "Hero section: fullscreen with HTML5 canvas animated particle network (50 dots connected by lines within 150px distance). "
        "Title: SpiderNetOS in orange gradient (#FF6B2C to #FF8C42). "
        "Subtitle: 'What if your business did not scale linearly but autonomously?'. "
        "Big orange CTA button 'Run Simulation'. "
        "Below hero: 3 cards in a responsive grid - BUILD (create AI systems), SELL (automate revenue), SCALE (deploy operations), each with emoji icon. "
        "Metrics section: 3 big numbers - 2.4M Agents, $840M Revenue, 99.97% Uptime. "
        "Minimal footer with 'SpiderNetOS v3.2'. "
        "Use Tailwind CSS classes ONLY. No external libraries. "
        "Use Vue 3 <script setup> with Composition API. "
        "CRITICAL: Every HTML tag must have matching closing tag. Use self-closing only for br/img."
    )
    raw = ollama_generate(prompt, max_tokens=3500)
    code = clean_code(raw, is_vue=True)
    write_file(os.path.join(site_dir, "src/App.vue"), code)

def fix_customer(site_dir, error):
    """Generate/fix customer cockpit files."""
    main_js = "import { createApp } from 'vue'\nimport App from './App.vue'\ncreateApp(App).mount('#app')\n"
    write_file(os.path.join(site_dir, "src/main.js"), main_js)

    css = ":root { --color-primary: #FF6B2C; --color-bg: #0F0F14; --color-card: #1A1A24; }\n"
    write_file(os.path.join(site_dir, "src/styles/orange-theme.css"), css)

    # Generate App.vue shell
    prompt = (
        "Create a Vue 3 App.vue for a customer dashboard. "
        "Layout: left sidebar (w-64, bg #12121A) with navigation items: Dashboard, Agents, Flows, Approvals (with badge count 3), Traces, Usage, Memory, Settings. "
        "Each nav item has emoji icon. Active item highlighted with orange #FF6B2C. "
        "Sidebar bottom shows budget: $34.50 / $50.00 with progress bar. "
        "Main area: top bar with page title, then content area that switches views based on selected nav. "
        "Dashboard view shows: 3 stat cards (System Status 'Operational' green, Active Agents '12' orange, Revenue Today '$2,847' green), and recent events list below. "
        "Use Vue 3 <script setup>. Import Dashboard from ./views/Dashboard.vue. "
        "Tailwind CSS. Dark theme #0F0F14 background. "
        "CRITICAL: Every HTML tag must close. Use v-if for view switching."
    )
    raw = ollama_generate(prompt, max_tokens=3500)
    code = clean_code(raw, is_vue=True)
    write_file(os.path.join(site_dir, "src/App.vue"), code)

    # Generate Dashboard.vue
    prompt2 = (
        "Create a Vue 3 Dashboard.vue component. "
        "Top row: 3 stat cards in grid - System Status (green dot, 'Operational'), Active Agents ('12', '+3 today'), Revenue Today ('$2,847', '+12%'). "
        "Below: Recent Events section with 4 events (Agent completed deal, Cost threshold warning, New flow deployed, Inference scaling). "
        "Each event has colored status dot and timestamp. "
        "Dark cards on #0F0F14 background. Orange #FF6B2C accents. Tailwind CSS."
    )
    raw2 = ollama_generate(prompt2, max_tokens=2500)
    code2 = clean_code(raw2, is_vue=True)
    os.makedirs(os.path.join(site_dir, "src/views"), exist_ok=True)
    write_file(os.path.join(site_dir, "src/views/Dashboard.vue"), code2)

def fix_internal(site_dir, error):
    """Internal cockpit - usually builds OK, just verify."""
    log("Internal cockpit checked - should be pre-built")
    # Ensure dist exists
    dist = os.path.join(site_dir, "dist")
    if not os.path.exists(dist):
        npm_build(site_dir)

def manage_servers():
    """Kill old servers and start new ones on correct ports."""
    log("\n=== MANAGING SERVERS ===")

    # Find and kill old python http servers
    log("Killing old http.server processes...")
    subprocess.run(["pkill", "-f", "http.server"], capture_output=True)
    time.sleep(2)

    # Verify ports are free
    for site_key, info in SITES.items():
        port = info["port"]
        # Check if port is free using /proc/net/tcp
        with open("/proc/net/tcp") as f:
            lines = f.readlines()
        hex_port = f"{port:04X}"
        occupied = any(hex_port in line.upper() for line in lines)
        if occupied:
            log(f"Port {port} still occupied, forcing...")
            subprocess.run(["fuser", "-k", f"{port}/tcp"], capture_output=True)
            time.sleep(1)

    # Start servers with nohup
    for site_key, info in SITES.items():
        port = info["port"]
        dist_dir = os.path.join(info["dir"], "dist")
        log_file = f"/tmp/server-{port}.log"

        if not os.path.exists(dist_dir):
            log(f"No dist/ for {site_key}, skipping", "WARN")
            continue

        cmd = f"nohup python3 -m http.server {port} --directory {dist_dir} > {log_file} 2>&1 &"
        log(f"Starting {site_key} on port {port}")
        os.system(cmd)
        time.sleep(1)

    # Verify all are responding
    time.sleep(2)
    log("\n=== VERIFICATION ===")
    all_ok = True
    for site_key, info in SITES.items():
        port = info["port"]
        try:
            req = urllib.request.Request(f"http://localhost:{port}")
            resp = urllib.request.urlopen(req, timeout=5)
            status = resp.getcode()
            html = resp.read(500).decode()
            title = re.search(r'<title>(.*?)</title>', html, re.I)
            title_str = title.group(1) if title else "no title"
            log(f"Port {port} ({site_key}): HTTP {status} - '{title_str}'")
        except Exception as e:
            log(f"Port {port} ({site_key}): FAILED - {e}", "ERROR")
            all_ok = False

    return all_ok

def main():
    log("=" * 60)
    log("SPIDERNETOS MECHANIC AGENT - AUTONOMOUS FIX")
    log("=" * 60)

    # Check Ollama availability
    try:
        resp = urllib.request.urlopen(f"{OLLAMA_URL}/api/tags", timeout=5)
        models = json.loads(resp.read().decode())
        model_names = [m["name"] for m in models.get("models", [])]
        log(f"Ollama ready. Models: {', '.join(model_names)}")
    except Exception as e:
        log(f"Ollama not available: {e}", "ERROR")
        sys.exit(1)

    # Phase 1: Fix all builds
    results = {}
    for site_key, info in SITES.items():
        ok = diagnose_and_fix(site_key, info)
        results[site_key] = ok

    # Phase 2: Manage servers
    servers_ok = manage_servers()

    # Summary
    log("\n" + "=" * 60)
    log("SUMMARY")
    log("=" * 60)
    for site, ok in results.items():
        status = "✅ FIXED" if ok else "❌ FAILED"
        log(f"  {site}: {status}")
    log(f"  Servers: {'✅ RUNNING' if servers_ok else '❌ ISSUES'}")
    log("=" * 60)

    # Cloudflare instructions
    log("\n=== CLOUDFLARE ORIGIN RULES ===")
    for site_key, info in SITES.items():
        log(f"  {info['domain']} -> http://220.134.41.156:{info['port']}")

    return all(results.values()) and servers_ok

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1)
