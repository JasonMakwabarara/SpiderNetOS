#!/bin/bash
# SpiderNetOS — start sites for Cloudflare Tunnel / Vast.ai persistence.
# spidernetos.com (apex) MUST serve the cockpit SPA on :3000 — see scripts/serve-cockpit-apex.sh.

echo "Starting SpiderNetOS sites..."

BASE="/workspace/SpiderNetOS"

pkill -f "http.server"
sleep 2

# Port 3000: official Cockpit SPA (Vue + Vite preview; correct SPA fallback)
if [[ -f "$BASE/scripts/serve-cockpit-apex.sh" ]]; then
  bash "$BASE/scripts/serve-cockpit-apex.sh"
else
  echo "ERROR: missing $BASE/scripts/serve-cockpit-apex.sh" >&2
  exit 1
fi

# Optional: customer marketing SPA (port 3001)
if [[ -f "$BASE/sites/customer-cockpit/dist/index.html" ]]; then
  cd "$BASE/sites/customer-cockpit/dist" && nohup python3 -m http.server 3001 --bind 0.0.0.0 >/tmp/site-3001.log 2>&1 &
else
  echo "Note: sites/customer-cockpit/dist missing — skipping port 3001"
fi

sleep 3

echo "Checking sites..."
for port in 3000 3001; do
  if curl -sf -o /dev/null "http://localhost:$port"; then
    echo "✓ Port $port: OK"
  else
    echo "✗ Port $port: FAILED"
  fi
done

echo ""
echo "Apex (https://spidernetos.com/) should proxy to cockpit on port 3000."
echo "Ensure Cloudflare Tunnel ~/.cloudflared/config.yml matches ingress (see create-cloudflare-config.sh)."
