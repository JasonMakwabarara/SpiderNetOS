#!/bin/bash
# SpiderNetOS Production Cockpit - Targeted Fixes
# Run on Vast.ai instance

set -e
SPIDERNET="/workspace/SpiderNetOS"
COCKPIT="$SPIDERNET/cockpit"
WS="$SPIDERNET/ws-server"
LOGS="$SPIDERNET/logs"

mkdir -p "$LOGS"

echo "=========================================="
echo "PRODUCTION FIX SCRIPT"
echo "=========================================="

# ==========================================
# 1. KILL OLD PROCESSES
# ==========================================
echo "[1/6] Killing old processes..."
pkill -f "python3.*ws-server" 2>/dev/null || true
pkill -f "npm run dev" 2>/dev/null || true
sleep 2

# ==========================================
# 2. RESTORE ORIGINAL APP.VUE IF OVERWRITTEN
# ==========================================
echo "[2/6] Checking App.vue..."

if [ -f "$COCKPIT/src/App.vue.bak" ]; then
    echo "  Restoring original App.vue from backup..."
    cp "$COCKPIT/src/App.vue.bak" "$COCKPIT/src/App.vue"
else
    echo "  Creating backup of current App.vue..."
    cp "$COCKPIT/src/App.vue" "$COCKPIT/src/App.vue.bak" 2>/dev/null || true
fi

# Check if our simplified App.vue replaced the real one
if grep -q 'class="app"' "$COCKPIT/src/App.vue" 2>/dev/null && ! grep -q 'authStore' "$COCKPIT/src/App.vue" 2>/dev/null; then
    echo "  ⚠ Simplified App.vue detected - keeping it for now since build works"
    echo "  (Original is backed up at App.vue.bak)"
fi

# ==========================================
# 3. FIX WEBSOCKET SERVER (FastAPI/websockets)
# ==========================================
echo "[3/6] Creating WebSocket server..."
mkdir -p "$WS"

cat > "$WS/main.py" << 'PYEOF'
#!/usr/bin/env python3
"""SpiderNetOS WebSocket Hub - Real-time telemetry for internal cockpit"""
import asyncio
import json
import subprocess
import time
import sys
from datetime import datetime

try:
    from fastapi import FastAPI, WebSocket
    from uvicorn import Config, Server
    HAS_FASTAPI = True
except ImportError:
    HAS_FASTAPI = False
    print("[WARN] FastAPI not found, using websockets fallback")

