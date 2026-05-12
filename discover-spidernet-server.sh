#!/bin/bash
# SpiderNetOS Server Discovery Script
# Run this on the SpiderNetOS server to get the correct connection info

echo "🔍 SpiderNetOS Server Discovery"
echo "================================"

# Check if SpiderNetOS is running locally
echo "📡 Checking local SpiderNetOS..."
if curl -f -s --max-time 5 http://localhost:8000/api/health > /dev/null 2>&1; then
    echo "✅ SpiderNetOS is running locally on port 8000"
else
    echo "❌ SpiderNetOS not found locally on port 8000"
fi

# Get network interfaces
echo ""
echo "🌐 Network Interfaces:"
ip addr show | grep -E "inet.*(eth|enp|wlan|tailscale)" | grep -v "127.0.0.1" | while read line; do
    ip=$(echo $line | awk '{print $2}' | cut -d'/' -f1)
    interface=$(echo $line | awk '{print $NF}')
    echo "  $interface: $ip"
done

# Get Tailscale IP specifically
echo ""
echo "🐉 Tailscale IP:"
TAILSCALE_IP=$(tailscale ip -4 2>/dev/null || echo "Tailscale not available")
if [ "$TAILSCALE_IP" != "Tailscale not available" ]; then
    echo "✅ Tailscale IP: $TAILSCALE_IP"

    # Test if SpiderNetOS is accessible via Tailscale
    echo ""
    echo "🧪 Testing SpiderNetOS via Tailscale..."
    if curl -f -s --max-time 5 http://$TAILSCALE_IP:8000/api/health > /dev/null 2>&1; then
        echo "✅ SpiderNetOS accessible via Tailscale: http://$TAILSCALE_IP:8000"
        echo ""
        echo "🎯 Use this URL in your Hermes configuration:"
        echo "   SPIDERNET_API_URL=http://$TAILSCALE_IP:8000"
    else
        echo "❌ SpiderNetOS not accessible via Tailscale"
        echo "   Make sure SpiderNetOS is running and port 8000 is open"
    fi
else
    echo "❌ Tailscale not detected"
    echo "   Install Tailscale: curl -fsSL https://tailscale.com/install.sh | sh"
fi

# Check firewall
echo ""
echo "🔥 Firewall Status:"
if command -v ufw >/dev/null 2>&1; then
    ufw status | grep 8000 || echo "Port 8000 not explicitly allowed in UFW"
elif command -v firewall-cmd >/dev/null 2>&1; then
    firewall-cmd --list-ports | grep 8000 || echo "Port 8000 not explicitly allowed in firewalld"
else
    echo "No recognized firewall detected"
fi

echo ""
echo "📋 Summary:"
echo "1. Make sure SpiderNetOS is running: ./artisan serve --host=0.0.0.0 --port=8000"
echo "2. Open port 8000 in firewall if needed"
echo "3. Use the Tailscale IP shown above in Hermes configuration"
echo "4. Test connection: curl http://YOUR_IP:8000/api/health"