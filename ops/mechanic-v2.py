#!/usr/bin/env python3
"""SpiderNetOS Mechanic v2 - Autonomous Health Monitor with Code Quality Validation"""
import json, os, re, subprocess, sys, time, urllib.request, socket

OLLAMA = "http://localhost:11434"
MODEL = "gemma4:31b"  # Primary: local model (DeepSeek v4 requires subscription)
FALLBACK_MODEL = "qwen3.6:latest"  # Fallback if gemma4 unavailable
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
            return models[0] if models else FALLBACK_MODEL
    except Exception as e:
        log(f"Model check failed: {e}", "WARN")
        return FALLBACK_MODEL

def ollama_generate(prompt, tokens=4000, model=None, temp=0.03):
    """Generate code using DeepSeek (or fallback)"""
    use_model = model or check_model_available()
    
    data = json.dumps({
        "model": use_model,
        "prompt": prompt,
        "stream": False,
        "options": {"temperature": temp, "num_predict": tokens}
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
        log(f"Ollama error: {e}", "ERROR")
        return ""

def validate_code_quality(code: str, component_type: str = "vue") -> dict:
    """
    Use DeepSeek to validate generated code before writing to disk.
    Returns validation report with issues and fixes.
    """
    if not code or len(code) < 100:
        return {"valid": False, "issues": ["Generated code too short"], "fixes": []}
    
    # Quick syntax check
    validation_prompt = f"""You are a code validator. Analyze this {component_type} code for:

1. Syntax errors
2. Missing closing tags (div, span, section, template, script, style)
3. Unclosed HTML elements
4. Missing imports
5. Undefined variables
6. Invalid Vue 3 composition API usage

CODE TO VALIDATE:
```{component_type}
{code[:3000]}  # First 3000 chars to fit context
```

Respond with JSON only:
{{
    "valid": true/false,
    "issues": ["specific issue 1", "issue 2"],
    "severity": "critical|warning|info",
    "suggested_fix": "brief description of fix needed"
}}"""

    validation = ollama_generate(validation_prompt, tokens=1000, temp=0.1)
    
    try:
        # Extract JSON from response
        json_match = re.search(r'\{[^}]*\}', validation, re.DOTALL)
        if json_match:
            result = json.loads(json_match.group())
            return result
    except:
        pass
    
    # Fallback validation
    issues = []
    if code.count("<div") != code.count("</div>"):
        issues.append("Unclosed div tags")
    if code.count("<span") != code.count("</span>"):
        issues.append("Unclosed span tags")
    if code.count("<template") != code.count("</template>"):
        issues.append("Unclosed template tags")
    
    return {
        "valid": len(issues) == 0,
        "issues": issues,
        "severity": "critical" if issues else "info",
        "suggested_fix": "Fix unclosed tags"
    }

def auto_fix_code(code: str, validation: dict, component_type: str = "vue") -> str:
    """Attempt to auto-fix code based on validation issues"""
    if validation.get("valid", False):
        return code
    
    issues = validation.get("issues", [])
    if not issues:
        return code
    
    log(f"Attempting auto-fix for issues: {issues}")
    
    # Try to fix common issues with another LLM call
    fix_prompt = f"""Fix these issues in the code:

ISSUES: {', '.join(issues)}

CODE:
```{component_type}
{code}
```

Provide ONLY the fixed code. Ensure every HTML tag has matching closing tag.
"""

    fixed = ollama_generate(fix_prompt, tokens=4000, temp=0.02)
    
    # Extract code from response
    lines = fixed.split("\n")
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

def clean_code(raw, is_vue=False):
    """Clean and validate generated code"""
    # Extract code from markdown fences
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
    
    # Ensure Vue files have template wrapper
    if is_vue and not code.startswith(("<template>", "<script", "<style")):
        if "<div" in code or "<section" in code:
            code = f"<template>\n{code}\n</template>"
    
    return code

def generate_with_validation(prompt: str, component_type: str = "vue", max_attempts: int = 3) -> str:
    """Generate code with iterative validation and fixing"""
    
    for attempt in range(max_attempts):
        log(f"Generation attempt {attempt + 1}/{max_attempts}")
        
        # Generate code
        raw = ollama_generate(prompt, tokens=4000)
        if not raw:
            continue
        
        code = clean_code(raw, is_vue=(component_type == "vue"))
        
        # Validate
        validation = validate_code_quality(code, component_type)
        
        if validation.get("valid", False):
            log(f"Code validated successfully on attempt {attempt + 1}")
            return code
        
        # Log issues
        issues = validation.get("issues", [])
        severity = validation.get("severity", "warning")
        log(f"Validation failed ({severity}): {issues}")
        
        # Try to fix
        if attempt < max_attempts - 1:
            code = auto_fix_code(code, validation, component_type)
            # Validate again after fix
            validation = validate_code_quality(code, component_type)
            if validation.get("valid", False):
                log(f"Auto-fix successful on attempt {attempt + 1}")
                return code
    
    # Return best effort even if not perfect
    log("Warning: Returning code that failed validation", "WARN")
    return code

def fix_and_build(name, site_dir):
    """Rebuild site with DeepSeek and validation"""
    log(f"Rebuilding {name} via {MODEL} with validation...")
    
    os.makedirs(os.path.join(site_dir, "src/styles"), exist_ok=True)
    open(os.path.join(site_dir, "src/styles/orange-theme.css"), "w").write(
        ":root { --color-primary: #FF6B2C; --color-bg: #0A0A0F; --color-card: #1A1A24; }\n"
    )
    open(os.path.join(site_dir, "src/main.js"), "w").write(
        "import { createApp } from 'vue'\nimport App from './App.vue'\ncreateApp(App).mount('#app')\n"
    )
    
    if "landing" in name:
        p = """Create a Vue 3 landing page (App.vue) using <script setup>.

TEMPLATE STRUCTURE:
- h-screen hero section with canvas#particles (absolute, full size)
- Centered: h1 "SpiderNetOS" (gradient text), subtitle about autonomous scaling, orange CTA button
- Feature grid: 3 cards (BUILD 🔨, SELL 💰, SCALE 📈)
- Metrics: 2.4M Agents, $840M Revenue, 99.97% Uptime
- Footer

SCRIPT: Particle animation with 40 dots, connect lines < 150px distance
STYLE: bg-[#0A0A0F], orange-500 primary, gray-100 text

RULES: Every tag must close. No unclosed divs."""
        
        code = generate_with_validation(p, "vue")
        open(os.path.join(site_dir, "src/App.vue"), "w").write(code)
        log(f"Landing App.vue generated ({len(code)} chars)")
        
    elif "customer" in name:
        p = """Create a Vue 3 customer dashboard shell (App.vue) using <script setup>.

LAYOUT:
- Flex h-screen bg-[#0F0F14]
- Sidebar w-64: logo, nav items with emojis (🏠 Dashboard, 🤖 Agents, 🔄 Flows, ⚡ Approvals badge-3, etc), budget bar at bottom
- Main area: top bar, dynamic component view

SCRIPT: ref for currentView, navItems array, import Dashboard

RULES: All tags must close. Use self-closing only for img/input."""
        
        code = generate_with_validation(p, "vue")
        open(os.path.join(site_dir, "src/App.vue"), "w").write(code)
        log(f"Customer App.vue generated ({len(code)} chars)")
        
        # Dashboard component
        p2 = """Create a Vue 3 Dashboard.vue using <script setup>.

CONTENT:
- Grid 3 columns: System Status (green dot, "Operational"), Active Agents ("12", "+3"), Revenue Today ("$2,847", "+12%")
- Cards: bg-[#1A1A24], border border-gray-800, rounded-lg
- Recent Events list: 4 items with colored status dots

DESIGN: Dark theme, orange #FF6B2C accents."""
        
        code2 = generate_with_validation(p2, "vue")
        os.makedirs(os.path.join(site_dir, "src/views"), exist_ok=True)
        open(os.path.join(site_dir, "src/views/Dashboard.vue"), "w").write(code2)
        log(f"Dashboard.vue generated ({len(code2)} chars)")
    
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

def ensure_server(port, name, dist_dir, expected):
    code, title = check(port)
    healthy = code == 200 and expected.lower() in title.lower()
    if healthy:
        return True, title
    
    log(f"{name}: Port {port} unhealthy (code={code}, title=\"{title}\")", "WARN")
    
    if not is_free(port):
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
    log("SPIDERNETOS MECHANIC v2 - STARTING")
    log("Features: DeepSeek v4 + Code Quality Validation")
    log("=" * 50)
    
    model = check_model_available()
    log(f"Using model: {model}")
    
    log("Monitoring every 30s. Ctrl+C to stop.")
    log("=" * 50)
    
    while True:
        all_ok = True
        for port, (name, dist, exp) in SITES.items():
            ok, msg = ensure_server(port, name, dist, exp)
            if not ok:
                all_ok = False
                log(f"{name}: CRITICAL - {msg}", "ERROR")
        
        if all_ok:
            log("All systems nominal")
        
        time.sleep(CHECK_SEC)

if __name__ == "__main__":
    try:
        main_loop()
    except KeyboardInterrupt:
        log("Mechanic stopped by user")
        sys.exit(0)
