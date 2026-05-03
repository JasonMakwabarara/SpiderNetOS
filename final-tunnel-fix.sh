#!/bin/bash
# Use cloudflared with the working port 18368

echo "Setting up Cloudflare tunnel to port 18368..."

# Kill existing cloudflared
pkill -f cloudflared
sleep 1

# Start tunnel to port 18368 (where the site is already running)
nohup cloudflared tunnel --url http://localhost:18368 > /tmp/tunnel.log 2>&1 &

echo "Waiting for tunnel..."
sleep 5

echo ""
echo "Your URL:"
cat /tmp/tunnel.log | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' | head -1

echo ""
echo "Or try the previous URL:"
cat /tmp/tunnel.log | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' | tail -1

echo ""
echo "Monitor with: tail -f /tmp/tunnel.log"
