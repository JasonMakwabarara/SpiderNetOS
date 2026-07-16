#!/bin/bash
# SpiderNetOS Docker Secrets Initialization
# One-time setup for production secrets (no fallback defaults)

set -euo pipefail

echo "=== SpiderNetOS Docker Secrets Initialization ==="
echo ""

# Check if running on Docker Swarm (required for secrets)
if ! docker info --format '{{.Swarm.LocalNodeState}}' 2>/dev/null | grep -q "active"; then
    echo "⚠ Docker Swarm not active. Initializing..."
    docker swarm init --advertise-addr 127.0.0.1 2>/dev/null || true
    echo "✓ Swarm initialized"
    echo ""
fi

# Generate cryptographically secure passwords
generate_password() {
    openssl rand -base64 32 | tr -d "=+/" | cut -c1-32
}

# Check if secrets already exist
check_secret() {
    docker secret ls --format '{{.Name}}' | grep -q "^${1}$"
}

echo "Creating secrets (will not overwrite existing)..."
echo ""

# Database password
if check_secret "db_password"; then
    echo "✓ db_password already exists"
else
    DB_PASS=$(generate_password)
    echo "$DB_PASS" | docker secret create db_password -
    echo "✓ db_password created"
fi

# Database replication password
if check_secret "db_repl_password"; then
    echo "✓ db_repl_password already exists"
else
    DB_REPL_PASS=$(generate_password)
    echo "$DB_REPL_PASS" | docker secret create db_repl_password -
    echo "✓ db_repl_password created"
fi

# Redis password
if check_secret "redis_password"; then
    echo "✓ redis_password already exists"
else
    REDIS_PASS=$(generate_password)
    echo "$REDIS_PASS" | docker secret create redis_password -
    echo "✓ redis_password created"
fi

# Pusher app secret
if check_secret "pusher_app_secret"; then
    echo "✓ pusher_app_secret already exists"
else
    PUSHER_SECRET=$(generate_password)
    echo "$PUSHER_SECRET" | docker secret create pusher_app_secret -
    echo "✓ pusher_app_secret created"
fi

# Database encryption key (32 chars for AES-256)
if check_secret "db_encryption_key"; then
    echo "✓ db_encryption_key already exists"
else
    ENC_KEY=$(openssl rand -hex 16)
    echo "$ENC_KEY" | docker secret create db_encryption_key -
    echo "✓ db_encryption_key created (32-char hex)"
fi

# CA private key (if generated)
if [[ -f "certs/ca/ca.key" ]]; then
    if check_secret "ca_private_key"; then
        echo "✓ ca_private_key already exists"
    else
        docker secret create ca_private_key certs/ca/ca.key
        echo "✓ ca_private_key created from certs/ca/ca.key"
    fi
fi

echo ""
echo "=== Secrets Created ==="
docker secret ls --filter name="db_\|redis_\|pusher_\|ca_"
echo ""
echo "NEXT: Update docker-compose.yml to use these secrets"
echo "See: services/<name>/secrets in docker-compose.yml"
