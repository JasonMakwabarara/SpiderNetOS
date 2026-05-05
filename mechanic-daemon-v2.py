#!/usr/bin/env python3
"""SpiderNetOS Mechanic Agent v2 - Autonomous build & deploy fixer"""
import json, os, re, subprocess, sys, time, urllib.request, socket

OLLAMA = "http://localhost:11434"
MODEL = "gemma4:31b"
BASE = "/workspace/SpiderNetOS"

SITES = {
    # Apex domain serves the repository Cockpit SPA (not sites/landing).
    "apex_cockpit": {"dir": f"{BASE}/cockpit", "port": 3000, "domain": "spidernetos.com", "check": "SpiderNet"},
    "customer": {"dir": f"{BASE}/sites/customer-cockpit", "port": 3001, "domain": "cockpit.spidernetos.com", "check": "SpiderNetOS"},
    "internal_cockpit": {"dir": f"{BASE}/cockpit", "port": 3002, "domain": "cockpit.internal.spidernetos.com", "check": "Cockpit"},
}

LOG = f"{BASE}/logs/mechanic.log"
os.makedirs(os.path.dirname(LOG), exist_ok=True)

def log(msg, lvl="INFO"):
    line = f"[{time.strftime('%H:%M:%S')}] [{lvl}] {msg}"
    print(line, flush=True)
    open(LOG, "a").write(line + "\n")

def check_port(port):
    try:
        r = urllib.request.urlopen(f"http://localhost:{port}", timeout=3)
        html = r.read(800).decode()
        t = re.search(r'<title>(.*?)</title>', html, re.I)
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
    except:
        return False

def kill_port(port):
    subprocess.run(["fuser", "-k", f"{port}/tcp"], capture_output=True)
    time.sleep(1)

def start_server(port, dist_dir):
    if not os.path.exists(dist_dir):
        return False
    log_file = f"/tmp/server-{port}.log"
    subprocess.Popen(
        ["python3", "-m", "http.server", str(port), "--directory", dist_dir],
        stdout=open(log_file, "w"), stderr=subprocess.STDOUT,
        start_new_session=True
    )
    time.sleep(1)
    code, title = check_port(port)
    return code == 200, title

def ollama(prompt, tokens=3000):
    system = ("You are the SpiderNetOS Mechanic. Generate ONLY clean valid code. "
              "Vue 3 <script setup>, Tailwind CSS. Dark theme. No markdown, no explanations.")
    data = json.dumps({
        "model": MODEL, "prompt": f"{system}\n\n{prompt}\n\nOnly code:",
        "stream": False, "options": {"temperature": 0.05, "num_predict": tokens}
    }).encode()
    req = urllib.request.Request(f"{OLLAMA}/api/generate", data=data, headers={"Content-Type": "application/json"})
    try:
        return json.loads(urllib.request.urlopen(req, timeout=300).read().decode()).get("response", "")
    except Exception as e:
        log(f"Ollama error: {e}", "ERROR")
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

def npm_build(site_dir):
    log(f"Building {site_dir}...")
    if not os.path.exists(os.path.join(site_dir, "node_modules")):
        r = subprocess.run(["npm", "install"], cwd=site_dir, capture_output=True, text=True, timeout=120)
        if r.returncode != 0:
            return False, r.stderr[-500:]
    r = subprocess.run(["npm", "run", "build"], cwd=site_dir, capture_output=True, text=True, timeout=120)
    if r.returncode != 0:
        return False, r.stderr[-800:]
    return True, ""

def fix_file(site_dir, filename, prompt, tokens=3000):
    log(f"Generating {filename} via {MODEL}...")
    raw = ollama(prompt, tokens)
    if raw:
        code = clean(raw, is_vue=filename.endswith(".vue"))
        path = os.path.join(site_dir, filename)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        open(path, "w").write(code)
        log(f"Wrote {path} ({len(code)} chars)")
        return True
    return False

