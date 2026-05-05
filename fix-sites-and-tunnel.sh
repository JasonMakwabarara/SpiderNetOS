#!/bin/bash
# Complete fix for sites and tunnel — apex serves cockpit SPA on :3000.

echo "=== Fixing SpiderNetOS Sites ==="

pkill -f nginx || true
pkill -f cloudflared || true
pkill -f "http.server" || true
sleep 2

BASE="${BASE:-/workspace/SpiderNetOS}"

echo "Checking cockpit build..."
if [[ ! -f "$BASE/cockpit/dist/index.html" ]]; then
  echo "⚠ cockpit/dist missing — run scripts/serve-cockpit-apex.sh (build + preview)."
fi

echo ""
echo "Starting cockpit apex on port 3000..."
bash "$BASE/scripts/serve-cockpit-apex.sh" || exit 1

if [[ -f "$BASE/sites/customer-cockpit/dist/index.html" ]]; then
  echo "Starting customer cockpit on port 3001..."
  cd "$BASE/sites/customer-cockpit/dist" && nohup python3 -m http.server 3001 --bind 0.0.0.0 >/tmp/site-3001.log 2>&1 &
else
  echo "⚠ Customer site dist missing — skipping port 3001."
fi

sleep 3

echo ""
echo "Verifying..."
for port in 3000 3001; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:$port/" || echo "000")
  if [[ "$code" == "200" ]]; then
    echo "✓ Port $port: HTTP 200"
  else
    echo "⚠ Port $port: HTTP ${code}"
  fi
done

echo ""
echo "Starting Cloudflare tunnel (quick mode → localhost:3000) ..."
nohup cloudflared tunnel --url http://localhost:3000 >/tmp/tunnel.log 2>&1 &
sleep 5

echo ""
echo "Tunnel URL (quick tunnel):"
grep -o 'https://[^ ]*\\.trycloudflare\\.com' /tmp/tunnel.log | head -1 || true

echo ""
echo "For custom hostnames (spidernetos.com), use named tunnel config (create-cloudflare-config.sh)."
echo "=== Setup Complete ==="
