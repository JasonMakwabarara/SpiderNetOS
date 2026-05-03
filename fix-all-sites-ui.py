#!/usr/bin/env python3
"""Fix all three SpiderNetOS sites with proper UI/UX"""
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
landing_prompt = '''Create a stunning, professional Vue 3 landing page (App.vue) for SpiderNetOS.

COLOR SCHEME (MUST USE EXACTLY):
- Background: bg-gradient-to-b from-[#0A0A0F] via-[#12121A] to-[#1A1A2E]
- Primary: #FF6B2C (vibrant orange)
- Secondary: #00D4FF (electric cyan)  
- Accent: #7C3AED (purple)
- Text Primary: #FFFFFF
- Text Secondary: #9CA3AF (gray-400)
- Glass: bg-white/5 backdrop-blur-lg border border-white/10

TYPOGRAPHY:
- H1: text-6xl font-extrabold tracking-tight
- H2: text-4xl font-bold
- Body: text-lg leading-relaxed
- Font: Inter/system-ui

LAYOUT SECTIONS:

1. NAVBAR (sticky, glass):
   - Logo: Spider icon + "SpiderNetOS" 
   - Links: Docs, GitHub, Community
   - CTA: "Launch App" button (orange)

2. HERO (min-h-screen, centered):
   - Animated particle network (50 dots, connecting lines, mouse parallax)
   - Badge: "Web3 Infrastructure" with pulse dot
   - H1: "The Neural OS for\nAutonomous Agents" (gradient text)
   - Subtitle: "Deploy, scale, and monetize AI agents across a decentralized compute mesh."
   - CTA Group: 
     * Primary: "Launch Console" (bg-orange-500, glow shadow)
     * Secondary: "Read Docs" (border, hover fill)
   - Trust badges: "Backed by" with placeholder logos
   - Scroll indicator (animated chevron)

3. FEATURES (py-24):
   - Section header with eyebrow text
   - 3 cards grid with glass effect:
     * BUILD: Hammer icon, "Create Agents", description, "Learn more →" link
     * SELL: Chart icon, "Monetize Compute", description
     * SCALE: Layers icon, "Infinite Scaling", description
   - Hover: translateY(-8px), border glow

4. METRICS (py-20, gradient bg):
   - 4 stats with animated counters:
     * 2.4M+ Active Agents
     * $840M Total Value Locked
     * 99.97% Uptime
     * 10K+ Compute Nodes
   - Each with icon and +growth indicator

5. HOW IT WORKS (py-24):
   - 3-step timeline with connecting line
   - Step 1: Deploy (rocket icon)
   - Step 2: Scale (zap icon)
   - Step 3: Earn (coins icon)

6. CTA SECTION (py-24):
   - Large card with gradient
   - "Ready to build the future?"
   - Email input + "Get Early Access" button

7. FOOTER:
   - 4-column layout: Product, Resources, Company, Connect
   - Social icons
   - Copyright

ANIMATIONS (CRITICAL):
- Particle canvas with requestAnimationFrame
- Intersection Observer for scroll reveals
- Hover transforms on all interactive elements
- Button ripple effects
- Counter animations for metrics

CODE RULES:
- Every tag must close
- Use <template>, <script setup>, <style scoped>
- Tailwind classes only
- No unclosed divs/sections'''

landing_code = clean_vue_code(ollama_generate(landing_prompt, tokens=8000))
with open(f"{BASE}/sites/landing/src/App.vue", "w") as f:
    f.write(landing_code)
print(f"Landing: {len(landing_code)} chars")
build_site(f"{BASE}/sites/landing")

