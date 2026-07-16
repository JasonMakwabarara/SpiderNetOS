#!/bin/bash
# SpiderNetOS Service Certificate Generator
# Generates TLS certificates for all internal services signed by internal CA

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CA_DIR="${SCRIPT_DIR}/../../certs/ca"
SERVICES_DIR="${SCRIPT_DIR}/../../certs/services"

SERVICES=("postgres" "redis" "kafka")
VALID_DAYS=365

echo "=== SpiderNetOS PKI - Service Certificate Generator ==="

# Verify CA exists
if [[ ! -f "$CA_DIR/ca.key" || ! -f "$CA_DIR/ca.crt" ]]; then
    echo "ERROR: CA not found. Run setup-ca.sh first."
    exit 1
fi

mkdir -p "$SERVICES_DIR"

for service in "${SERVICES[@]}"; do
    echo ""
    echo "Generating certificates for $service..."
    
    SERVICE_DIR="$SERVICES_DIR/$service"
    mkdir -p "$SERVICE_DIR"
    
    # Generate private key
    openssl genrsa -out "$SERVICE_DIR/$service.key" 2048
    chmod 600 "$SERVICE_DIR/$service.key"
    
    # Generate CSR with service-specific SANs
    openssl req -new \
        -key "$SERVICE_DIR/$service.key" \
        -out "$SERVICE_DIR/$service.csr" \
        -subj "/C=US/ST=California/L=San Francisco/O=SpiderNetOS/OU=Services/CN=$service" \
        -config <(cat <<EOF
[req]
distinguished_name = req_distinguished_name
req_extensions = v3_req
[req_distinguished_name]
[v3_req]
basicConstraints = CA:FALSE
keyUsage = nonRepudiation, digitalSignature, keyEncipherment
subjectAltName = @alt_names
[alt_names]
DNS.1 = $service
DNS.2 = $service.spidernet
DNS.3 = localhost
IP.1 = 127.0.0.1
IP.2 = 172.20.0.1
EOF
)
    
    # Sign with CA
    openssl x509 -req \
        -in "$SERVICE_DIR/$service.csr" \
        -CA "$CA_DIR/ca.crt" \
        -CAkey "$CA_DIR/ca.key" \
        -CAcreateserial \
        -out "$SERVICE_DIR/$service.crt" \
        -days $VALID_DAYS \
        -sha256 \
        -extensions v3_req \
        -extfile <(cat <<EOF
[v3_req]
basicConstraints = CA:FALSE
keyUsage = nonRepudiation, digitalSignature, keyEncipherment
subjectAltName = @alt_names
[alt_names]
DNS.1 = $service
DNS.2 = $service.spidernet
DNS.3 = localhost
IP.1 = 127.0.0.1
IP.2 = 172.20.0.1
EOF
)
    
    chmod 644 "$SERVICE_DIR/$service.crt"
    
    # Create fullchain (cert + CA)
    cat "$SERVICE_DIR/$service.crt" "$CA_DIR/ca.crt" > "$SERVICE_DIR/$service-fullchain.crt"
    
    # Cleanup CSR
    rm "$SERVICE_DIR/$service.csr"
    
    echo "  ✓ $service.crt (valid $VALID_DAYS days)"
    echo "  ✓ $service.key (2048-bit RSA)"
    echo "  ✓ $service-fullchain.crt"
done

echo ""
echo "=== All Service Certificates Generated ==="
echo "Location: $SERVICES_DIR"
echo ""
echo "Verify with:"
echo "  openssl x509 -in $SERVICES_DIR/postgres/postgres.crt -text -noout"
