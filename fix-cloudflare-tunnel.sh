#!/bin/bash
# Fix Cloudflare Tunnel for SpiderNetOS

echo "Checking Cloudflare setup..."

# Check if credentials exist
if [ -f ~/.cloudflared/cert.pem ]; then
    echo "✓ Origin certificate found"
else
    echo "✗ Origin certificate missing"
    echo "You need to authenticate cloudflared:"
    echo "  cloudflared tunnel login"
    echo "Then copy the cert.pem to ~/.cloudflared/"
fi

# Check if tunnel config exists
if [ -f ~/.cloudflared/config.yml ]; then
    echo "✓ Config file exists"
    cat ~/.cloudflared/config.yml
else
    echo "Creating config..."
    mkdir -p ~/.cloudflared
    cat > ~/.cloudflared/config.yml << 'EOF'
tunnel: spidernetos
credentials-file: /root/.cloudflared/spidernetos.json

ingress:
  - hostname: spidernetos.com
    service: http://localhost:3000
  - hostname: www.spidernetos.com
    service: http://localhost:3000
  - hostname: app.spidernetos.com
    service: http://localhost:3001
  - hostname: cockpit.internal.spidernetos.com
    service: http://localhost:3002
  - service: http_status:404
EOF
    echo "✓ Config created"
fi

# Check tunnel credentials JSON
if [ -f ~/.cloudflared/spidernetos.json ]; then
    echo "✓ Tunnel credentials found"
else
    echo "✗ Tunnel credentials missing"
    echo "Run: cloudflared tunnel create spidernetos"
fi

echo ""
echo "To fix:"
echo "1. If cert.pem missing: cloudflared tunnel login"
echo "2. If credentials missing: cloudflared tunnel create spidernetos"
echo "3. Start tunnel: cloudflared tunnel run spidernetos"
