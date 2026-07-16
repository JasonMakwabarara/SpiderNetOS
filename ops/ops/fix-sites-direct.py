#!/usr/bin/env python3
"""Fix all three SpiderNetOS sites with proper UI/UX - DIRECT VERSION"""
import json, os, urllib.request, subprocess

OLLAMA = "http://localhost:11434"
MODEL = "gemma4:31b"
BASE = "/workspace/SpiderNetOS"

def ollama_generate(prompt, tokens=8000):
    data = json.dumps({
        "model": MODEL,
        "prompt": prompt,
        "stream": False,
        "options": {"temperature": 0.02, "num_predict": tokens}
    }).encode()
    
    req = urllib.request.Request(
        f"{OLLAMA}/api/generate",
        data=data,
        headers={"Content-Type": "application/json"}
    )
    
    try:
        r = urllib.request.urlopen(req, timeout=300)
        result = json.loads(r.read().decode())
        return result.get("response", "")
    except Exception as e:
        print(f"Error: {e}")
        return ""

def clean_vue_code(raw):
    if not raw:
        return ""
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
    return "\n".join(lines[s:e]).strip()

def build_site(site_dir):
    if not os.path.exists(os.path.join(site_dir, "node_modules")):
        print("  Installing npm dependencies...")
        subprocess.run(["npm", "install"], cwd=site_dir, capture_output=True, timeout=120)
    print("  Building...")
    result = subprocess.run(["npm", "run", "build"], cwd=site_dir, capture_output=True, text=True, timeout=120)
    if result.returncode != 0:
        print(f"  Build error: {result.stderr[-300:]}")
        return False
    print("  Build OK")
    return True

# ============ LANDING PAGE ============
print("\n=== GENERATING LANDING PAGE ===")
landing_prompt = '''Create a stunning Vue 3 landing page (App.vue) for SpiderNetOS using <script setup>.

COLORS:
- bg-gradient-to-b from-[#0A0A0F] via-[#12121A] to-[#1A1A2E]
- Orange: #FF6B2C for CTAs
- Cyan: #00D4FF for accents
- Text: white, gray-400

LAYOUT:
1. Fixed navbar with logo, links, orange "Launch App" button
2. Hero (h-screen): Canvas particle animation (50 dots, connecting lines), gradient title "The Neural OS for Autonomous Agents", subtitle, two CTAs
3. Features (3 cards): BUILD (hammer), SELL (coins), SCALE (rocket) with glassmorphism effect
4. Metrics row: 2.4M+, $840M, 99.97%, 10K+
5. Footer

CODE RULES: All tags must close. Use <template>, <script setup>, <style scoped>. Tailwind classes only.'''

landing_code = clean_vue_code(ollama_generate(landing_prompt, tokens=8000))
if landing_code and len(landing_code) > 100:
    with open(f"{BASE}/sites/landing/src/App.vue", "w") as f:
        f.write(landing_code)
    print(f"Landing: {len(landing_code)} chars")
    build_site(f"{BASE}/sites/landing")
else:
    print("Landing generation failed - keeping existing")

# ============ CUSTOMER COCKPIT ============
print("\n=== GENERATING CUSTOMER COCKPIT ===")
customer_prompt = '''Create a Vue 3 dashboard (App.vue) for SpiderNetOS Customer Cockpit using <script setup>.

COLORS:
- Background: bg-[#0F0F14]
- Sidebar: bg-[#1A1A24]
- Cards: bg-[#1E1E2D] rounded-xl border border-white/5
- Primary: #FF6B2C, Success: #10B981, Text: white/gray-400

LAYOUT:
- h-screen flex
- Sidebar (w-64): Logo, nav items with icons (Dashboard, Agents, Workflows, Analytics), budget progress bar at bottom
- Main: Top bar with search + notifications, "Dashboard" header
- Stats cards (4): System Status (green), Active Agents (12), Revenue ($2,847), Compute Used (78%)
- Recent Events list (4 items with timestamps and status badges)

CODE RULES: All tags close. Use <template>, <script setup>. Include mock data in script.'''

customer_code = clean_vue_code(ollama_generate(customer_prompt, tokens=8000))
if customer_code and len(customer_code) > 100:
    with open(f"{BASE}/sites/customer-cockpit/src/App.vue", "w") as f:
        f.write(customer_code)
    print(f"Customer: {len(customer_code)} chars")
    build_site(f"{BASE}/sites/customer-cockpit")
else:
    print("Customer generation failed - keeping existing")

# ============ INTERNAL COCKPIT ============
print("\n=== GENERATING INTERNAL COCKPIT ===")
internal_prompt = '''Create an admin dashboard (App.vue) for SpiderNetOS Internal Cockpit using <script setup>.

COLORS:
- Background: bg-[#0A0A0F]
- Surface: bg-[#141420] rounded-xl
- Primary: #FF6B2C, Danger: #DC2626, Success: #059669
- Text: white, gray-300

LAYOUT:
- h-screen
- Top bar: Logo "SpiderNet Ops", environment selector, alerts bell, user menu
- Sidebar: System Health (live indicator), Nodes, Pods, Services, Security, Logs
- Main:
  * Header: "System Overview" + Live indicator
  * Critical alerts banner (if issues)
  * Metrics row (5): Nodes (47/50), Pods (1,247), CPU (67%), Memory (82%), Network (2.4 Gbps)
  * Recent Events table (timestamp, severity, message)

CODE RULES: All tags close. Use <template>, <script setup>. Realistic mock data.'''

internal_code = clean_vue_code(ollama_generate(internal_prompt, tokens=8000))
if internal_code and len(internal_code) > 100:
    with open(f"{BASE}/cockpit/src/App.vue", "w") as f:
        f.write(internal_code)
    print(f"Internal: {len(internal_code)} chars")
    build_site(f"{BASE}/cockpit")
else:
    print("Internal generation failed - keeping existing")

print("\n=== ALL SITES UPDATED ===")
print("\nRestart servers:")
print("pkill -f 'http.server 300[0-2]'")
print("cd /workspace/SpiderNetOS/sites/landing/dist && python3 -m http.server 3000 --bind 0.0.0.0 &")
print("cd /workspace/SpiderNetOS/sites/customer-cockpit/dist && python3 -m http.server 3001 --bind 0.0.0.0 &")
print("cd /workspace/SpiderNetOS/cockpit/dist && python3 -m http.server 3002 --bind 0.0.0.0 &")
