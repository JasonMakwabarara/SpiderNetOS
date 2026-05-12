# SpiderNetOS Production Server Status Check

**Server IP:** 5.223.68.233:8000
**Hermes Server:** 37.27.254.140 (Tailscale: 100.120.219.83)
**Status:** Connection refused

## Issue Analysis

The production server is not responding, which means the SpiderNetOS integration code hasn't been deployed or the server isn't running.

## Required Actions

### 1. Access Production Server
```bash
# SSH to production server (adjust credentials as needed)
ssh user@5.223.68.233
# or if using key-based auth:
ssh -i ~/.ssh/production_key user@5.223.68.233
```

### 2. Deploy Integration Code
Once on the production server:

```bash
# Navigate to SpiderNetOS backend
cd /path/to/spidernet/backend

# Copy the integration files (if not already there)
# You'll need to upload: HermesController.php, updated routes/api.php,
# MemoryGraph.php, ReinforcementLearning.php, AgentMesh.php

# Install dependencies
composer install --no-dev --optimize-autoloader

# Run migrations for new tables
php artisan migrate --force

# Clear caches
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
```

### 3. Configure Server for External Access
```bash
# Ensure server binds to external interface
# If using artisan serve:
php artisan serve --host=0.0.0.0 --port=8000

# If using web server (nginx/apache), ensure config allows external access
# Example nginx config:
server {
    listen 8000;
    server_name _;
    root /path/to/spidernet/public;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 4. Open Firewall Port
```bash
# Ubuntu/Debian
sudo ufw allow 8000
sudo ufw reload

# CentOS/RHEL
sudo firewall-cmd --permanent --add-port=8000/tcp
sudo firewall-cmd --reload

# Check if port is open
sudo netstat -tlnp | grep :8000
```

### 5. Test Local Access
```bash
# On production server
curl http://localhost:8000/api/health
curl http://localhost:8000/api/hermes/status
```

### 6. Restart Services
```bash
# If using systemd
sudo systemctl restart nginx
sudo systemctl restart php8.2-fpm

# If using Docker
docker-compose restart

# If using PM2
pm2 restart spidernet
```

## Alternative: Use Tailscale Internal Network

If external access is problematic, configure both servers to use Tailscale:

```bash
# On production server, get Tailscale IP
tailscale ip -4  # Shows: e.g., 100.111.175.105

# On Hermes server, update environment
export SPIDERNET_API_URL="http://100.111.175.105:8000"
```

## Verification Steps

### From Production Server:
```bash
# Test local endpoints
curl http://localhost:8000/api/health
curl http://localhost:8000/api/hermes/status

# Test with sample data
curl -X POST http://localhost:8000/api/hermes/coordinate \
  -H "Content-Type: application/json" \
  -d '{"message": "Test coordination", "channel": "api"}'
```

### From Hermes Server:
```bash
# Test external access
curl http://5.223.68.233:8000/api/health
curl http://5.223.68.233:8000/api/hermes/status

# Test integration script
python3 /opt/hermes-integrations/spidernet_integration.py
```

## Expected Outcome

Once deployed, you should see:
```
Testing SpiderNetOS Hermes integration...
✅ SpiderNet integration test passed
   Supported channels: 9
   Supported integrations: 7
```

## Quick Debug Script

Run this on the production server to check status:

```bash
#!/bin/bash
echo "🔍 Production Server Debug"

# Check if SpiderNetOS is running
if pgrep -f "artisan serve\|php.*spidernet" > /dev/null; then
    echo "✅ SpiderNetOS process is running"
else
    echo "❌ SpiderNetOS process not found"
fi

# Check port binding
if netstat -tlnp | grep :8000 > /dev/null; then
    echo "✅ Port 8000 is bound"
    netstat -tlnp | grep :8000
else
    echo "❌ Port 8000 not bound"
fi

# Test local access
if curl -f -s http://localhost:8000/api/health > /dev/null; then
    echo "✅ Local API accessible"
else
    echo "❌ Local API not accessible"
fi

# Check firewall
if command -v ufw > /dev/null; then
    if ufw status | grep "8000.*ALLOW" > /dev/null; then
        echo "✅ Firewall allows port 8000"
    else
        echo "❌ Firewall blocks port 8000"
    fi
fi
```

The key issue is getting the SpiderNetOS code deployed and running on the production server with external access enabled. Once that's done, the Hermes integration will work! 🎯