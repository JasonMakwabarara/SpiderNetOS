#!/bin/bash
# Alternative: Use ngrok instead of cloudflared

echo "Installing ngrok..."

# Download ngrok
curl -s https://ngrok-agent.s3.amazonaws.com/ngrok.asc | tee /etc/apt/trusted.gpg.d/ngrok.asc >/dev/null
echo "deb https://ngrok-agent.s3.amazonaws.com buster main" | tee /etc/apt/sources.list.d/ngrok.list
apt-get update && apt-get install -y ngrok

echo ""
echo "To use ngrok:"
echo "1. Sign up at https://ngrok.com (free)"
echo "2. Get authtoken from dashboard"
echo "3. Run: ngrok config add-authtoken YOUR_TOKEN"
echo "4. Run: ngrok http 18368"
echo ""
echo "This gives you a reliable URL that works immediately."
