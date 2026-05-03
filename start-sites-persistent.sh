#!/bin/bash
# SpiderNetOS Site Startup Script
# Run on Vast.ai to start all sites persistently

echo "Starting SpiderNetOS Sites..."

BASE="/workspace/SpiderNetOS"

# Kill any existing servers
pkill -f "http.server"
sleep 2

# Start each site with nohup so they persist
cd "$BASE/sites/landing/dist" && nohup python3 -m http.server 3000 --bind 0.0.0.0 > /tmp/site-3000.log 2>&1 &
cd "$BASE/sites/customer-cockpit/dist" && nohup python3 -m http.server 3001 --bind 0.0.0.0 > /tmp/site-3001.log 2>&1 &
cd "$BASE/cockpit/dist" && nohup python3 -m http.server 3002 --bind 0.0.0.0 > /tmp/site-3002.log 2>&1 &

sleep 3

# Check if running
echo "Checking sites..."
for port in 3000 3001 3002; do
    if curl -s -o /dev/null -w "%{http_code}" http://localhost:$port | grep -q "200"; then
        echo "✓ Port $port: OK"
    else
        echo "✗ Port $port: FAILED"
    fi
done

echo ""
echo "Sites started. Note: Vast.ai firewall may block external access."
echo "Check Vast.ai instance settings to open ports 3000-3002"
echo ""
echo "To verify from your local machine:"
echo "  curl -I http://<VAST_IP>:3000"
