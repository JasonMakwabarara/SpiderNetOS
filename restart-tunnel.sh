#!/bin/bash
# Restart Cloudflare tunnel and get fresh URL

echo "Checking tunnel status..."

# Check if tunnel is running
if pgrep -f "cloudflared" > /dev/null; then
    echo "Tunnel is running"
    echo "Current URL from logs:"
    cat /tmp/tunnel.log | strings | grep -o 'https://[a-z-]*\.trycloudflare\.com' | tail -1
else
    echo "Tunnel is NOT running - restarting..."
    
    # Verify sites are up
    echo ""
    echo "Checking sites..."
    for port in 3000 3001 3002; do
        code=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:$port)
        echo "Port $port: HTTP $code"
    done
    
    # Start tunnel
    echo ""
    echo "Starting new tunnel..."
    nohup cloudflared tunnel --url http://localhost:3000 > /tmp/tunnel.log 2>&1 &
    
    echo "Waiting for tunnel to establish..."
    sleep 5
    
    echo ""
    echo "New URL:"
    cat /tmp/tunnel.log | strings | grep -o 'https://[a-z-]*\.trycloudflare\.com' | tail -1
fi

echo ""
echo "To monitor tunnel: tail -f /tmp/tunnel.log"
echo "To stop tunnel: pkill -f cloudflared"