# ============ CUSTOMER COCKPIT ============
print("\n=== GENERATING CUSTOMER COCKPIT ===")
customer_prompt = '''Create a professional Vue 3 dashboard application (App.vue) for SpiderNetOS Customer Cockpit.

COLOR SCHEME:
- Background: bg-[#0F0F14]
- Sidebar: bg-[#1A1A24] border-r border-white/5
- Cards: bg-[#1E1E2D] rounded-xl border border-white/5
- Primary: #FF6B2C (orange for actions)
- Success: #10B981 (green for status)
- Warning: #F59E0B (amber for alerts)
- Danger: #EF4444 (red for errors)
- Text: white primary, gray-400 secondary

LAYOUT:
- Full h-screen flex layout
- Sidebar (w-64, fixed):
  * Logo + "SpiderNet" at top
  * Nav items with icons: Dashboard, My Agents, Workflows, Marketplace, Analytics
  * Badge counts on items (e.g., "3" on Approvals)
  * Bottom: Budget progress bar ($847 / $1,000)

- Top Bar (sticky, glass):
  * Breadcrumb: "Customer / Dashboard"
  * Search input with icon
  * Notification bell with badge
  * User avatar dropdown

- Main Content (flex-1, overflow-auto):
  * Page header: "Dashboard" + "Overview of your agent fleet"
  * Quick actions row: 4 buttons (Deploy Agent, Create Flow, View Logs, Settings)
  
  * Stats Grid (4 cards):
    - System Status (green dot, "Operational")
    - Active Agents ("12", "+3 this week", icon)
    - Revenue Today ("$2,847", "+12%", chart mini)
    - Compute Used ("78%", progress bar)
  
  * Two-column section:
    - Left: Recent Events list (timestamp, event, status badge)
    - Right: Agent Performance chart placeholder

INTERACTIONS:
- Sidebar hover states
- Card hover lift
- Button press effects
- Status indicators pulse

CODE RULES:
- All tags must close
- Use <template>, <script setup>, <style scoped>
- Include mock data in script
- Tailwind classes only'''

customer_code = clean_vue_code(ollama_generate(customer_prompt, tokens=8000))
with open(f"{BASE}/sites/customer-cockpit/src/App.vue", "w") as f:
    f.write(customer_code)
print(f"Customer: {len(customer_code)} chars")
build_site(f"{BASE}/sites/customer-cockpit")

# ============ INTERNAL COCKPIT ============
print("\n=== GENERATING INTERNAL COCKPIT ===")
internal_prompt = '''Create an admin/internal dashboard (App.vue) for SpiderNetOS Operations Cockpit.

COLOR SCHEME:
- Background: bg-[#0A0A0F]
- Surface: bg-[#141420] rounded-xl
- Primary: #FF6B2C (orange)
- Danger: #DC2626 (red for critical alerts)
- Success: #059669 (green)
- Warning: #D97706 (amber)
- Text: white, gray-300, gray-500

LAYOUT:
- Full h-screen
- Top Navigation (h-16, border-b):
  * Logo + "SpiderNet Ops"
  * Environment selector (Production, Staging, Dev)
  * Alert bell (critical count badge)
  * User menu

- Sidebar (w-56):
  * System Health (with live indicator)
  * Nodes, Pods, Services, Ingress
  * Security, Cost, Logs, Settings

- Main Dashboard:
  * Header: "System Overview" + Live indicator + Refresh button
  
  * Critical Alerts Banner (if any):
    - Red background, warning icon
    - "3 Critical Issues" with View button
  
  * Metrics Row (5 mini cards):
    - Node Count: "47/50 Online"
    - Pod Count: "1,247 Running"
    - CPU Usage: "67%" with sparkline
    - Memory: "82%" with warning color
    - Network: "2.4 Gbps"
  
  * Grid (2 columns):
    - Left: Cluster Topology (visual representation)
    - Right: Recent Events table (timestamp, severity, message, source)
  
  * Bottom Row:
    - Resource Usage (bars for each namespace)
    - Top Pods by CPU (list with percentages)

INTERACTIONS:
- Live indicator pulsing
- Table row hover highlights
- Card hover effects
- Alert dismiss buttons

CODE RULES:
- All tags must close
- Use proper Vue 3 <script setup>
- Include realistic mock data
- Tailwind classes throughout'''

internal_code = clean_vue_code(ollama_generate(internal_prompt, tokens=8000))
with open(f"{BASE}/cockpit/src/App.vue", "w") as f:
    f.write(internal_code)
print(f"Internal: {len(internal_code)} chars")
build_site(f"{BASE}/cockpit")

print("\n=== ALL SITES UPDATED ===")
print("\nRestart servers:")
print("pkill -f 'http.server' && cd /workspace/SpiderNetOS/sites/landing/dist && python3 -m http.server 3000 --bind 0.0.0.0 &")
print("cd /workspace/SpiderNetOS/sites/customer-cockpit/dist && python3 -m http.server 3001 --bind 0.0.0.0 &")
print("cd /workspace/SpiderNetOS/cockpit/dist && python3 -m http.server 3002 --bind 0.0.0.0 &")
