#!/bin/bash
# SpiderNetOS Production Readiness Validation
set -euo pipefail

ERRORS=0

log() {
    echo "[$(date +%H:%M:%S)] $1"
}

check_prerequisites() {
    log "Checking prerequisites..."
    
    # Docker Swarm
    if docker info --format '{{.Swarm.LocalNodeState}}' | grep -q "active"; then
        log "PASS: Docker Swarm active"
    else
        log "FAIL: Docker Swarm not active"
        ((ERRORS++))
    fi
    
    # Required secrets
    local required=("db_password" "redis_password" "pusher_app_secret")
    for secret in "${required[@]}"; do
        if docker secret ls | grep -q "$secret"; then
            log "PASS: Secret $secret exists"
        else
            log "FAIL: Secret $secret missing"
            ((ERRORS++))
        fi
    done
}

check_certificates() {
    log "Checking certificates..."
    if ./scripts/pki/verify-tls.sh >/dev/null 2>&1; then
        log "PASS: TLS certificates valid"
    else
        log "FAIL: TLS certificate issues"
        ((ERRORS++))
    fi
}

run_tests() {
    log "Running test suite..."
    if pip install -q -r requirements-dev.txt && pytest tests/integration -q --tb=short; then
        log "PASS: Integration tests passed"
    else
        log "FAIL: Integration tests failed"
        ((ERRORS++))
    fi
}

check_security() {
    log "Running security audit..."
    if ./scripts/security/security-audit.sh >/dev/null 2>&1; then
        log "PASS: Security audit passed"
    else
        log "FAIL: Security issues found"
        ((ERRORS++))
    fi
}

main() {
    log "=== Production Readiness Validation ==="
    check_prerequisites
    check_certificates
    run_tests
    check_security
    
    log ""
    if [[ $ERRORS -eq 0 ]]; then
        log "=== READY FOR PRODUCTION ==="
        exit 0
    else
        log "=== NOT READY: $ERRORS issues found ==="
        exit 1
    fi
}

main "$@"
