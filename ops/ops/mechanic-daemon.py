#!/usr/bin/env python3
"""
Mechanic Daemon - Continuous Auto-Healing for SpiderNetOS

Monitors all services and automatically fixes issues without human intervention.
Learns from each fix to improve over time.
"""

import asyncio
import httpx
import subprocess
import sqlite3
import time
import json
from datetime import datetime
from pathlib import Path
import hashlib

# Configuration
SERVICES = {
    "inference": {"url": "http://localhost:9000/health", "port": 9000, "dir": "/workspace/SpiderNetOS/inference", "cmd": "python3 main.py"},
    "atlas-api": {"url": "http://localhost:8001/health", "port": 8001, "dir": "/workspace/SpiderNetOS/atlas-api", "cmd": "python3 main.py"},
    "cpl": {"url": "http://localhost:9100/health", "port": 9100, "dir": "/workspace/SpiderNetOS/services/cpl-service", "cmd": "python3 main.py"},
    "cockpit": {"port": 3000, "dir": "/workspace/SpiderNetOS/cockpit", "cmd": "npm run dev"},
    "intelligence": {"port": None, "dir": "/workspace/SpiderNetOS/intelligence", "cmd": "python3 main.py"}
}

KB_PATH = "/workspace/SpiderNetOS/mechanic-knowledge.db"
LOG_DIR = "/workspace/SpiderNetOS/logs"
OLLAMA_URL = "http://localhost:11434"


