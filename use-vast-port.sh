#!/bin/bash
# Use Vast.ai's provided port 8080

echo "Setting up SpiderNetOS on Vast.ai port 8080..."

# Vast.ai maps: 8080 (external) -> 18368 (internal)
# So we need to run nginx or the site on port 18368

# Kill existing
pkill -f nginx
pkill -f cloudflared
pkill -f "http.server"
sleep 1

# Option 1: Run landing site directly on port 18368
cd /workspace/SpiderNetOS/sites/landing/dist
python3 -m http.server 18368 --bind 0.0.0.0 &

echo ""
echo "Landing site should now be accessible at:"
echo "  http://220.134.41.156:8080"
echo ""
echo "Test locally first:"
curl -I http://localhost:18368
