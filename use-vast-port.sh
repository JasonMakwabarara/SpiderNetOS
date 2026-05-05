#!/bin/bash
# Use Vast.ai's provided port 8080 (external) → run cockpit SPA on internal 18368.

echo "Setting up SpiderNetOS Cockpit on Vast.ai port 8080..."

pkill -f nginx || true
pkill -f cloudflared || true
pkill -f "http.server" || true
sleep 1

BASE="/workspace/SpiderNetOS"
export COCKPIT_APEX_PORT=18368

bash "$BASE/scripts/serve-cockpit-apex.sh" || exit 1

echo ""
echo "Cockpit should be accessible at:"
echo "  http://<VAST_IP>:8080"
echo ""
echo "Test locally first:"
curl -I "http://localhost:18368"
