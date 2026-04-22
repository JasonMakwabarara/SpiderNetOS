#!/usr/bin/env bash
set -euo pipefail

echo "╔══════════════════════════════════════════╗"
echo "║     SpiderNet OS v3.2 — Starting...      ║"
echo "╚══════════════════════════════════════════╝"

# Copy .env if not exists
if [ ! -f .env ]; then
    cp .env.example .env
    echo "[init] Created .env from .env.example"
fi

# Build and start all planes
docker compose up --build -d

echo ""
echo "Waiting for services to be healthy..."
sleep 5

# Run Laravel migrations
echo "[migrate] Running database migrations..."
docker compose exec api php artisan migrate --force 2>/dev/null || echo "[migrate] Skipped (API not ready yet — run manually: docker compose exec api php artisan migrate)"

echo ""
echo "╔══════════════════════════════════════════╗"
echo "║     SpiderNet OS is running!             ║"
echo "╠══════════════════════════════════════════╣"
echo "║  API:        http://localhost:8000       ║"
echo "║  Cockpit:    http://localhost:5173       ║"
echo "║  Inference:  http://localhost:9000       ║"
echo "║  WebSocket:  ws://localhost:6001         ║"
echo "║  PostgreSQL: localhost:5432              ║"
echo "║  Redis:      localhost:6379              ║"
echo "╚══════════════════════════════════════════╝"
