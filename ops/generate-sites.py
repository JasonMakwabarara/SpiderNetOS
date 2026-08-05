#!/usr/bin/env python3
"""
SpiderNetOS Multi-Site Generator
Uses Ollama models (gemma4:31b) to generate all three sites from prompts.
Run on Vast.ai instance: python3 generate-sites.py
"""
import asyncio
import json
import os
import time
import httpx
from pathlib import Path

OLLAMA_URL = "http://localhost:11434"
MODEL = "gemma4:31b"
PROMPTS_FILE = "/workspace/SpiderNetOS/site-generation-prompts.json"
OUTPUT_BASE = "/workspace/SpiderNetOS/sites"
LOG_FILE = "/workspace/SpiderNetOS/logs/site-generation.log"

# Color tokens for reference in prompts
THEME = {
    "primary": "#FF6B2C",
    "secondary": "#FF8C42", 
    "accent_teal": "#00E5C8",
    "accent_purple": "#B967FF",
    "bg_dark": "#0A0A0F",
    "bg_panel": "#12121A",
    "bg_card": "#1A1A24",
    "text_primary": "#E8E8EF",
    "text_secondary": "#6B6B7B"
}

# Shared design system context injected into every prompt
DESIGN_SYSTEM = f"""
SPIDERNETOS DESIGN SYSTEM (MUST FOLLOW):
- Dark theme only: background {THEME['bg_dark']}, cards {THEME['bg_card']}, panels {THEME['bg_panel']}
- Primary accent: {THEME['primary']} (orange)
- Secondary accent: {THEME['accent_teal']} (teal) for success/positive
- Purple: {THEME['accent_purple']} for RL/CPL elements
- All data/numbers use JetBrains Mono or monospace font
- Glassmorphism: backdrop-filter blur(12px), bg-white/5, border-white/10
- No decorative elements - every pixel conveys information
- GPU-accelerated animations only (transform, opacity)
- Mobile responsive with Tailwind breakpoints
"""


def log(msg):
    ts = time.strftime("%H:%M:%S")
    line = f"[{ts}] {msg}"
    print(line)
    os.makedirs(os.path.dirname(LOG_FILE), exist_ok=True)
    with open(LOG_FILE, "a") as f:
        f.write(line + "\n")


def clean_code(raw: str, file_ext: str) -> str:
    """Strip markdown fences and fix common AI generation artifacts."""
    lines = raw.split("\n")
    start, end = 0, len(lines)

    # Find start fence
    for i, line in enumerate(lines):
        stripped = line.strip()
        if stripped.startswith("```"):
            # Extract language if present
            start = i + 1
            break

    # Find end fence
    for i in range(len(lines) - 1, start - 1, -1):
        if lines[i].strip().startswith("```"):
            end = i
            break

    code = "\n".join(lines[start:end]).strip()

    # Remove "Here is the..." preamble if present
    preamble_markers = [
        "here is the", "here's the", "below is the", "generated code",
        "i have generated", "the code you requested", "vue component"
    ]
    lower = code.lower()
    for marker in preamble_markers:
        if lower.startswith(marker):
            # Find first meaningful line (starts with <, import, export, etc.)
            for i, line in enumerate(code.split("\n")):
                stripped = line.strip()
                if stripped and (stripped.startswith(("<", "import", "export", "const", "function", "//", "/*", "#", "from ")) or stripped.startswith(".")):
                    code = "\n".join(code.split("\n")[i:])
                    break
            break

    # Ensure proper Vue structure if .vue file
    if file_ext == ".vue" and not code.startswith(("<template>", "<script", "<style")):
        # Wrap in template if it looks like HTML
        if "<div" in code or "<section" in code:
            code = f"<template>\n{code}\n</template>"

    return code


