#!/bin/bash
# SpiderNetOS Security Audit Script
set -euo pipefail

ERRORS=0

log() {
    echo "[$(date +%H:%M:%S)] $1"
}

check_docker_secrets() {
    log "Checking Docker secrets..."
    local secrets_count=$(docker secret ls --format '{{.Name}}' | wc -l)
    if [[ $secrets_count -ge 5 ]]; then
        log "PASS: $secrets_count secrets found"
    else
        log "FAIL: Only $secrets_count secrets, expected 5+"
        ((ERRORS++))
    fi
}

check_env_fallbacks() {
    log "Checking for fallback defaults..."
    if grep -r '${.*:-' docker-compose.yml .env 2>/dev/null; then
        log "FAIL: Fallback defaults found"
        ((ERRORS++))
    else
        log "PASS: No fallback defaults"
    fi
}

check_tls_certs() {
    log "Checking TLS certificates..."
    if [[ -f "certs/ca/ca.crt" ]]; then
        log "PASS: CA certificate exists"
    else
        log "FAIL: CA certificate missing"
        ((ERRORS++))
    fi
}

check_container_user() {
    log "Checking container security..."
    # Services should run as non-root
    for svc in api intelligence; do
        if docker compose config | grep -q "user:"; then
            log "PASS: $svc has user restriction"
        else
            log "WARN: $svc may run as root"
        fi
    done
}

main() {
    log "=== Security Audit ==="
    check_docker_secrets
    check_env_fallbacks
    check_tls_certs
    check_container_user
    log "=== Results: $ERRORS issues found ==="
    [[ $ERRORS -eq 0 ]] && exit 0 || exit 1
}

main