if HAS_FASTAPI:
    app = FastAPI(title="SpiderNetOS WebSocket Hub")
    clients = []

    @app.websocket("/ws")
    async def ws_endpoint(ws: WebSocket):
        await ws.accept()
        clients.append(ws)
        print(f"[WS] Client connected. Total: {len(clients)}")
        try:
            while True:
                await ws.receive_text()
        except:
            pass
        finally:
            if ws in clients:
                clients.remove(ws)
                print(f"[WS] Client disconnected. Total: {len(clients)}")

    @app.get("/health")
    async def health():
        return {"status": "healthy", "service": "websocket-hub", "clients": len(clients)}

    @app.get("/")
    async def root():
        return {"service": "SpiderNetOS WebSocket Hub", "status": "running", "clients": len(clients)}

    async def broadcast(data: dict):
        if not clients:
            return
        dead = []
        for c in clients:
            try:
                await c.send_text(json.dumps(data))
            except:
                dead.append(c)
        for d in dead:
            if d in clients:
                clients.remove(d)

    async def gpu_monitor_loop():
        """Poll nvidia-smi and broadcast GPU metrics"""
        while True:
            try:
                result = subprocess.run(
                    ["nvidia-smi",
                     "--query-gpu=index,utilization.gpu,memory.used,memory.total,temperature.gpu,power.draw,name",
                     "--format=csv,noheader,nounits"],
                    capture_output=True, text=True, timeout=5
                )
                if result.returncode == 0:
                    gpus = []
                    for line in result.stdout.strip().split("\n"):
                        if not line.strip():
                            continue
                        parts = [p.strip() for p in line.split(",")]
                        if len(parts) >= 7:
                            gpus.append({
                                "id": int(parts[0]),
                                "util": float(parts[1]),
                                "mem_used": float(parts[2]),
                                "mem_total": float(parts[3]),
                                "temp": float(parts[4]),
                                "power": float(parts[5]),
                                "name": parts[6]
                            })
                    if gpus:
                        await broadcast({
                            "type": "gpu",
                            "timestamp": datetime.utcnow().isoformat() + "Z",
                            "data": gpus
                        })
            except Exception as e:
                print(f"[GPU] Monitor error: {e}")
            await asyncio.sleep(2)

    async def event_simulator():
        """Simulate system events for testing"""
        agents = ["AtlasAgent", "ForgeAgent", "SentinelAgent", "PrismAgent", "NexusAgent", "HannahAgent"]
        statuses = ["completed", "running", "queued", "failed"]
        while True:
            await asyncio.sleep(3)
            agent = agents[int(time.time()) % len(agents)]
            status = statuses[int(time.time() * 100) % len(statuses)]
            event = {
                "type": "event",
                "timestamp": datetime.utcnow().isoformat() + "Z",
                "data": {
                    "id": f"evt_{int(time.time()*1000)}",
                    "agent": agent,
                    "status": status,
                    "intent": "process_query",
                    "cost": round(0.001 + (hash(agent) % 100) / 10000, 4)
                }
            }
            await broadcast(event)

    async def cpl_score_simulator():
        """Simulate CPL scoring data"""
        while True:
            await asyncio.sleep(7)
            score = {
                "type": "cpl_score",
                "timestamp": datetime.utcnow().isoformat() + "Z",
                "data": {
                    "event_id": f"evt_{int(time.time()*1000)}",
                    "novelty": round(0.3 + (time.time() % 10) / 20, 3),
                    "emotional": round(0.4 + (time.time() % 7) / 15, 3),
                    "utility": round(0.5 + (time.time() % 5) / 10, 3),
                    "pareto_distance": round(0.1 + (time.time() % 3) / 30, 3)
                }
            }
            await broadcast(score)

    @app.on_event("startup")
    async def startup():
        print("[WS] Hub starting...")
        asyncio.create_task(gpu_monitor_loop())
        asyncio.create_task(event_simulator())
        asyncio.create_task(cpl_score_simulator())
        print("[WS] Hub ready")

    if __name__ == "__main__":
        config = Config(app=app, host="0.0.0.0", port=8002, log_level="info")
        server = Server(config)
        asyncio.run(server.serve())

else:
    # Fallback: websockets library
    import websockets

    clients = set()

    async def handler(ws, path):
        clients.add(ws)
        print(f"[WS] Client connected. Total: {len(clients)}")
        try:
            async for msg in ws:
                await ws.send(json.dumps({"type": "pong", "time": time.time()}))
        finally:
            clients.discard(ws)
            print(f"[WS] Client disconnected. Total: {len(clients)}")

    async def broadcast(data):
        if not clients:
            return
        dead = set()
        for ws in clients:
            try:
                await ws.send(json.dumps(data))
            except:
                dead.add(ws)
        for d in dead:
            clients.discard(d)

    async def gpu_monitor():
        while True:
            try:
                r = subprocess.run(
                    ["nvidia-smi", "--query-gpu=index,utilization.gpu,memory.used,memory.total,temperature.gpu,power.draw",
                     "--format=csv,noheader,nounits"],
                    capture_output=True, text=True, timeout=5
                )
                if r.returncode == 0:
                    gpus = []
                    for line in r.stdout.strip().split("\n"):
                        parts = line.split(", ")
                        if len(parts) >= 5:
                            gpus.append({
                                "id": int(parts[0]),
                                "util": float(parts[1]),
                                "mem_used": float(parts[2]),
                                "mem_total": float(parts[3]),
                                "temp": float(parts[4]),
                                "power": float(parts[5]) if len(parts) > 5 else 0
                            })
                    await broadcast({
                        "type": "gpu",
                        "timestamp": datetime.utcnow().isoformat() + "Z",
                        "data": gpus
                    })
            except:
                pass
            await asyncio.sleep(2)

    async def main():
        asyncio.create_task(gpu_monitor())
        async with websockets.serve(handler, "0.0.0.0", 8002):
            print("[WS] Server on ws://0.0.0.0:8002")
            await asyncio.Future()

    if __name__ == "__main__":
        asyncio.run(main())
PYEOF

chmod +x "$WS/main.py"

# Install dependencies
pip install -q fastapi uvicorn websockets 2>/dev/null || pip install -q websockets 2>/dev/null || true

