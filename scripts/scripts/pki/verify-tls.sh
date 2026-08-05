#!/bin/bash
# SpiderNetOS TLS Verification Script
# Validates all service certificates and TLS configurations

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CA_DIR="${SCRIPT_DIR}/../../certs/ca"
SERVICES_DIR="${SCRIPT_DIR}/../../certs/services"

ERRORS=0

echo "=== SpiderNetOS TLS Verification ==="
echo ""

# 1. Verify CA certificate
echo "1. Verifying CA certificate..."
if [[ ! -f "$CA_DIR/ca.crt" ]]; then
    echo "   ✗ CA certificate not found"
    ((ERRORS++))
else
    if openssl x509 -in "$CA_DIR/ca.crt" -noout 2>/dev/null; then
        echo "   ✓ CA certificate valid"
        echo "   Subject: $(openssl x509 -in "$CA_DIR/ca.crt" -noout -subject | cut -d'=' -f2-)"
        echo "   Valid until: $(openssl x509 -in "$CA_DIR/ca.crt" -noout -enddate | cut -d'=' -f2)"
    else
        echo "   ✗ CA certificate invalid"
        ((ERRORS++))
    fi
fi
echo ""

# 2. Verify service certificates
echo "2. Verifying service certificates..."
for service_dir in "$SERVICES_DIR"/*; do
    if [[ -d "$service_dir" ]]; then
        service=$(basename "$service_dir")
        cert_file="$service_dir/$service.crt"
        key_file="$service_dir/$service.key"
        
        echo "   $service:"
        
        # Check cert exists and is valid
        if [[ -f "$cert_file" ]]; then
            if openssl x509 -in "$cert_file" -noout 2>/dev/null; then
                echo "     ✓ Certificate valid"
                
                # Check expiration
                expiry=$(openssl x509 -in "$cert_file" -noout -enddate | cut -d'=' -f2)
                echo "     ✓ Expires: $expiry"
                
                # Check it's signed by our CA
                if openssl verify -CAfile "$CA_DIR/ca.crt" "$cert_file" 2>/dev/null | grep -q "OK"; then
                    echo "     ✓ Signed by internal CA"
                else
                    echo "     ✗ Not signed by internal CA"
                    ((ERRORS++))
                fi
            else
                echo "     ✗ Certificate invalid"
                ((ERRORS++))
            fi
        else
            echo "     ✗ Certificate not found"
            ((ERRORS++))
        fi
        
        # Check key exists and matches cert
        if [[ -f "$key_file" ]]; then
            cert_modulus=$(openssl x509 -noout -modulus -in "$cert_file" 2>/dev/null | md5sum)
            key_modulus=$(openssl rsa -noout -modulus -in "$key_file" 2>/dev/null | md5sum)
            if [[ "$cert_modulus" == "$key_modulus" ]]; then
                echo "     ✓ Key matches certificate"
            else
                echo "     ✗ Key does not match certificate"
                ((ERRORS++))
            fi
        else
            echo "     ✗ Private key not found"
            ((ERRORS++))
        fi
    fi
done
echo ""

# 3. Check certificate expiration (warn if < 30 days)
echo "3. Checking expiration warnings (< 30 days)..."
for service_dir in "$SERVICES_DIR"/*; do
    if [[ -d "$service_dir" ]]; then
        service=$(basename "$service_dir")
        cert_file="$service_dir/$service.crt"
        
        if [[ -f "$cert_file" ]]; then
            expiry_epoch=$(openssl x509 -in "$cert_file" -noout -enddate | cut -d'=' -f2 | xargs -I {} date -d "{}" +%s 2>/dev/null || echo "0")
            now_epoch=$(date +%s)
            days_until_expiry=$(( (expiry_epoch - now_epoch) / 86400 ))
            
            if [[ $days_until_expiry -lt 30 ]]; then
                echo "   ⚠ $service expires in $days_until_expiry days (renew soon!)"
            fi
        fi
    fi
done
echo ""

# Summary
echo "=== Verification Summary ==="
if [[ $ERRORS -eq 0 ]]; then
    echo "✓ All TLS configurations valid"
    exit 0
else
    echo "✗ $ERRORS error(s) found"
    exit 1
fi
