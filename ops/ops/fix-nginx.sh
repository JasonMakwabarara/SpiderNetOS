#!/bin/bash
# Fix nginx in Docker container (no systemd)

echo "Fixing nginx..."

# Kill existing nginx
pkill -f nginx
sleep 1

# Start nginx directly
nginx

# Test
sleep 1
echo "Testing localhost:80..."
curl -I http://localhost:80

echo ""
echo "Testing localhost:3000 directly..."
curl -I http://localhost:3000
