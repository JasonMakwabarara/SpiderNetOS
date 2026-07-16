#!/bin/bash
# Generate SSL certificates for PostgreSQL encryption
# SpiderNet OS - Database Security Setup

set -e

CERT_DIR="./db/ssl"
mkdir -p "$CERT_DIR"

echo "Generating SSL certificates for PostgreSQL..."

# Generate CA private key
openssl genrsa -out "$CERT_DIR/ca.key" 4096

# Generate CA certificate
openssl req -new -x509 -days 3650 -key "$CERT_DIR/ca.key" -sha256 -out "$CERT_DIR/ca.crt" \
  -subj "/C=US/ST=State/L=City/O=SpiderNet/OU=DB/CN=SpiderNet CA"

# Generate server private key
openssl genrsa -out "$CERT_DIR/server.key" 4096

# Generate certificate signing request
openssl req -subj "/C=US/ST=State/L=City/O=SpiderNet/OU=DB/CN=localhost" \
  -new -key "$CERT_DIR/server.key" -out "$CERT_DIR/server.csr"

# Generate server certificate
openssl x509 -req -days 3650 -in "$CERT_DIR/server.csr" -CA "$CERT_DIR/ca.crt" \
  -CAkey "$CERT_DIR/ca.key" -CAcreateserial -out "$CERT_DIR/server.crt" \
  -sha256 -extfile <(printf "subjectAltName=DNS:localhost,DNS:db,IP:127.0.0.1,IP:172.20.0.1")

# Set proper permissions
chmod 600 "$CERT_DIR/server.key"
chmod 644 "$CERT_DIR/server.crt" "$CERT_DIR/ca.crt"

echo "SSL certificates generated successfully!"
echo "Location: $CERT_DIR"
ls -la "$CERT_DIR"