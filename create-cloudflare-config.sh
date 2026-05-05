#!/bin/bash
# Create Cloudflare tunnel config

TUNNEL_ID="a9750ebd-db4a-4770-81f4-c1343e493644"

mkdir -p ~/.cloudflared

cat > ~/.cloudflared/config.yml << EOF
tunnel: ${TUNNEL_ID}
credentials-file: /root/.cloudflared/${TUNNEL_ID}.json

ingress:
  # Apex MUST hit the cockpit SPA on :3000 (vite preview via scripts/serve-cockpit-apex.sh).
  # Listed hostnames explicitly; unmatched requests hit catch-all 404.
  - hostname: spidernetos.com
    service: http://localhost:3000
  - hostname: www.spidernetos.com
    service: http://localhost:3000
  - hostname: cockpit.spidernetos.com
    service: http://localhost:3000
  - hostname: app.spidernetos.com
    service: http://localhost:3001
  - hostname: cockpit.internal.spidernetos.com
    service: http://localhost:3000
  - service: http_status:404
EOF

echo "✓ Config created at ~/.cloudflared/config.yml"
cat ~/.cloudflared/config.yml
