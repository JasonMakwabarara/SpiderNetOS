# SpiderNetOS + Hermes Integration Setup Guide

## Current Status
You're on the Hermes server (Hermes-1) via Tailscale, trying to connect to SpiderNetOS. The connection is failing because the integration script is using the wrong IP address.

## Step-by-Step Setup

### 1. Identify Server IPs

**On SpiderNetOS Server:**
```bash
# Get the Tailscale IP that Hermes should connect to
tailscale ip -4
# This should show something like: 100.111.175.105
```

**On Hermes Server (where you currently are):**
```bash
# Get your Hermes Tailscale IP
tailscale ip -4
# This shows: 100.120.219.83 (from your error message)
```

### 2. Configure Environment Variables

**On Hermes Server:**
```bash
# Set the correct SpiderNetOS server IP
export SPIDERNET_API_URL="http://YOUR_SPIDERNET_TAILSCALE_IP:8000"
export HERMES_API_TOKEN=""  # Add if authentication is required

# For example:
export SPIDERNET_API_URL="http://100.111.175.105:8000"
```

### 3. Update Integration Files

The integration files have been updated with the correct IP. Copy them to the right location:

```bash
# Copy integration script to correct location
cp /tmp/spidernet_integration.py /opt/hermes-integrations/spidernet_integration.py

# Copy test script
cp /tmp/test-spidernet-connection.sh /opt/hermes/test-connection.sh
chmod +x /opt/hermes/test-connection.sh
```

### 4. Test Connection

```bash
# Run the connection test
/opt/hermes/test-connection.sh
```

This will test:
- ✅ Basic network connectivity
- ✅ SpiderNetOS API availability
- ✅ Hermes endpoint availability
- ✅ Full integration functionality

### 5. Configure Hermes Environment

Create or update the Hermes environment file:

```bash
# Create Hermes environment configuration
cat > /etc/hermes/spidernet.env << EOF
# SpiderNetOS Integration Configuration
SPIDERNET_API_URL=http://100.111.175.105:8000
HERMES_API_TOKEN=

# Model Configuration
HERMES_MODEL=gemma-4
HERMES_FALLBACK_MODEL=gpt-4o

# Communication Channels
HERMES_DISCORD_ENABLED=true
HERMES_SLACK_ENABLED=true
HERMES_WEBHOOK_ENABLED=true
EOF
```

### 6. Restart Hermes Services

```bash
# Restart Hermes to pick up new configuration
systemctl restart hermes-agent

# Or if using a different process manager
# supervisorctl restart hermes
# or
# docker restart hermes-container
```

## Troubleshooting

### If Connection Still Fails:

1. **Verify SpiderNetOS is Running:**
```bash
# On SpiderNetOS server
curl http://localhost:8000/api/health
```

2. **Check Tailscale Connectivity:**
```bash
# On Hermes server
ping YOUR_SPIDERNET_TAILSCALE_IP

# On SpiderNetOS server
ping 100.120.219.83
```

3. **Verify Firewall Rules:**
```bash
# On SpiderNetOS server
ufw status
# Allow port 8000
ufw allow 8000
```

4. **Check API Routes:**
```bash
# On SpiderNetOS server
php artisan route:list | grep hermes
```

### Common Issues:

- **Wrong IP**: Make sure you're using the SpiderNetOS Tailscale IP, not the Hermes IP
- **Port Issues**: Ensure SpiderNetOS is running on port 8000
- **Firewall**: Tailscale should handle this, but check server firewalls
- **Authentication**: If SpiderNetOS requires auth, set HERMES_API_TOKEN

## Expected Output

When working correctly, you should see:
```
🔍 Testing SpiderNetOS connection...
Target URL: http://100.111.175.105:8000
📡 Testing basic connectivity...
✅ SpiderNetOS API is reachable
🔧 Testing Hermes endpoints...
✅ Hermes endpoints are available
🚀 Testing full SpiderNet integration...
✅ SpiderNet integration test passed
   Supported channels: 9
   Supported integrations: 7

🎯 If all tests pass, your integration is ready!
```

## Next Steps

Once connected:

1. **Test Basic Coordination:**
```bash
python3 -c "
from spidernet_integration import spidernet_agent_coordination
result = spidernet_agent_coordination('Create a simple test workflow')
print('Result:', result)
"
```

2. **Configure Communication Channels:**
   - Set up Discord bot token
   - Configure Slack webhook
   - Add email SMTP settings

3. **Monitor Integration:**
```bash
# Check logs on both servers
journalctl -u hermes-agent -f  # On Hermes
tail -f /var/log/spidernet/hermes.log  # On SpiderNetOS
```

The integration will enable Hermes to coordinate complex workflows across SpiderNetOS agents, handle multi-channel communication, and continuously learn from outcomes.