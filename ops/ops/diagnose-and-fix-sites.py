#!/usr/bin/env python3
"""
SpiderNetOS Site Diagnostic & Repair Tool
Run on Vast.ai to diagnose and fix site accessibility issues
"""

import json
import os
import subprocess
import sys
import time
import urllib.request
import socket

BASE = "/workspace/SpiderNetOS"
OLLAMA = "http://localhost:11434"
MODEL = "gemma4:31b"

# Site configurations
SITES = {
    3000: {
        "name": "landing",
        "dist": f"{BASE}/sites/landing/dist",
        "title": "SpiderNetOS",
        "domain": "www.spidernetos.com"
    },
    3001: {
        "name": "customer",
        "dist": f"{BASE}/sites/customer-cockpit/dist", 
        "title": "SpiderNetOS",
        "domain": "app.spidernetos.com"
    },
    3002: {
        "name": "internal",
        "dist": f"{BASE}/cockpit/dist",
        "title": "Cockpit",
        "domain": "cockpit.internal.spidernetos.com"
    }
}

def log(msg, lvl="INFO"):
    line = f"[{time.strftime('%H:%M:%S')}] [{lvl}] {msg}"
    print(line, flush=True)

def check_local(port):
    """Check if site responds locally"""
    try:
        r = urllib.request.urlopen(f"http://localhost:{port}", timeout=5)
        h = r.read(1000).decode()
        t = re.search(r'<title>(.*?)</title>', h, re.I)
        return r.getcode(), t.group(1) if t else "no title", len(h)
    except Exception as e:
        return None, str(e), 0

def check_network_binding(port):
    """Check what address the server is binding to"""
    try:
        # Check if binding to 0.0.0.0 or 127.0.0.1
        result = subprocess.run(
            ["netstat", "-tlnp"], 
            capture_output=True, 
            text=True,
            timeout=5
        )
        lines = result.stdout.split('\n')
        for line in lines:
            if f":{port}" in line:
                return line.strip()
        return "Not found in netstat"
    except Exception as e:
        return f"Error: {e}"

def get_public_ip():
    """Get the instance public IP"""
    try:
        # Try multiple services
        services = [
            "https://api.ipify.org",
            "https://ifconfig.me",
            "https://icanhazip.com"
        ]
        for service in services:
            try:
                r = urllib.request.urlopen(service, timeout=5)
                return r.read().decode().strip()
            except:
                continue
        return "Unknown"
    except:
        return "Unknown"

def kill_existing_servers():
    """Kill any existing Python http.server processes"""
    log("Killing existing servers...")
    subprocess.run(["pkill", "-f", "http.server"], capture_output=True)
    time.sleep(2)

def start_server_on_all_interfaces(port, dist_dir):
    """Start server binding to 0.0.0.0 (all interfaces)"""
    if not os.path.exists(dist_dir):
        return False, "dist directory missing"
    
    # Check for index.html
    if not os.path.exists(os.path.join(dist_dir, "index.html")):
        return False, "index.html missing"
    
    log_file = f"/tmp/server-{port}.log"
    
    # Start with explicit 0.0.0.0 binding
    proc = subprocess.Popen(
        ["python3", "-m", "http.server", str(port), "--directory", dist_dir, "--bind", "0.0.0.0"],
        stdout=open(log_file, "w"),
        stderr=subprocess.STDOUT,
        start_new_session=True
    )
    
    # Wait for startup
    time.sleep(2)
    
    # Verify it's running and accessible
    code, title, size = check_local(port)
    if code == 200:
        return True, f"Running on 0.0.0.0:{port}"
    else:
        return False, f"Failed to start: {title}"

def check_cloudflare_tunnel():
    """Check if cloudflared is running"""
    try:
        result = subprocess.run(
            ["pgrep", "-a", "cloudflared"],
            capture_output=True,
            text=True
        )
        if result.returncode == 0:
            return True, result.stdout.strip()
        return False, "cloudflared not running"
    except Exception as e:
        return False, str(e)

