#!/bin/bash
# SpiderNetOS Advanced Chaos Testing Suite
# Sophisticated failure injection for comprehensive resilience testing

set -euo pipefail

# Configuration
CHAOS_DURATION="${CHAOS_DURATION:-300}"  # 5 minutes default
CHAOS_ITERATIONS="${CHAOS_ITERATIONS:-10}"
CHAOS_MODE="${CHAOS_MODE:-random}"  # random, sequential, network, resource
LOG_FILE="chaos-$(date +%Y%m%d-%H%M%S).log"

# Service definitions with recovery expectations
declare -A SERVICES=(
    ["kafka-1"]="kafka"      # Critical infrastructure
    ["kafka-2"]="kafka"      # Critical infrastructure
    ["kafka-3"]="kafka"      # Critical infrastructure
    ["redis"]="cache"        # High availability
    ["db-primary"]="database" # Critical infrastructure
    ["api"]="web"           # Application layer
    ["cpl-service"]="ml"     # ML service
)

declare -A RECOVERY_TIMEOUTS=(
    ["kafka"]="30"      # Kafka needs time to rebalance
    ["cache"]="10"      # Redis is fast
    ["database"]="60"   # PostgreSQL recovery can be slow
    ["web"]="15"        # Fast web service restart
    ["ml"]="30"         # ML models can take time to load
)

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

error() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: $1" >&2 | tee -a "$LOG_FILE"
    return 1
}

# Network failure injection
inject_network_failure() {
    local service="$1"
    local duration="${2:-30}"
    local container_id

    container_id=$(docker ps --filter "name=$service" --format "{{.ID}}")

    if [[ -z "$container_id" ]]; then
        error "Service $service not running"
        return 1
    fi

    log "🌐 Injecting network failure for $service (${duration}s)"

    # Block all traffic except health checks
    docker exec "$container_id" iptables -A INPUT -p tcp --dport 80 -j ACCEPT || true
    docker exec "$container_id" iptables -A INPUT -p tcp --dport 443 -j ACCEPT || true
    docker exec "$container_id" iptables -A INPUT -j DROP || true

    sleep "$duration"

    # Restore network
    docker exec "$container_id" iptables -F || true
    docker exec "$container_id" iptables -X || true

    log "✅ Network restored for $service"
}

# Resource exhaustion
inject_resource_exhaustion() {
    local service="$1"
    local resource="${2:-cpu}"  # cpu, memory, disk
    local duration="${3:-60}"

    log "🔥 Injecting $resource exhaustion on $service (${duration}s)"

    case "$resource" in
        "cpu")
            # Spawn CPU-intensive processes
            docker exec "$service" bash -c "for i in {1..4}; do (while true; do :; done) & done; sleep $duration; killall bash" &
            ;;
        "memory")
            # Allocate memory until OOM
            docker exec "$service" bash -c "python3 -c \"import time; [bytearray(100*1024*1024) for _ in range(10)]; time.sleep($duration)\"" &
            ;;
        "disk")
            # Fill disk space
            docker exec "$service" bash -c "dd if=/dev/zero of=/tmp/fill bs=1M count=100; sleep $duration; rm /tmp/fill" &
            ;;
    esac

    wait
    log "✅ Resource exhaustion test complete for $service"
}

# Correlated failure injection
inject_correlated_failure() {
    local services=("$@")

    log "💥 Injecting correlated failure: ${services[*]}"

    for service in "${services[@]}"; do
        docker kill "$service" 2>/dev/null || true
        log "  ✗ Killed $service"
    done

    # Wait for recovery with longer timeout for correlated failures
    local max_timeout=0
    for service in "${services[@]}"; do
        local service_type="${SERVICES[$service]}"
        local timeout="${RECOVERY_TIMEOUTS[$service_type]:-30}"
        (( max_timeout = max_timeout > timeout ? max_timeout : timeout ))
    done

    log "⏳ Waiting ${max_timeout}s for correlated recovery..."
    sleep "$max_timeout"

    local failed_count=0
    for service in "${services[@]}"; do
        if ! docker ps --format '{{.Names}}' | grep -q "^${service}$"; then
            error "Service $service failed to recover"
            ((failed_count++))
        else
            log "  ✓ $service recovered"
        fi
    done

    return $(( failed_count > 0 ))
}

# Split-brain scenario
inject_split_brain() {
    log "🧠 Injecting split-brain scenario (network partition)"

    # Simulate network partition between Kafka brokers
    docker network disconnect spidernet kafka-1 2>/dev/null || true
    docker network disconnect spidernet kafka-2 2>/dev/null || true

    # Leave kafka-3 isolated (split-brain)
    sleep 60

    # Restore network
    docker network connect spidernet kafka-1
    docker network connect spidernet kafka-2

    # Check if cluster heals
    sleep 30

    local healthy_count=$(docker exec kafka-3 kafka-broker-api-versions --bootstrap-server kafka-1:29092,kafka-2:29093 2>/dev/null | wc -l || echo 0)

    if [[ $healthy_count -gt 0 ]]; then
        log "✅ Split-brain resolved - cluster healed"
        return 0
    else
        error "Split-brain unresolved - cluster permanently divided"
        return 1
    fi
}