def fix_site(name, info):
    site_dir = info["dir"]
    dist_dir = os.path.join(site_dir, "dist")

    # Repository cockpit — only npm build; never overwrite src/ with landing stubs.
    if os.path.normpath(site_dir) == os.path.normpath(f"{BASE}/cockpit"):
        if not os.path.isfile(os.path.join(site_dir, "package.json")):
            log(f"{name}: cockpit missing package.json", "ERROR")
            return False
        if os.path.isdir(dist_dir) and os.listdir(dist_dir):
            ok, err = npm_build(site_dir)
            if ok:
                log(f"{name}: cockpit dist valid")
                return True
            log(f"{name}: cockpit rebuild after failed check: {err[-300:]}", "WARN")
        ok, err = npm_build(site_dir)
        if not ok:
            log(f"{name}: cockpit build failed: {err[-300:]}", "ERROR")
            return False
        log(f"{name}: cockpit build OK")
        return True

    # Check if dist exists and is valid
    if os.path.exists(dist_dir):
        files = os.listdir(dist_dir)
        if files:
            # Just verify it builds, no need to regenerate
            ok, err = npm_build(site_dir)
            if ok:
                log(f"{name}: dist valid ({len(files)} files)")
                return True
            log(f"{name}: Build failed: {err[-300:]}", "WARN")

    log(f"{name}: Rebuilding...")
    # Ensure CSS exists
    css_dir = os.path.join(site_dir, "src/styles")
    os.makedirs(css_dir, exist_ok=True)
    css = ":root { --color-primary: #FF6B2C; --color-bg: #0A0A0F; --color-card: #1A1A24; }\n"
    open(os.path.join(css_dir, "orange-theme.css"), "w").write(css)

    # Fix main.js
    main = "import { createApp } from 'vue'\nimport App from './App.vue'\ncreateApp(App).mount('#app')\n"
    open(os.path.join(site_dir, "src/main.js"), "w").write(main)

    if name == "customer":
        fix_file(site_dir, "src/App.vue",
            "Vue 3 customer dashboard. Left sidebar: nav items Dashboard/Agents/Flows/Approvals(badge 3)/Traces/Usage/Memory/Settings with emojis. "
            "Sidebar bottom shows budget $34.50/$50.00 with orange progress bar. Main: top bar + content area. "
            "Import Dashboard from ./views/Dashboard.vue. Dark #0F0F14. Tailwind. script setup.", 3500)
        fix_file(site_dir, "src/views/Dashboard.vue",
            "Vue 3 dashboard. 3 stat cards: System Status green Operational, Active Agents 12 +3 today, Revenue Today $2,847 +12%. "
            "Below: recent events list with timestamps and status dots. Dark #0F0F14. Orange #FF6B2C. Tailwind.", 2500)

    # Rebuild
    ok, err = npm_build(site_dir)
    if not ok:
        log(f"{name}: Build still failing: {err[-300:]}", "ERROR")
        return False
    log(f"{name}: Build OK")
    return True

def manage_server(name, info):
    port = info["port"]
    dist_dir = os.path.join(info["dir"], "dist")
    code, title = check_port(port)

    if code == 200 and info["check"].lower() in title.lower():
        log(f"{name}: Port {port} HEALTHY - \"{title}\"")
        return True

    log(f"{name}: Port {port} needs restart (code={code}, title=\"{title}\")", "WARN")

    # Check if port is actually occupied
    if not is_free(port):
        log(f"{name}: Port occupied, killing...")
        kill_port(port)

    ok, title = start_server(port, dist_dir)
    if ok:
        log(f"{name}: Server started on {port} - \"{title}\"")
        return True
    else:
        log(f"{name}: Server FAILED to start: {title}", "ERROR")
        return False

def main():
    log("=" * 50)
    log("SPIDERNETOS MECHANIC AGENT v2 - RUNNING")
    log("=" * 50)

    # Check Ollama
    try:
        r = urllib.request.urlopen(f"{OLLAMA}/api/tags", timeout=5)
        models = json.loads(r.read().decode())
        names = [m["name"] for m in models.get("models", [])]
        log(f"Ollama ready: {', '.join(names)}")
    except Exception as e:
        log(f"Ollama unavailable: {e}", "ERROR")
        # Continue anyway - may not need to regenerate

    all_ok = True
    for name, info in SITES.items():
        # Step 1: Ensure site builds
        if not os.path.exists(os.path.join(info["dir"], "dist", "index.html")):
            log(f"{name}: No dist found, fixing...")
            if not fix_site(name, info):
                all_ok = False
                continue

        # Step 2: Ensure server running
        if not manage_server(name, info):
            all_ok = False

    log("=" * 50)
    log(f"STATUS: {'ALL SYSTEMS NOMINAL' if all_ok else 'ISSUES PERSIST'}")
    log("=" * 50)

    for name, info in SITES.items():
        log(f"  {info['domain']} -> http://220.134.41.156:{info['port']}")

    return all_ok

if __name__ == "__main__":
    sys.exit(0 if main() else 1)