async def generate_with_ollama(prompt: str, model: str = MODEL, timeout: int = 300) -> str:
    """Send prompt to Ollama and return generated text."""
    system = f"""You are an expert Vue 3 + Tailwind CSS frontend developer specializing in dark-themed, cyberpunk-inspired admin dashboards and landing pages.

{DESIGN_SYSTEM}

RULES:
- Generate COMPLETE, PRODUCTION-READY code.
- Use Vue 3 Composition API with <script setup>.
- Use Tailwind CSS for all styling.
- Use Lucide icons where appropriate (import from 'lucide-vue-next').
- All components must be responsive and accessible.
- For charts, use inline SVG or mock Chart.js integration comments.
- Generate ONLY the code. No explanations, no markdown fences, no preamble.
- Start immediately with <template>, import statements, or CSS rules."""

    full_prompt = f"{system}\n\n{prompt}\n\nGenerate ONLY the code, no explanations:"

    async with httpx.AsyncClient(timeout=timeout) as client:
        try:
            resp = await client.post(
                f"{OLLAMA_URL}/api/generate",
                json={
                    "model": model,
                    "prompt": full_prompt,
                    "stream": False,
                    "options": {
                        "temperature": 0.2,
                        "num_predict": 4000,
                        "top_p": 0.9,
                        "top_k": 40
                    }
                }
            )
            resp.raise_for_status()
            data = resp.json()
            return data.get("response", "")
        except Exception as e:
            log(f"  ERROR calling Ollama: {e}")
            return ""


async def generate_site(site_config: dict):
    """Generate all pages for a single site."""
    site_name = site_config["domain"]
    output_dir = site_config["output_dir"]
    pages = site_config.get("pages", [])

    log(f"\n{'='*60}")
    log(f"SITE: {site_name}")
    log(f"Role: {site_config['role']}")
    log(f"Output: {output_dir}")
    log(f"Pages: {len(pages)}")
    log(f"{'='*60}")

    os.makedirs(output_dir, exist_ok=True)
    os.makedirs(os.path.join(output_dir, "src"), exist_ok=True)
    os.makedirs(os.path.join(output_dir, "src/components"), exist_ok=True)
    os.makedirs(os.path.join(output_dir, "src/views"), exist_ok=True)
    os.makedirs(os.path.join(output_dir, "src/stores"), exist_ok=True)
    os.makedirs(os.path.join(output_dir, "src/services"), exist_ok=True)
    os.makedirs(os.path.join(output_dir, "src/styles"), exist_ok=True)

    generated_files = []

    for page in pages:
        name = page["name"]
        file_path = os.path.join(output_dir, page["file"])
        prompt = page["prompt"]

        log(f"\n  Generating: {name} → {file_path}")

        # Check if we should skip existing files (unless forced)
        if os.path.exists(file_path) and os.path.getsize(file_path) > 500:
            log(f"  ⚠ File exists ({os.path.getsize(file_path)} bytes), skipping. Delete to regenerate.")
            generated_files.append(file_path)
            continue

        raw = await generate_with_ollama(prompt)
        if not raw:
            log(f"  ✗ Failed to generate {name}")
            continue

        _, ext = os.path.splitext(file_path)
        code = clean_code(raw, ext)

        # Ensure directory exists
        os.makedirs(os.path.dirname(file_path), exist_ok=True)

        with open(file_path, "w") as f:
            f.write(code)

        size = len(code)
        log(f"  ✓ Generated {name}: {size} chars")
        generated_files.append(file_path)

        # Rate limit between requests
        await asyncio.sleep(2)

    # Generate shared components for this site
    shared = site_config.get("shared_components", [])
    for comp in shared:
        file_path = os.path.join(output_dir, comp["file"])
        prompt = comp["prompt"]
        name = comp["name"]

        log(f"\n  Generating shared: {name} → {file_path}")

        if os.path.exists(file_path) and os.path.getsize(file_path) > 200:
            log(f"  ⚠ File exists, skipping.")
            continue

        raw = await generate_with_ollama(prompt)
        if not raw:
            log(f"  ✗ Failed to generate {name}")
            continue

        _, ext = os.path.splitext(file_path)
        code = clean_code(raw, ext)

        os.makedirs(os.path.dirname(file_path), exist_ok=True)
        with open(file_path, "w") as f:
            f.write(code)

        log(f"  ✓ Generated {name}: {len(code)} chars")
        generated_files.append(file_path)
        await asyncio.sleep(1)

    # Generate package.json and vite.config.js for the site
    log(f"\n  Generating build configs...")
    await generate_build_configs(site_config, output_dir)

    return generated_files


