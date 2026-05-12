# SpiderNetOS + Hermes Integration Deployment Script
# Run on Hermes server: root@100.120.219.83

#!/bin/bash

set -e

echo "🚀 Starting SpiderNetOS + Hermes Agent Integration"

# Configuration - Update this with your actual SpiderNetOS server Tailscale IP
SPIDERNET_API_URL="${SPIDERNET_API_URL:-http://100.111.175.105:8000}"
HERMES_API_TOKEN="${HERMES_API_TOKEN:-}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] $1${NC}"
}

error() {
    echo -e "${RED}[ERROR] $1${NC}"
}

warn() {
    echo -e "${YELLOW}[WARN] $1${NC}"
}

info() {
    echo -e "${BLUE}[INFO] $1${NC}"
}

# Phase 1: Environment Setup
log "📦 Phase 1: Setting up environment variables"

cat > /etc/hermes/spidernet.env << EOF
# SpiderNetOS Integration Configuration
SPIDERNET_API_URL=$SPIDERNET_API_URL
HERMES_API_TOKEN=$HERMES_API_TOKEN

# Model Configuration for Hermes
HERMES_MODEL=gemma-4
HERMES_FALLBACK_MODEL=gpt-4o
HERMES_MAX_TOKENS=4096
HERMES_TEMPERATURE=0.7

# Communication Channels
HERMES_DISCORD_ENABLED=true
HERMES_SLACK_ENABLED=true
HERMES_TELEGRAM_ENABLED=true
HERMES_EMAIL_ENABLED=true
HERMES_WEBHOOK_ENABLED=true

# Learning Configuration
HERMES_LEARNING_ENABLED=true
HERMES_LEARNING_SYNC_INTERVAL=3600
EOF

log "✅ Environment configuration created"

# Phase 2: Install SpiderNet Integration Skills
log "🔧 Phase 2: Installing SpiderNetOS integration skills"

# Create skills directory
mkdir -p /opt/hermes/skills/spidernet

# Copy integration skills (assuming this script is run with the file mounted)
if [ -f "/tmp/spidernet_integration.py" ]; then
    cp /tmp/spidernet_integration.py /opt/hermes/skills/spidernet/
else
    error "SpiderNet integration skills file not found. Please mount it at /tmp/spidernet_integration.py"
    exit 1
fi

# Install Python dependencies
pip install requests python-dotenv

log "✅ SpiderNet integration skills installed"

# Phase 3: Configure Hermes Model
log "🧠 Phase 3: Configuring Hermes model settings"

# Update Hermes configuration
cat >> /etc/hermes/config.yaml << EOF

# SpiderNetOS Integration Settings
spidernet:
  enabled: true
  api_url: "$SPIDERNET_API_URL"
  api_token: "$HERMES_API_TOKEN"
  coordination_timeout: 30
  learning_sync_enabled: true

model:
  primary: gemma-4
  fallback: gpt-4o
  max_tokens: 4096
  temperature: 0.7

communication:
  channels:
    - discord
    - slack
    - telegram
    - email
    - webhook
  context_preservation: true
  multi_agent_coordination: true

learning:
  enabled: true
  sync_interval: 3600
  data_collection: true
EOF

log "✅ Hermes model and communication settings configured"

# Phase 4: Set up Communication Channel Bridges
log "🌉 Phase 4: Setting up communication channel bridges"

# Create channel configuration
cat > /etc/hermes/channels.yaml << EOF
channels:
  discord:
    enabled: true
    token: "\${DISCORD_BOT_TOKEN}"
    command_prefix: "!"

  slack:
    enabled: true
    token: "\${SLACK_BOT_TOKEN}"
    signing_secret: "\${SLACK_SIGNING_SECRET}"

  telegram:
    enabled: true
    token: "\${TELEGRAM_BOT_TOKEN}"

  email:
    enabled: true
    smtp_server: "\${SMTP_SERVER}"
    smtp_port: "\${SMTP_PORT}"
    username: "\${SMTP_USERNAME}"
    password: "\${SMTP_PASSWORD}"

  webhook:
    enabled: true
    endpoints:
      - "/webhook/stripe"
      - "/webhook/github"
      - "/webhook/zapier"
EOF

log "✅ Communication channel bridges configured"

# Phase 5: Test Integration
log "🧪 Phase 5: Testing SpiderNetOS integration"

# Test API connectivity
info "Testing SpiderNetOS API connectivity..."
if curl -f -s "$SPIDERNET_API_URL/api/health" > /dev/null 2>&1; then
    log "✅ SpiderNetOS API is reachable"
else
    error "❌ SpiderNetOS API is not reachable at $SPIDERNET_API_URL"
    warn "Please verify the SPIDERNET_API_URL and network connectivity"
fi

# Test Hermes skills
info "Testing Hermes SpiderNet skills..."
python3 -c "
import sys
sys.path.append('/opt/hermes/skills/spidernet')
try:
    from spidernet_integration import spidernet_status_check
    result = spidernet_status_check()
    print('✅ SpiderNet skills loaded successfully')
    print(f'Status: {result}')
except Exception as e:
    print(f'❌ Failed to load SpiderNet skills: {e}')
"

# Phase 6: Start Services
log "🚀 Phase 6: Starting integrated services"

# Restart Hermes with new configuration
systemctl restart hermes-agent

# Start learning sync cron job
cat > /etc/cron.d/hermes-learning-sync << EOF
# Hermes learning synchronization - runs every hour
0 * * * * hermes /opt/hermes/bin/learning-sync.sh
EOF

chmod 644 /etc/cron.d/hermes-learning-sync
systemctl restart cron

log "✅ Services restarted and learning sync scheduled"

# Phase 7: Verification
log "✅ Phase 7: Running final verification checks"

# Check if Hermes is running
if systemctl is-active --quiet hermes-agent; then
    log "✅ Hermes agent is running"
else
    error "❌ Hermes agent failed to start"
fi

# Check SpiderNet connectivity
sleep 5
if python3 -c "
import sys
sys.path.append('/opt/hermes/skills/spidernet')
from spidernet_integration import spidernet_status_check
result = spidernet_status_check()
exit(0 if result.get('status') == 'operational' else 1)
"; then
    log "✅ SpiderNetOS integration is operational"
else
    warn "⚠️ SpiderNetOS integration may have issues - check configuration"
fi

log "🎉 SpiderNetOS + Hermes integration deployment completed!"
log ""
log "Next steps:"
log "1. Configure your communication channel tokens in /etc/hermes/channels.yaml"
log "2. Test individual channels (Discord, Slack, etc.)"
log "3. Monitor the integration logs: journalctl -u hermes-agent -f"
log "4. Set up monitoring and alerts for the integration"
log ""
log "Integration endpoints:"
log "- Coordination: POST /api/hermes/coordinate"
log "- Webhooks: POST /api/hermes/webhook/{type}"
log "- Learning: POST /api/hermes/learning/sync"
log "- Status: GET /api/hermes/status"