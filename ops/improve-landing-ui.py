#!/usr/bin/env python3
"""Improve SpiderNetOS landing page UI/UX"""
import json, os, urllib.request

OLLAMA = "http://localhost:11434"
MODEL = "gemma4:31b"
BASE = "/workspace/SpiderNetOS"
SITE_DIR = f"{BASE}/sites/landing"

def ollama_generate(prompt, tokens=6000):
    data = json.dumps({
        "model": MODEL,
        "prompt": prompt,
        "stream": False,
        "options": {"temperature": 0.03, "num_predict": tokens}
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

# Enhanced UI/UX prompt
prompt = """Create a stunning, modern Vue 3 landing page (App.vue) for SpiderNetOS using <script setup> and Tailwind CSS.

## DESIGN SYSTEM:
- Background: Deep space gradient #0A0A0F to #141420
- Primary: Vibrant orange #FF6B2C with glow effects
- Secondary: Electric cyan #00D4FF for accents
- Text: Pure white #FFFFFF, muted gray #9CA3AF for secondary
- Cards: Glassmorphism effect - bg-white/5 backdrop-blur-md border border-white/10

## LAYOUT STRUCTURE:

### HERO SECTION (h-screen):
- Animated particle network (60 particles, 3 connection layers)
- Centered content with staggered fade-in animation
- H1: "SpiderNetOS" - gradient text (orange to cyan), font-weight 800, tracking-tight
- Tagline: "The Neural Operating System for Autonomous Intelligence" - text-xl text-gray-400
- CTA Buttons: "Launch Console" (orange glow), "Read Manifesto" (outline with hover fill)
- Scroll indicator with bounce animation

### FEATURE GRID:
3 cards with hover lift effect (transform -translate-y-2, shadow glow):
1. BUILD - Icon: Hammer/Zap, Title in gradient, Description about agent creation
2. SELL - Icon: Coins/Chart, Marketplace monetization description  
3. SCALE - Icon: Layers/Rocket, Infinite scaling description

### LIVE METRICS (animated counter):
- 2.4M Active Agents (count-up animation)
- $840M Total Value Locked (with pulse)
- 99.97% Uptime (with live indicator dot)

### FOOTER:
- Subtle border-top
- Social links with hover effects
- Copyright

## INTERACTIONS:
- Particle network: Mouse parallax effect
- Cards: Hover scale + glow border
- Buttons: Ripple effect on click
- Metrics: Intersection Observer triggered count-up
- Smooth scroll behavior

## CODE RULES:
- All tags must close properly
- Use self-closing for: img, input, br
- Every <div> needs </div>
- No unclosed templates or sections
- Include proper <template>, <script setup>, <style scoped> blocks

Generate the complete App.vue file."""

print("Generating improved landing page with better UI/UX...")
raw = ollama_generate(prompt, tokens=6000)
code = clean_vue_code(raw)

# Save
app_path = f"{SITE_DIR}/src/App.vue"
with open(app_path, "w") as f:
    f.write(code)

print(f"✓ Generated: {app_path} ({len(code)} chars)")
print("\nBuilding...")

# Build
os.chdir(SITE_DIR)
os.system("npm run build")

print("\n✓ Landing page rebuilt with improved UI/UX!")
print("Restart your server or run: pkill -f 'http.server 3000'")
print("Then start again: python3 -m http.server 3000 --bind 0.0.0.0")