class MechanicKnowledgeBase:
    """Knowledge base for learned fixes"""
    
    def __init__(self):
        self._init_db()
    
    def _init_db(self):
        conn = sqlite3.connect(KB_PATH)
        c = conn.cursor()
        c.execute('''
            CREATE TABLE IF NOT EXISTS fixes (
                id INTEGER PRIMARY KEY,
                service TEXT,
                symptom_hash TEXT,
                symptom TEXT,
                fix_cmd TEXT,
                success_count INTEGER DEFAULT 0,
                fail_count INTEGER DEFAULT 0,
                last_used TIMESTAMP,
                created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        conn.commit()
        conn.close()
    
    def find_fix(self, service, symptom):
        """Find a known fix for this symptom"""
        symptom_hash = hashlib.sha256(symptom.encode()).hexdigest()[:16]
        conn = sqlite3.connect(KB_PATH)
        c = conn.cursor()
        c.execute('''
            SELECT fix_cmd, success_count FROM fixes 
            WHERE service=? AND (symptom_hash=? OR symptom LIKE ?)
            ORDER BY success_count DESC, last_used DESC
            LIMIT 1
        ''', (service, symptom_hash, f'%{symptom[:50]}%'))
        result = c.fetchone()
        conn.close()
        return result[0] if result else None
    
    def save_fix(self, service, symptom, fix_cmd):
        """Save a successful fix"""
        symptom_hash = hashlib.sha256(symptom.encode()).hexdigest()[:16]
        conn = sqlite3.connect(KB_PATH)
        c = conn.cursor()
        try:
            c.execute('''
                INSERT INTO fixes (service, symptom_hash, symptom, fix_cmd)
                VALUES (?, ?, ?, ?)
            ''', (service, symptom_hash, symptom, fix_cmd))
        except sqlite3.IntegrityError:
            c.execute('''
                UPDATE fixes SET success_count = success_count + 1, last_used = CURRENT_TIMESTAMP
                WHERE service=? AND symptom_hash=?
            ''', (service, symptom_hash))
        conn.commit()
        conn.close()
    
    def record_success(self, service, symptom):
        """Increment success count"""
        symptom_hash = hashlib.sha256(symptom.encode()).hexdigest()[:16]
        conn = sqlite3.connect(KB_PATH)
        c = conn.cursor()
        c.execute('''
            UPDATE fixes SET success_count = success_count + 1, last_used = CURRENT_TIMESTAMP
            WHERE service=? AND symptom_hash=?
        ''', (service, symptom_hash))
        conn.commit()
        conn.close()


class MechanicDaemon:
    """Main auto-healing daemon"""
    
    def __init__(self):
        self.kb = MechanicKnowledgeBase()
        self.running = True
        
        # Known hardcoded fixes for common issues
        self.HARDCODED_FIXES = {
            "cockpit_port_in_use": "kill -9 $(lsof -t -i:3000) 2>/dev/null; sleep 2; cd /workspace/SpiderNetOS/cockpit && nohup npm run dev > /workspace/SpiderNetOS/logs/cockpit.log 2>&1 &",
            "cpl_datetime_utc": "sed -i 's/datetime.now(datetime.UTC)/datetime.utcnow()/g' /workspace/SpiderNetOS/services/cpl-service/main.py",
            "port_already_bound": "kill -9 $(lsof -t -i:{port}) 2>/dev/null",
            "service_not_responding": "cd {dir} && {cmd} &"
        }
    
    async def check_service(self, name, config):
        """Check if service is healthy"""
        if config.get("url"):
            try:
                r = subprocess.run(
                    ["curl", "-s", config["url"]],
                    capture_output=True, text=True, timeout=5
                )
                return r.returncode == 0 and "healthy" in r.stdout
            except:
                return False
        else:
            # Check process
            r = subprocess.run(
                ["pgrep", "-f", name],
                capture_output=True
            )
            return r.returncode == 0
    
    def get_log_snippet(self, service, lines=20):
        """Get recent log lines"""
        log_file = f"{LOG_DIR}/{service}.log"
        try:
            with open(log_file) as f:
                return '\n'.join(f.readlines()[-lines:])
        except:
            return "No logs available"
    
    def identify_symptom(self, service, logs):
        """Identify the problem from logs"""
        # Common error patterns
        if "address already in use" in logs.lower():
            return "port_already_bound", f"Port {SERVICES[service]['port']} already in use"
        if "datetime.UTC" in logs:
            return "cpl_datetime_utc", "datetime.UTC not supported"
        if "port 3000 is already in use" in logs:
            return "cockpit_port_in_use", "Cockpit port in use"
        if "connection refused" in logs.lower():
            return "service_not_responding", "Service not responding"
        return "unknown", logs[-200:]
    
    async def apply_fix(self, service, symptom_type, symptom_desc, config):
        """Apply appropriate fix"""
        print(f"[MECHANIC] Fixing {service}: {symptom_desc[:50]}...")
        
        # Try hardcoded fix first
        if symptom_type in self.HARDCODED_FIXES:
            fix_cmd = self.HARDCODED_FIXES[symptom_type]
            if "{port}" in fix_cmd:
                fix_cmd = fix_cmd.replace("{port}", str(config.get("port", "")))
            if "{dir}" in fix_cmd:
                fix_cmd = fix_cmd.replace("{dir}", config["dir"])
            if "{cmd}" in fix_cmd:
                fix_cmd = fix_cmd.replace("{cmd}", config["cmd"])
            
            print(f"[MECHANIC] Applying hardcoded fix: {fix_cmd[:60]}...")
            result = subprocess.run(fix_cmd, shell=True, capture_output=True, text=True)
            
            if result.returncode == 0 or "already in use" not in result.stderr:
                print(f"[MECHANIC] ✓ Fix applied")
                self.kb.record_success(service, symptom_desc)
                return True
        
        # Try known fix from KB
        known_fix = self.kb.find_fix(service, symptom_desc)
        if known_fix:
            print(f"[MECHANIC] Trying known fix from KB")
            result = subprocess.run(known_fix, shell=True, capture_output=True, text=True)
            if result.returncode == 0:
                print(f"[MECHANIC] ✓ Known fix worked")
                return True
        
        # Try AI-generated fix
        print(f"[MECHANIC] Consulting AI for new fix...")
        fix = await self.ask_ai_for_fix(service, symptom_desc)
        if fix:
            result = subprocess.run(fix, shell=True, capture_output=True, text=True)
            if result.returncode == 0:
                print(f"[MECHANIC] ✓ AI fix worked, saving to KB")
                self.kb.save_fix(service, symptom_desc, fix)
                return True
        
        print(f"[MECHANIC] ✗ Could not fix {service}")
        return False
    
    async def ask_ai_for_fix(self, service, symptom):
        """Ask Ollama for a fix"""
        try:
            async with httpx.AsyncClient(timeout=30) as client:
                resp = await client.post(
                    f"{OLLAMA_URL}/api/generate",
                    json={
                        "model": "qwen3.6",
                        "prompt": f"""Service: {service}
Problem: {symptom}

Provide ONE bash command to fix this. Be specific.
Return only the command, no explanation.
Example: kill -9 $(lsof -t -i:PORT) && cd DIR && CMD &""",
                        "stream": False,
                        "options": {"temperature": 0.1}
                    }
                )
                fix = resp.json().get("response", "").strip()
                # Clean markdown
                if fix.startswith("```"):
                    fix = fix.split("\n")[1] if "\n" in fix else fix[3:-3]
                return fix if fix else None
        except:
            return None
    
    async def heal_loop(self):
        """Main healing loop"""
        print("=" * 60)
        print("MECHANIC DAEMON - Auto-Healing Active")
        print("=" * 60)
        print("Monitoring services every 30 seconds...")
        print("Press Ctrl+C to stop")
        print("=" * 60)
        
        while self.running:
            for service, config in SERVICES.items():
                healthy = await self.check_service(service, config)
                
                if not healthy:
                    print(f"\n[ALERT] {service} is DOWN - initiating repair...")
                    logs = self.get_log_snippet(service)
                    symptom_type, symptom_desc = self.identify_symptom(service, logs)
                    
                    fixed = await self.apply_fix(service, symptom_type, symptom_desc, config)
                    
                    if fixed:
                        # Verify
                        await asyncio.sleep(3)
                        healthy = await self.check_service(service, config)
                        status = "✓ RESTORED" if healthy else "✗ Still down"
                        print(f"[MECHANIC] {service}: {status}")
                else:
                    print(f"  ✓ {service}", end="\r")
            
            await asyncio.sleep(30)
    
    def stop(self):
        self.running = False


async def main():
    daemon = MechanicDaemon()
    try:
        await daemon.heal_loop()
    except KeyboardInterrupt:
        print("\n[MECHANIC] Stopping daemon...")
        daemon.stop()


if __name__ == "__main__":
    asyncio.run(main())