async def generate_build_configs(site_config: dict, output_dir: str):
    """Generate package.json, vite.config.js, and main.js for a site."""
    port = site_config.get("port", 3000)
    tech = site_config.get("tech_stack", "Vue 3 + Vite + Tailwind CSS")
    role = site_config["role"]

    # package.json
    pkg = {
        "name": f"spidernet-{role}",
        "version": "1.0.0",
        "type": "module",
        "scripts": {
            "dev": f"vite --host 0.0.0.0 --port {port}",
            "build": "vite build",
            "preview": "vite preview"
        },
        "dependencies": {
            "vue": "^3.4.0",
            "vue-router": "^4.2.0",
            "pinia": "^2.1.0",
            "lucide-vue-next": "^0.400.0"
        },
        "devDependencies": {
            "@vitejs/plugin-vue": "^5.0.0",
            "vite": "^5.0.0",
            "tailwindcss": "^3.4.0",
            "postcss": "^8.4.0",
            "autoprefixer": "^10.4.0"
        }
    }

    pkg_path = os.path.join(output_dir, "package.json")
    if not os.path.exists(pkg_path):
        with open(pkg_path, "w") as f:
            json.dump(pkg, f, indent=2)
        log(f"  ✓ package.json")

    # vite.config.js
    vite_config = f"""import {{ defineConfig }} from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({{
  plugins: [vue()],
  server: {{
    host: '0.0.0.0',
    port: {port},
    allowedHosts: ['{site_config["domain"]}', '.spidernetos.com', 'localhost']
  }},
  resolve: {{
    alias: {{
      '@': path.resolve(__dirname, './src'),
      '~': path.resolve(__dirname, './src')
    }}
  }},
  build: {{
    outDir: 'dist',
    sourcemap: false,
    minify: 'terser'
  }}
}})
"""
    vite_path = os.path.join(output_dir, "vite.config.js")
    if not os.path.exists(vite_path):
        with open(vite_path, "w") as f:
            f.write(vite_config)
        log(f"  ✓ vite.config.js")

    # tailwind.config.js
    tailwind_config = """/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js,ts}'],
  theme: {
    extend: {
      colors: {
        spider: {
          orange: '#FF6B2C',
          'orange-light': '#FF8C42',
          teal: '#00E5C8',
          purple: '#B967FF',
          dark: '#0A0A0F',
          panel: '#12121A',
          card: '#1A1A24',
        }
      },
      fontFamily: {
        mono: ['JetBrains Mono', 'Fira Code', 'monospace'],
        sans: ['Inter', 'system-ui', 'sans-serif'],
      }
    }
  },
  plugins: []
}
"""
    tw_path = os.path.join(output_dir, "tailwind.config.js")
    if not os.path.exists(tw_path):
        with open(tw_path, "w") as f:
            f.write(tailwind_config)
        log(f"  ✓ tailwind.config.js")

    # postcss.config.js
    postcss = """export default {
  plugins: {
    tailwindcss: {},
    autoprefixer: {},
  },
}
"""
    pc_path = os.path.join(output_dir, "postcss.config.js")
    if not os.path.exists(pc_path):
        with open(pc_path, "w") as f:
            f.write(postcss)
        log(f"  ✓ postcss.config.js")

    # index.html
    index_html = f"""<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SpiderNetOS - {role.replace('_', ' ').title()}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
</head>
<body class="bg-spider-dark text-spider-text-primary">
  <div id="app"></div>
  <script type="module" src="/src/main.js"></script>
</body>
</html>
"""
    idx_path = os.path.join(output_dir, "index.html")
    if not os.path.exists(idx_path):
        with open(idx_path, "w") as f:
            f.write(index_html)
        log(f"  ✓ index.html")

    # main.js
    main_js = """import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import './styles/orange-theme.css'

const app = createApp(App)
app.use(createPinia())
app.mount('#app')
"""
    main_path = os.path.join(output_dir, "src/main.js")
    if not os.path.exists(main_path):
        with open(main_path, "w") as f:
            f.write(main_js)
        log(f"  ✓ main.js")


