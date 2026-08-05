#!/usr/bin/env bash
# Serve the cockpit SPA on port 3000 (apex + Cloudflare Tunnel default).
# Uses `vite preview` with SPA history mode fallback.

set -euo pipefail

BASE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COCKPIT="${COCKPIT_DIR:-$BASE/cockpit}"
PORT="${COCKPIT_APEX_PORT:-3000}"

if ! command -v npm >/dev/null 2>&1; then
  echo "ERROR: npm required to run cockpit (vite preview). Install Node.js or use Docker." >&2
  exit 1
fi

mkdir -p /tmp

pkill -f "http\\.server ${PORT}\\b" 2>/dev/null || true
pkill -f "vite preview.*--port ${PORT}" 2>/dev/null || true
pkill -f "node.*serve-spa.js" 2>/dev/null || true
sleep 1

cd "$COCKPIT"
if [[ ! -f dist/index.html ]]; then
  echo "Building cockpit (no dist/)..."
  if [[ -f package-lock.json ]]; then
    npm ci --prefer-offline --no-audit
  else
    npm install --no-audit
  fi
  npm run build
fi

echo "Starting cockpit on 0.0.0.0:${PORT}"
nohup node serve-spa.js >>/tmp/site-3000.log 2>&1 &
sleep 2

curl -sf -o /dev/null -w "apex cockpit: HTTP %{http_code}\n" "http://127.0.0.1:${PORT}/" || {
  echo "FAIL: cockpit did not respond on ${PORT}; see /tmp/site-3000.log" >&2
  tail -40 /tmp/site-3000.log 2>/dev/null || true
  exit 1
}
