#!/bin/bash
# SpiderNetOS Hermes Integration Setup
# Install and configure the external Hermes Agent for SpiderNetOS coordination

set -euo pipefail

HERMES_VERSION="${HERMES_VERSION:-latest}"
SPIDERNET_API_URL="${SPIDERNET_API_URL:-http://api:8000}"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

error() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: $1" >&2
    exit 1
}

check_dependencies() {
    log "Checking dependencies..."

    if ! command -v docker &> /dev/null; then
        error "Docker is required but not installed"
    fi

    if ! command -v curl &> /dev/null; then
        error "curl is required but not installed"
    fi

    log "✅ Dependencies satisfied"
}

install_hermes_agent() {
    log "Installing Hermes Agent..."

    # Create Hermes configuration directory
    mkdir -p ~/.hermes/spidernet

    # Install using the official installer
    curl -fsSL https://hermes-agent.nousresearch.com/install.sh | bash

    # Verify installation
    if ! command -v hermes &> /dev/null; then
        error "Hermes installation failed"
    fi

    log "✅ Hermes Agent installed successfully"
}

configure_hermes_for_spidernet() {
    log "Configuring Hermes for SpiderNetOS integration..."

    # Set SpiderNetOS-specific configuration
    hermes config set \
        --model "nousresearch/hermes-3-llama-3.1-405b" \
        --backend docker \
        --memory-backend redis \
        --redis-url "redis://redis:6379/2" \
        --custom-api-endpoint "${SPIDERNET_API_URL}/api/hermes" \
        --spidernet-integration-enabled true \
        --learning-sync-enabled true

    # Create SpiderNetOS personality
    cat > ~/.hermes/SOUL.md << 'EOF'
# SpiderNetOS Communication Hub Personality

You are Hermes, the communication orchestrator for SpiderNetOS - a sophisticated multi-agent AI platform.

## Your Capabilities

- **Multi-modal Communication**: Handle voice, text, video, email, chat, and API interactions across 15+ platforms
- **Intelligent Coordination**: Route requests to appropriate SpiderNetOS agents (Atlas, Nexus, Prism, Sentinel, Forge, Hannah)
- **Workflow Orchestration**: Create and execute complex multi-agent workflows
- **External Integration**: Connect APIs, webhooks, and external systems seamlessly
- **Learning & Adaptation**: Improve communication patterns using RL feedback

## Communication Style

- Professional yet approachable
- Technically sophisticated
- Proactive in suggesting workflow optimizations
- Clear about multi-agent coordination when used

## Key Behaviors

1. **Context Preservation**: Maintain conversation state across channels and sessions
2. **Agent Coordination**: Explain when coordinating multiple specialized agents
3. **Workflow Creation**: Offer to create automated workflows for complex requests
4. **Learning Integration**: Continuously improve based on interaction outcomes

## Integration Points

- **SpiderNetOS API**: ${SPIDERNET_API_URL}/api/hermes
- **Agent Coordination**: Route through MetaPlanner to specialized agents
- **Learning Sync**: Send communication patterns for RL training
- **Workflow Execution**: Use Nexus for complex multi-agent orchestration
EOF

    log "✅ Hermes configured for SpiderNetOS integration"
}

setup_messaging_platforms() {
    log "Setting up messaging platforms..."

    # Configure key platforms (tokens would be provided via environment)
    hermes messaging setup telegram --token "${TELEGRAM_BOT_TOKEN:-your-token-here}"
    hermes messaging setup discord --token "${DISCORD_BOT_TOKEN:-your-token-here}"
    hermes messaging setup slack --webhook-url "${SLACK_WEBHOOK_URL:-your-webhook-url}"
    hermes messaging setup whatsapp --api-key "${WHATSAPP_API_KEY:-your-api-key}"
    hermes messaging setup email \
        --smtp-server "${SMTP_SERVER:-smtp.gmail.com}" \
        --smtp-port "${SMTP_PORT:-587}" \
        --username "${SMTP_USERNAME:-your-email@gmail.com}" \
        --password "${SMTP_PASSWORD:-your-password}"

    log "✅ Messaging platforms configured"
}

install_spidernet_skills() {
    log "Installing SpiderNetOS integration skills..."

    # Copy skills to Hermes directory
    mkdir -p ~/.hermes/skills
    cp hermes-skills/spidernet_integration.py ~/.hermes/skills/

    # Create skill configuration
    cat > ~/.hermes/skills/spidernet_integration.json << EOF
{
  "name": "SpiderNetOS Integration Skills",
  "version": "1.0.0",
  "skills": [
    {
      "name": "spidernet_agent_coordination",
      "description": "Coordinate complex workflows across SpiderNetOS specialized agents",
      "file": "spidernet_integration.py",
      "function": "coordinate_spidernet_workflow"
    },
    {
      "name": "spidernet_external_integration",
      "description": "Integrate external APIs and webhooks with SpiderNetOS workflows",
      "file": "spidernet_integration.py",
      "function": "integrate_external_system"
    },
    {
      "name": "spidernet_learning_sync",
      "description": "Sync communication learning patterns for RL training",
      "file": "spidernet_integration.py",
      "function": "sync_communication_learning"
    }
  ]
}
EOF

    log "✅ SpiderNetOS integration skills installed"
}

