#!/bin/bash
# SpiderNetOS Chaos Testing - Random Service Killer
# Tests system resilience by randomly killing services

set -euo pipefail

SERVICES=("kafka-1" "kafka-2" "kafka-3" "redis" "db-primary")
COOLDOWN=10

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

kill_random_service() {
    # Pick random service
    service=${SERVICES[$RANDOM % ${#SERVICES[@]}]}
    
    log "🔥 Killing service: $service"
    
    # Kill the container
    if docker kill "$service" 2>/dev/null; then
        log "✓ Killed $service"
        
        # Wait for potential recovery
        log "⏳ Waiting ${COOLDOWN}s for recovery..."
        sleep $COOLDOWN
        
        # Check if service recovered
        if docker ps --format '{{.Names}}' | grep -q "^${service}$"; then
            log "✓ $service recovered"
            return 0
        else
            log "✗ $service did not recover"
            return 1
        fi
    else
        log "✗ Could not kill $service (already dead?)"
        return 1
    fi
}

run_chaos_test() {
    local iterations=${1:-5}
    local failed=0
    
    log "=== SpiderNetOS Chaos Test ==="
    log "Iterations: $iterations"
    log "Services: ${SERVICES[*]}"
    log ""
    
    for i in $(seq 1 $iterations); do
        log "--- Iteration $i/$iterations ---"
        if ! kill_random_service; then
            ((failed++))
        fi
        log ""
    done
    
    log "=== Chaos Test Complete ==="
    log "Failed recoveries: $failed/$iterations"
    
    if [[ $failed -gt 0 ]]; then
        log "✗ SYSTEM NOT RESILIENT: $failed services failed to recover"
        exit 1
    else
        log "✓ SYSTEM RESILIENT: All services recovered"
        exit 0
    fi
}

# Usage
if [[ "${1:-}" == "--help" ]]; then
    echo "Usage: $0 [iterations]"
    echo "Example: $0 10  # Run 10 chaos iterations"
    exit 0
fi

run_chaos_test "${1:-5}"