def generate_vue_component(site_name, prompt, site_dir):
    """Generate Vue component using Ollama"""
    log(f"Generating {site_name} with {MODEL}...")
    
    system = """You are the SpiderNetOS Code Architect. 
Generate production-ready Vue 3 components using <script setup>.
Use Tailwind CSS with dark theme.
CRITICAL: Every HTML tag must have matching closing tag."""
    
    full_prompt = f"{system}\n\n{prompt}\n\nGenerate ONLY valid Vue code:"
    
    data = json.dumps({
        "model": MODEL,
        "prompt": full_prompt,
        "stream": False,
        "options": {"temperature": 0.05, "num_predict": 4000}
    }).encode()
    
    try:
        req = urllib.request.Request(
            f"{OLLAMA}/api/generate",
            data=data,
            headers={"Content-Type": "application/json"},
            method="POST"
        )
        
        with urllib.request.urlopen(req, timeout=300) as r:
            result = json.loads(r.read().decode())
            raw_code = result.get("response", "")
            
            # Clean up code
            lines = raw_code.split("\n")
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
            
            # Ensure template wrapper
            if not code.startswith(("<template>", "<script", "<style")):
                if "<div" in code:
                    code = f"<template>\n{code}\n</template>"
            
            # Write file
            os.makedirs(os.path.join(site_dir, "src"), exist_ok=True)
            app_path = os.path.join(site_dir, "src/App.vue")
            with open(app_path, "w") as f:
                f.write(code)
            
            log(f"Generated {len(code)} chars for {site_name}")
            return True
    except Exception as e:
        log(f"Generation failed: {e}", "ERROR")
        return False

def rebuild_site(site_config):
    """Rebuild a site from scratch"""
    site_dir = os.path.dirname(site_config["dist"])
    name = site_config["name"]
    
    log(f"Rebuilding {name}...")
    
    # Create base structure
    os.makedirs(os.path.join(site_dir, "src/styles"), exist_ok=True)
    
    # Create CSS
    css = """:root {
  --color-primary: #FF6B2C;
  --color-bg: #0A0A0F;
  --color-card: #1A1A24;
}
body {
  background-color: var(--color-bg);
  color: #E8E8EF;
}
"""
    with open(os.path.join(site_dir, "src/styles/theme.css"), "w") as f:
        f.write(css)
    
    # Create main.js
    main_js = """import { createApp } from 'vue'
import App from './App.vue'
createApp(App).mount('#app')
"""
    with open(os.path.join(site_dir, "src/main.js"), "w") as f:
        f.write(main_js)
    
    # Generate App.vue based on site type
    if name == "landing":
        prompt = """Create a Vue 3 landing page for "SpiderNetOS" - an autonomous scaling platform.

Design:
- Dark background #0A0A0F
- Orange accent #FF6B2C
- Hero section with animated particle canvas (40 dots, connecting lines)
- Title "SpiderNetOS" with orange gradient
- Subtitle about autonomous business scaling
- 3 feature cards: BUILD (rocket emoji), SELL (chart emoji), SCALE (globe emoji)
- Stats: 2.4M Agents, $840M Revenue, 99.97% Uptime
- Orange CTA button "Get Started"
- Footer

Use Tailwind CSS classes. Every tag must close properly."""
        
    elif name == "customer":
        prompt = """Create a Vue 3 customer dashboard for SpiderNetOS.

Design:
- Dark theme #0A0A0F sidebar, #0F0F14 main area
- Left sidebar: Logo, nav items with icons (Dashboard 🏠, Agents 🤖, Flows 🔄, Approvals ⚡ with badge "3", Usage 💰, Settings ⚙️)
- Budget indicator at bottom: $34.50 / $50.00 with orange progress bar
- Main area: Top bar with search, 3 stat cards (System Status 🟢, Active Agents 12, Revenue $2.4K)
- Recent events table

All elements must have proper closing tags."""
        
    elif name == "internal":
        prompt = """Create a Vue 3 internal cockpit for SpiderNetOS operations team.

Design:
- Dark theme #0A0A0F
- Header: System health status, alerts count
- 4 metric cards: Active Tenants, Running Agents, Queue Depth, Error Rate
- 2 charts areas (placeholder divs)
- Recent alerts list with severity colors
- Action buttons: Deploy, Scale, Rollback

Every HTML tag must close."""
    else:
        prompt = f"Create a Vue 3 app for {name}. Dark theme, orange accents."
    
    if not generate_vue_component(name, prompt, site_dir):
        return False
    
    # Install dependencies
    if not os.path.exists(os.path.join(site_dir, "node_modules")):
        log(f"Installing npm dependencies for {name}...")
        result = subprocess.run(
            ["npm", "install"],
            cwd=site_dir,
            capture_output=True,
            text=True,
            timeout=180
        )
        if result.returncode != 0:
            log(f"npm install failed: {result.stderr[-200:]}", "ERROR")
            return False
    
    # Build
    log(f"Building {name}...")
    result = subprocess.run(
        ["npm", "run", "build"],
        cwd=site_dir,
        capture_output=True,
        text=True,
        timeout=180
    )
    
    if result.returncode != 0:
        log(f"Build failed: {result.stderr[-300:]}", "ERROR")
        return False
    
    log(f"{name} rebuilt successfully")
    return True

