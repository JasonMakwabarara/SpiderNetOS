#!/bin/bash
# SpiderNetOS Internal CA Setup
# Generates 4096-bit RSA CA for service mTLS

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CA_DIR="${SCRIPT_DIR}/../../certs/ca"
mkdir -p "$CA_DIR"

echo "=== SpiderNetOS PKI - Internal CA Setup ==="

# Check if CA already exists
if [[ -f "$CA_DIR/ca.key" && -f "$CA_DIR/ca.crt" ]]; then
    echo "CA already exists at $CA_DIR"
    read -p "Regenerate? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Using existing CA"
        exit 0
    fi
fi

echo "Generating 4096-bit RSA CA key..."
openssl genrsa -out "$CA_DIR/ca.key" 4096
chmod 600 "$CA_DIR/ca.key"

echo "Generating self-signed CA certificate (10-year validity)..."
openssl req -x509 -new -nodes \
    -key "$CA_DIR/ca.key" \
    -sha256 \
    -days 3650 \
    -out "$CA_DIR/ca.crt" \
    -subj "/C=US/ST=California/L=San Francisco/O=SpiderNetOS/OU=Security/CN=SpiderNetOS Internal CA" \
    -config <(cat <<EOF
[req]
distinguished_name = req_distinguished_name
x509_extensions = v3_ca
[req_distinguished_name]
[v3_ca]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer
basicConstraints = critical, CA:true, pathlen:0
keyUsage = critical, digitalSignature, cRLSign, keyCertSign
EOF
)

chmod 644 "$CA_DIR/ca.crt"

echo ""
echo "=== CA Generated Successfully ==="
echo "Private Key: $CA_DIR/ca.key (600 permissions)"
echo "Certificate: $CA_DIR/ca.crt"
echo ""
echo "NEXT: Run generate-service-certs.sh to create service certificates"
