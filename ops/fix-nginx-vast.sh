#!/bin/bash
# Proper nginx config for Vast.ai port 18368

cat > /tmp/nginx-vast.conf << 'EOF'
worker_processes 1;

events {
    worker_connections 1024;
}

http {
    server {
        listen 18368;
        server_name _;
        
        location / {
            proxy_pass http://localhost:3000/;
            proxy_set_header Host $host;
        }
        
        location /app/ {
            proxy_pass http://localhost:3001/;
            proxy_set_header Host $host;
        }
        
        # Legacy path: same SPA as apex (cockpit listens on :3000)
        location /cockpit/ {
            proxy_pass http://localhost:3000/;
            proxy_set_header Host $host;
        }
    }
}
EOF

# Kill existing nginx
pkill -f nginx
sleep 1

# Start nginx with this config
nginx -c /tmp/nginx-vast.conf

# Test
echo "Testing localhost:18368..."
curl -I http://localhost:18368

echo ""
echo "Try these URLs in your browser:"
echo "  http://220.134.41.156:8080/          (cockpit SPA apex)"
echo "  http://220.134.41.156:8080/app/      (customer site)"
echo "  http://220.134.41.156:8080/cockpit/   (cockpit SPA, legacy path)"