def main():
    log("=" * 60)
    log("SPIDERNETOS SITE DIAGNOSTIC & REPAIR")
    log("=" * 60)
    
    # Get public IP
    public_ip = get_public_ip()
    log(f"Instance public IP: {public_ip}")
    
    # Check cloudflared
    cf_running, cf_status = check_cloudflare_tunnel()
    log(f"Cloudflare tunnel: {'Running' if cf_running else 'NOT RUNNING'} - {cf_status[:100]}")
    
    # Kill existing servers
    kill_existing_servers()
    
    # Check and fix each site
    all_ok = True
    for port, config in SITES.items():
        log(f"\n--- Checking {config['name']} (port {port}) ---")
        
        # Check local accessibility
        code, title, size = check_local(port)
        log(f"Local check: HTTP {code}, Title: {title[:50]}, Size: {size} bytes")
        
        # Check network binding
        binding = check_network_binding(port)
        log(f"Network binding: {binding[:100]}")
        
        # If not accessible, try to fix
        if code != 200:
            log(f"Site {config['name']} not accessible locally. Attempting rebuild...")
            
            # Check if dist exists
            if not os.path.exists(config["dist"]):
                log(f"dist/ missing for {config['name']}")
                if not rebuild_site(config):
                    all_ok = False
                    continue
            elif not os.path.exists(os.path.join(config["dist"], "index.html")):
                log(f"index.html missing for {config['name']}")
                if not rebuild_site(config):
                    all_ok = False
                    continue
            
            # Start server on 0.0.0.0
            success, msg = start_server_on_all_interfaces(port, config["dist"])
            log(f"Server start: {msg}")
            if not success:
                all_ok = False
        else:
            # Already running, check if on 0.0.0.0
            if "0.0.0.0" not in binding and ":::" not in binding:
                log(f"WARNING: Server may only be on localhost. Restarting on 0.0.0.0...")
                kill_existing_servers()
                time.sleep(2)
                success, msg = start_server_on_all_interfaces(port, config["dist"])
                log(f"Restart: {msg}")
    
    # Final summary
    log("\n" + "=" * 60)
    log("DIAGNOSTIC SUMMARY")
    log("=" * 60)
    
    for port, config in SITES.items():
        code, title, _ = check_local(port)
        status = "✓ OK" if code == 200 else "✗ FAIL"
        log(f"{status} {config['name']}: localhost:{port} -> {config['domain']}")
    
    log(f"\nPublic IP: {public_ip}")
    log(f"Cloudflare: {'Running' if cf_running else 'NOT RUNNING - Run cloudflared!'}")
    
    if not cf_running:
        log("\n⚠️  WARNING: Cloudflare tunnel not running!")
        log("Sites won't be accessible from custom domains.")
        log("\nTo fix, run:")
        log("  cloudflared tunnel run spidernetos")
    
    log("\nDirect access URLs (for testing):")
    for port, config in SITES.items():
        log(f"  http://{public_ip}:{port} (bypass Cloudflare)")
    
    log("=" * 60)
    
    return all_ok

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1)