async def generate_shared_components(shared_configs: list, output_base: str):
    """Generate shared components used across all sites."""
    log(f"\n{'='*60}")
    log(f"SHARED COMPONENTS")
    log(f"{'='*60}")

    for comp in shared_configs:
        name = comp["name"]
        file_path = os.path.join(output_base, comp["file"])
        prompt = comp["prompt"]

        log(f"\n  Generating: {name} → {file_path}")

        if os.path.exists(file_path) and os.path.getsize(file_path) > 200:
            log(f"  ⚠ File exists, skipping.")
            continue

        raw = await generate_with_ollama(prompt)
        if not raw:
            log(f"  ✗ Failed to generate {name}")
            continue

        _, ext = os.path.splitext(file_path)
        code = clean_code(raw, ext)

        os.makedirs(os.path.dirname(file_path), exist_ok=True)
        with open(file_path, "w") as f:
            f.write(code)

        log(f"  ✓ Generated {name}: {len(code)} chars")
        await asyncio.sleep(1)


async def build_sites(config: dict):
    """Run npm install and npm run build for each site."""
    log(f"\n{'='*60}")
    log(f"BUILDING SITES")
    log(f"{'='*60}")

    for site in config["sites"]:
        site_dir = site["output_dir"]
        site_name = site["domain"]

        log(f"\n  Building: {site_name}")

        if not os.path.exists(os.path.join(site_dir, "package.json")):
            log(f"  ✗ No package.json found, skipping build")
            continue

        # Check if node_modules exists
        if not os.path.exists(os.path.join(site_dir, "node_modules")):
            log(f"  Installing dependencies...")
            proc = await asyncio.create_subprocess_shell(
                f"cd {site_dir} && npm install 2>&1",
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE
            )
            stdout, stderr = await proc.communicate()
            if proc.returncode != 0:
                log(f"  ✗ npm install failed: {stderr.decode()[:200]}")
                continue
            log(f"  ✓ Dependencies installed")

        # Build
        log(f"  Running build...")
        proc = await asyncio.create_subprocess_shell(
            f"cd {site_dir} && npm run build 2>&1",
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE
        )
        stdout, stderr = await proc.communicate()

        if proc.returncode == 0:
            dist_dir = os.path.join(site_dir, "dist")
            if os.path.exists(dist_dir):
                files = os.listdir(dist_dir)
                log(f"  ✓ Build successful: {len(files)} files in dist/")
            else:
                log(f"  ⚠ Build reported success but no dist/ found")
        else:
            err = stderr.decode()[:500]
            log(f"  ✗ Build failed: {err}")


async def main():
    log(f"\n{'#'*60}")
    log(f"SPIDERNETOS MULTI-SITE GENERATOR")
    log(f"Model: {MODEL}")
    log(f"Ollama: {OLLAMA_URL}")
    log(f"{'#'*60}")

    # Check Ollama availability
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            resp = await client.get(f"{OLLAMA_URL}/api/tags")
            if resp.status_code == 200:
                models = resp.json().get("models", [])
                model_names = [m.get("name", m.get("model", "unknown")) for m in models]
                log(f"Ollama available. Models: {', '.join(model_names[:5])}")
                if MODEL not in str(model_names):
                    log(f"  ⚠ {MODEL} not found in available models. Will try anyway.")
            else:
                log(f"⚠ Ollama responded with status {resp.status_code}")
    except Exception as e:
        log(f"✗ Cannot reach Ollama at {OLLAMA_URL}: {e}")
        log("Please ensure Ollama is running: ollama serve")
        return

    # Load prompts
    if not os.path.exists(PROMPTS_FILE):
        log(f"✗ Prompts file not found: {PROMPTS_FILE}")
        return

    with open(PROMPTS_FILE) as f:
        config = json.load(f)

    log(f"Loaded {len(config['sites'])} sites, {len(config.get('shared_components', []))} shared components")

    # Generate each site
    all_files = []
    for site in config["sites"]:
        files = await generate_site(site)
        all_files.extend(files)

    # Generate shared components
    if "shared_components" in config:
        await generate_shared_components(config["shared_components"], OUTPUT_BASE)

    # Build all sites
    await build_sites(config)

    # Summary
    log(f"\n{'#'*60}")
    log(f"GENERATION COMPLETE")
    log(f"{'#'*60}")
    log(f"Total files generated: {len(all_files)}")
    for f in all_files:
        size = os.path.getsize(f) if os.path.exists(f) else 0
        log(f"  {f} ({size} bytes)")

    log(f"\nNext steps:")
    log(f"1. Review generated files for quality")
    log(f"2. Fix any import errors or missing dependencies")
    log(f"3. Configure Nginx to serve each site's dist/ folder")
    log(f"4. Test all three domains")


if __name__ == "__main__":
    asyncio.run(main())