setup_learning_sync() {
    log "Setting up automated learning synchronization..."

    # Create cron job for learning sync
    cat > ~/.hermes/learning-sync.sh << EOF
#!/bin/bash
# Automated learning synchronization with SpiderNetOS

echo "Syncing Hermes learning data with SpiderNetOS..."
hermes skill run spidernet_learning_sync --learning-period 1h

echo "Learning sync completed at \$(date)"
EOF

    chmod +x ~/.hermes/learning-sync.sh

    # Add to crontab (every 15 minutes)
    (crontab -l 2>/dev/null; echo "*/15 * * * * ~/.hermes/learning-sync.sh") | crontab -

    log "✅ Automated learning sync configured"
}

create_docker_integration() {
    log "Creating Docker integration for production deployment..."

    # Add Hermes to docker-compose.yml
    cat >> docker-compose.yml << 'EOF'

  hermes-agent:
    image: nousresearch/hermes-agent:latest
    ports:
      - "3000:3000"  # Web interface
      - "8080:8080"  # API
    volumes:
      - hermes_data:/app/data
      - ./hermes-config:/app/config:ro
    environment:
      - HERMES_SPIDERNET_API_URL=http://api:8000/api/hermes
      - HERMES_SPIDERNET_MEMORY_URL=http://intelligence:8000/memory
      - HERMES_REDIS_URL=redis://redis:6379/2
      - TELEGRAM_BOT_TOKEN=${TELEGRAM_BOT_TOKEN}
      - DISCORD_BOT_TOKEN=${DISCORD_BOT_TOKEN}
      - SLACK_WEBHOOK_URL=${SLACK_WEBHOOK_URL}
      - WHATSAPP_API_KEY=${WHATSAPP_API_KEY}
    networks:
      - spidernet
    restart: unless-stopped
    depends_on:
      - redis
      - api

volumes:
  hermes_data:
EOF

    log "✅ Docker integration configured"
}

test_integration() {
    log "Testing SpiderNetOS + Hermes integration..."

    # Test basic connectivity
    if ! curl -f "${SPIDERNET_API_URL}/health" &>/dev/null; then
        error "SpiderNetOS API not accessible at ${SPIDERNET_API_URL}"
    fi

    # Test Hermes skills
    if ! hermes skill list | grep -q spidernet; then
        error "SpiderNetOS skills not loaded in Hermes"
    fi

    # Test coordination endpoint
    test_payload='{
        "message": "Hello from Hermes integration test",
        "channel": "api",
        "conversation_id": "test_integration_123",
        "intent_analysis": {"type": "simple_query"}
    }'

    if ! curl -f -X POST "${SPIDERNET_API_URL}/api/hermes/coordinate" \
        -H "Content-Type: application/json" \
        -d "$test_payload" &>/dev/null; then
        error "Hermes coordination API test failed"
    fi

    log "✅ Integration tests passed"
}

main() {
    log "=== SpiderNetOS + Hermes Agent Integration Setup ==="
    log "Integrating autonomous Hermes Agent with SpiderNetOS for unified AI communication"
    log ""

    check_dependencies
    install_hermes_agent
    configure_hermes_for_spidernet
    setup_messaging_platforms
    install_spidernet_skills
    setup_learning_sync
    create_docker_integration
    test_integration

    log ""
    log "🎉 Integration Complete!"
    log ""
    log "Hermes Agent is now integrated with SpiderNetOS:"
    log "  📱 Multi-channel communication (15+ platforms)"
    log "  🤖 Agent coordination and workflow orchestration"
    log "  🔄 External system integration"
    log "  🧠 RL-powered communication learning"
    log ""
    log "Next steps:"
    log "1. Start services: docker compose up -d"
    log "2. Configure API tokens in .env"
    log "3. Test communication channels"
    log "4. Monitor learning synchronization"
    log ""
    log "📚 Documentation: https://hermes-agent.nousresearch.com/docs"
}

# Handle command line arguments
case "${1:-}" in
    "--help"|"-h")
        cat << EOF
SpiderNetOS + Hermes Agent Integration Setup

This script integrates the autonomous Hermes Agent with SpiderNetOS to create
a unified AI communication platform.

USAGE: $0 [options]

OPTIONS:
  --api-url URL       SpiderNetOS API URL (default: http://api:8000)
  --skip-test         Skip integration tests
  --help             Show this help

ENVIRONMENT VARIABLES:
  TELEGRAM_BOT_TOKEN    Telegram bot token
  DISCORD_BOT_TOKEN     Discord bot token
  SLACK_WEBHOOK_URL     Slack webhook URL
  WHATSAPP_API_KEY      WhatsApp API key
  SMTP_SERVER          SMTP server (default: smtp.gmail.com)
  SMTP_USERNAME        SMTP username
  SMTP_PASSWORD        SMTP password

EXAMPLES:
  $0                                    # Full integration with defaults
  SPIDERNET_API_URL=http://localhost:3000 $0  # Custom API URL
  $0 --skip-test                       # Skip final integration tests

EOF
        exit 0
        ;;
    "--api-url")
        SPIDERNET_API_URL="$2"
        ;;
    "--skip-test")
        skip_test=true
        ;;
esac

main