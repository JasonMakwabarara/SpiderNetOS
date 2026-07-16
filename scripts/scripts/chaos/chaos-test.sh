#!/bin/bash
# SpiderNetOS Chaos Testing - Tests system resilience
set -euo pipefail

SERVICES=("kafka-1" "kafka-2" "redis" "db-primary")
PASSED=0
FAILED=0

log() {
    echo "[$(date +%H:%M:%S)] $1"
}

kill_service() {
    local svc=$1
    log "Killing $svc..."
    if docker kill "$svc" 2>/dev/null; then
        sleep 10
        if docker ps | grep -q "$svc"; then
            log "PASS: $svc recovered"
            ((PASSED++))
        else
            log "FAIL: $svc did not recover"
            ((FAILED++))
        fi
    fi
}

main() {
    log "=== Chaos Test ==="
    local iterations=${1:-5}
    for i in $(seq 1 $iterations); do
        kill_service "${SERVICES[$RANDOM % ${#SERVICES[@]}]}"
    done
    log "Results: $PASSED passed, $FAILED failed"
    [[ $FAILED -eq 0 ]] && exit 0 || exit 1
}

main "$@"