# Advanced chaos modes
run_chaos_mode() {
    local mode="$1"
    local iterations="$2"

    case "$mode" in
        "random")
            log "🎲 Running random chaos mode"
            for ((i=1; i<=iterations; i++)); do
                log "--- Random Iteration $i/$iterations ---"
                local services_array=("${!SERVICES[@]}")
                local random_service="${services_array[RANDOM % ${#services_array[@]}]}"
                docker kill "$random_service" 2>/dev/null || true
                sleep 10
                check_recovery "$random_service"
            done
            ;;

        "network")
            log "🌐 Running network chaos mode"
            for service in "${!SERVICES[@]}"; do
                inject_network_failure "$service" 20
                check_recovery "$service"
            done
            ;;

        "resource")
            log "🔥 Running resource chaos mode"
            for service in "${!SERVICES[@]}"; do
                inject_resource_exhaustion "$service" "cpu" 30
                inject_resource_exhaustion "$service" "memory" 30
                check_recovery "$service"
            done
            ;;

        "correlated")
            log "💥 Running correlated chaos mode"
            # Test infrastructure correlation
            inject_correlated_failure "kafka-1" "kafka-2"
            # Test application correlation
            inject_correlated_failure "cpl-service" "api"
            ;;

        "split-brain")
            log "🧠 Running split-brain chaos mode"
            inject_split_brain
            ;;

        *)
            error "Unknown chaos mode: $mode"
            echo "Available modes: random, network, resource, correlated, split-brain"
            return 1
            ;;
    esac
}

check_recovery() {
    local service="$1"
    local service_type="${SERVICES[$service]}"
    local timeout="${RECOVERY_TIMEOUTS[$service_type]:-30}"

    log "⏳ Checking recovery of $service (${timeout}s timeout)"

    local start_time=$(date +%s)
    while (( $(date +%s) - start_time < timeout )); do
        if docker ps --format '{{.Names}}' | grep -q "^${service}$"; then
            log "✅ $service recovered successfully"
            return 0
        fi
        sleep 2
    done

    error "$service failed to recover within ${timeout}s"
    return 1
}

main() {
    log "=== SpiderNetOS Advanced Chaos Testing ==="
    log "Mode: $CHAOS_MODE"
    log "Duration: ${CHAOS_DURATION}s"
    log "Iterations: $CHAOS_ITERATIONS"
    log "Log file: $LOG_FILE"
    log ""

    local start_time=$(date +%s)
    local failed_tests=0

    case "$CHAOS_MODE" in
        "benchmark")
            # Run all modes sequentially for comprehensive testing
            for mode in random network resource correlated split-brain; do
                log "--- Testing mode: $mode ---"
                if ! run_chaos_mode "$mode" 2; then
                    ((failed_tests++))
                fi
                log ""
            done
            ;;

        *)
            if ! run_chaos_mode "$CHAOS_MODE" "$CHAOS_ITERATIONS"; then
                ((failed_tests++))
            fi
            ;;
    esac

    local duration=$(( $(date +%s) - start_time ))

    log "=== Chaos Testing Complete ==="
    log "Duration: ${duration}s"
    log "Failed tests: $failed_tests"
    log "Log saved to: $LOG_FILE"

    if [[ $failed_tests -gt 0 ]]; then
        error "SYSTEM NOT FULLY RESILIENT: $failed_tests tests failed"
        exit 1
    else
        log "✅ SYSTEM RESILIENT: All chaos tests passed"
        exit 0
    fi
}

# Usage
if [[ "${1:-}" == "--help" ]]; then
    cat << EOF
SpiderNetOS Advanced Chaos Testing Suite

Usage: $0 [options]

Options:
  --mode MODE       Chaos mode: random, network, resource, correlated, split-brain, benchmark
  --iterations N    Number of iterations (default: 10)
  --duration N      Test duration in seconds (default: 300)
  --help           Show this help

Examples:
  $0 --mode random --iterations 20          # 20 random kills
  $0 --mode network                         # Network failure injection
  $0 --mode benchmark                        # Run all modes

Chaos Modes:
  random      - Randomly kill services and check recovery
  network     - Simulate network partitions
  resource    - Inject CPU/memory/disk exhaustion
  correlated  - Kill related services together
  split-brain - Test network partitions in distributed systems
  benchmark   - Run all modes for comprehensive testing

EOF
    exit 0
fi

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --mode)
            CHAOS_MODE="$2"
            shift 2
            ;;
        --iterations)
            CHAOS_ITERATIONS="$2"
            shift 2
            ;;
        --duration)
            CHAOS_DURATION="$2"
            shift 2
            ;;
        *)
            error "Unknown option: $1"
            exit 1
            ;;
    esac
done

main