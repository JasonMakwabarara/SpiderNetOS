#!/bin/bash
# Complete fix for sites and tunnel

echo "=== Fixing SpiderNetOS Sites ==="

# 1. Kill everything
pkill -f nginx
pkill -f cloudflared
pkill -f "http.server"
sleep 2

# 2. Verify sites are still built
echo "Checking dist folders..."
for port in 3000 3001 3002; do
    if [ -f "/workspace/SpiderNetOS/sites/landing/dist/index.html" ] && [ $port -eq 3000 ]; then
        echo "✓ Port $port: dist exists"
    elif [ -f "/workspace/SpiderNetOS/sites/customer-cockpit/dist/index.html" ] && [ $port -eq 3001 ]; then
        echo "✓ Port $port: dist exists"
    elif [ -f "/workspace/SpiderNetOS/cockpit/dist/index.html" ] && [ $port -eq 3002 ]; then
        echo "✓ Port $port: dist exists"
    else
        echo "✗ Port $port: dist MISSING"
    fi
done

# 3. Start sites directly (skip nginx)
echo ""
echo "Starting sites on ports 3000-3002..."
cd /workspace/SpiderNetOS/sites/landing/dist && nohup python3 -m http.server 3000 --bind 0.0.0.0 > /tmp/site-3000.log 2>&1 &
cd /workspace/SpiderNetOS/sites/customer-cockpit/dist && nohup python3 -m http.server 3001 --bind 0.0.0.0 > /tmp/site-3001.log 2>&1 &
cd /workspace/SpiderNetOS/cockpit/dist && nohup python3 -m http.server 3002 --bind 0.0.0.0 > /tmp/site-3002.log 2>&1 &

sleep 3

# 4. Verify sites are running
echo ""
echo "Verifying sites..."
for port in 3000 3001 3002; do
    code=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:$port)
    if [ "$code" = "200" ]; then
        echo "✓ Port $port: HTTP 200"
    else
        echo "✗ Port $port: HTTP $code"
    fi
done

# 5. Start tunnel on port 3000 (landing site directly)
echo ""
echo "Starting Cloudflare tunnel..."
nohup cloudflared tunnel --url http://localhost:3000 > /tmp/tunnel.log 2>&1 &
sleep 5

# 6. Get the URL
echo ""
echo "Tunnel URL:"
grep -o 'https://[^ ]*\.trycloudflare\.com' /tmp/tunnel.log | head -1

echo ""
echo "=== Setup Complete ==="
echo "Test the URL above in your browser"
