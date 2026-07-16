#!/bin/bash
# SpiderNetOS Environment Verification Script
# Checks that all required secrets and env vars are set (no fallbacks)

set -euo pipefail

REQUIRED_ENV_VARS=(
    "DB_PASSWORD"
    "DB_REPL_PASSWORD"
    "DB_ENCRYPTION_KEY"
    "REDIS_PASSWORD"
    "PUSHER_APP_KEY"
    "PUSHER_APP_SECRET"
    "APP_KEY"
)

REQUIRED_SECRETS=(
    "db_password"
    "db_repl_password"
    "db_encryption_key"
    "redis_password"
    "pusher_app_secret"
)

ERRORS=0

echo "=== SpiderNetOS Environment Verification ==="
echo ""

# Check environment variables
echo "1. Checking required environment variables..."
for var in "${REQUIRED_ENV_VARS[@]}"; do
    if [[ -z "${!var:-}" ]]; then
        echo "   ✗ $var: NOT SET"
        ((ERRORS++))
    else
        echo "   ✓ $var: Set"
    fi
done
echo ""

# Check Docker secrets (if Swarm is active)
echo "2. Checking Docker secrets..."
if docker info --format '{{.Swarm.LocalNodeState}}' 2>/dev/null | grep -q "active"; then
    for secret in "${REQUIRED_SECRETS[@]}"; do
        if docker secret ls --format '{{.Name}}' | grep -q "^${secret}$"; then
            echo "   ✓ $secret: Created"
        else
            echo "   ✗ $secret: NOT FOUND"
            echo "     Create with: echo 'value' | docker secret create $secret -"
            ((ERRORS++))
        fi
    done
else
    echo "   ⚠ Docker Swarm not active (secrets not checked)"
    echo "     Initialize with: docker swarm init"
fi
echo ""

# Check for dangerous defaults in .env
echo "3. Checking for fallback defaults in .env..."
if grep -E '\$\{[A-Z_]+:-[^}]+\}' .env 2>/dev/null; then
    echo "   ✗ FALLBACK DEFAULTS FOUND in .env"
    echo "     Remove all \${VAR:-default} patterns"
    ((ERRORS++))
else
    echo "   ✓ No fallback defaults found"
fi
echo ""

# Check TLS certificates
echo "4. Checking TLS certificates..."
if [[ -f "certs/ca/ca.crt" ]]; then
    echo "   ✓ CA certificate exists"
else
    echo "   ✗ CA certificate not found"
    echo "     Run: ./scripts/pki/setup-ca.sh"
    ((ERRORS++))
fi

if [[ -d "certs/services/postgres" ]]; then
    echo "   ✓ Service certificates exist"
else
    echo "   ✗ Service certificates not found"
    echo "     Run: ./scripts/pki/generate-service-certs.sh"
    ((ERRORS++))
fi
echo ""

# Summary
echo "=== Verification Summary ==="
if [[ $ERRORS -eq 0 ]]; then
    echo "✓ All checks passed - ready for deployment"
    exit 0
else
    echo "✗ $ERRORS check(s) failed - fix before deploying"
    exit 1
fi