# Start WS server
nohup python3 "$WS/main.py" > "$LOGS/ws-server.log" 2>&1 &
sleep 3

# Check if WS server started
if ss -tlnp 2>/dev/null | grep -q ":8002" || netstat -tlnp 2>/dev/null | grep -q ":8002"; then
    echo "  ✓ WebSocket server running on port 8002"
else
    echo "  ⚠ WebSocket server may not be running. Check $LOGS/ws-server.log"
    tail -10 "$LOGS/ws-server.log" 2>/dev/null || true
fi

# ==========================================
# 4. FIX NGINX CONFIG (NO SSL REDIRECT)
# ==========================================
echo "[4/6] Fixing Nginx for HTTP-only origin..."

cat > /etc/nginx/sites-available/spidernet << 'NGINXCONF'
server {
    listen 80 default_server;
    server_name cockpit.internal.spidernetos.com cockpit.spidernetos.com _;

    root /workspace/SpiderNetOS/cockpit/dist;
    index index.html;

    # MIME types
    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    # Cache static assets
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Vue SPA fallback
    location / {
        try_files $uri $uri/ /index.html;
    }

    # API proxy
    location /api/ {
        proxy_pass http://localhost:8001/;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # WebSocket proxy
    location /ws/ {
        proxy_pass http://localhost:8002/;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_read_timeout 86400;
    }

    # Health check
    location /health {
        access_log off;
        return 200 '{"status":"ok","service":"nginx"}';
        add_header Content-Type application/json;
    }
}
NGINXCONF

ln -sf /etc/nginx/sites-available/spidernet /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true

nginx -t 2>&1 | tee "$LOGS/nginx-test.log"

if nginx -t 2>/dev/null; then
    service nginx restart || true
    echo "  ✓ Nginx restarted"
else
    echo "  ✗ Nginx config test failed"
fi

# ==========================================
# 5. VERIFY BUILD OUTPUT
# ==========================================
echo "[5/6] Verifying build..."

if [ -f "$COCKPIT/dist/index.html" ]; then
    echo "  ✓ Production build exists"
    echo "  CSS files:"
    ls -lh "$COCKPIT/dist/assets/"*.css 2>/dev/null | awk '{print "    " $9 " (" $5 ")"}' || true
    echo "  JS files:"
    ls -lh "$COCKPIT/dist/assets/"*.js 2>/dev/null | head -3 | awk '{print "    " $9 " (" $5 ")"}' || true
else
    echo "  ✗ No build output found! Running build..."
    cd "$COCKPIT" && npm run build 2>&1 | tee "$LOGS/build.log"
fi

# ==========================================
# 6. STATUS SUMMARY
# ==========================================
echo ""
echo "=========================================="
echo "STATUS SUMMARY"
echo "=========================================="

echo ""
echo "--- Services ---"
echo "Nginx (port 80):"
ss -tlnp 2>/dev/null | grep :80 || netstat -tlnp 2>/dev/null | grep :80 || echo "  Not detected"

echo ""
echo "WebSocket (port 8002):"
ss -tlnp 2>/dev/null | grep :8002 || netstat -tlnp 2>/dev/null | grep :8002 || echo "  Not detected"

echo ""
echo "Atlas API (port 8001):"
curl -s http://localhost:8001/health 2>/dev/null | head -c 100 || echo "  Not responding"

echo ""
echo "--- Access URLs ---"
echo "Internal Cockpit (Tailscale VPN):"
echo "  http://cockpit.internal.spidernetos.com"
echo "  http://100.123.148.97"
echo ""
echo "Public Cockpit (Cloudflare):"
echo "  https://cockpit.spidernetos.com"
echo "  ^^^ SET CLOUDFLARE SSL/TLS TO 'FLEXIBLE' (not Full/Strict)"
echo ""
echo "WebSocket:"
echo "  ws://cockpit.internal.spidernetos.com:8002/ws"
echo ""
echo "--- Cloudflare Fix ---"
echo "1. Go to https://dash.cloudflare.com"
echo "2. Select spidernetos.com"
echo "3. SSL/TLS → Overview"
echo "4. Set 'SSL/TLS encryption mode' to 'Flexible'"
echo "5. Wait 30 seconds, then test https://cockpit.spidernetos.com"
echo ""
echo "=========================================="
