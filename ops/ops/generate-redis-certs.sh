#!/bin/bash
# Generate TLS certificates for Redis encryption
# SpiderNet OS - Cache Security Setup

set -e

CERT_DIR="./redis/tls"
mkdir -p "$CERT_DIR"

echo "Generating TLS certificates for Redis..."

# Generate CA private key
openssl genrsa -out "$CERT_DIR/ca.key" 4096

# Generate CA certificate
openssl req -new -x509 -days 3650 -key "$CERT_DIR/ca.key" -sha256 -out "$CERT_DIR/ca.crt" \
  -subj "/C=US/ST=State/L=City/O=SpiderNet/OU=Cache/CN=SpiderNet CA"

# Generate Redis private key
openssl genrsa -out "$CERT_DIR/redis.key" 4096

# Generate certificate signing request
openssl req -subj "/C=US/ST=State/L=City/O=SpiderNet/OU=Cache/CN=localhost" \
  -new -key "$CERT_DIR/redis.key" -out "$CERT_DIR/redis.csr"

# Generate Redis certificate
openssl x509 -req -days 3650 -in "$CERT_DIR/redis.csr" -CA "$CERT_DIR/ca.crt" \
  -CAkey "$CERT_DIR/ca.key" -CAcreateserial -out "$CERT_DIR/redis.crt" \
  -sha256 -extfile <(printf "subjectAltName=DNS:localhost,DNS:redis,IP:127.0.0.1,IP:172.20.0.1")

# Set proper permissions
chmod 600 "$CERT_DIR/redis.key"
chmod 644 "$CERT_DIR/redis.crt" "$CERT_DIR/ca.crt"

echo "TLS certificates generated successfully!"
echo "Location: $CERT_DIR"
ls -la "$CERT_DIR"