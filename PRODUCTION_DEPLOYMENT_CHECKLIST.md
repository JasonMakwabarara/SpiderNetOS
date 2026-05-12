# SpiderNetOS Production Server Checklist
# Run this to verify your production server is ready for Hermes integration

## 1. Server Access
```bash
# SSH to production server
ssh root@5.223.68.233
cd /path/to/spidernet/backend
```

## 2. Code Deployment
```bash
# Copy the new files we created
# (Assuming you have them available on the server)

# Check if files exist
ls -la app/Http/Controllers/HermesController.php
ls -la routes/api.php
ls -la app/Services/MemoryGraph.php
ls -la app/Services/ReinforcementLearning.php
ls -la app/Services/AgentMesh.php
```

## 3. Dependencies & Migration
```bash
# Install/update dependencies
composer install --no-dev --optimize-autoloader

# Run migrations for new tables
php artisan migrate --force

# Clear caches
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
```

## 4. Server Configuration
```bash
# Check if server is binding to external interface
netstat -tlnp | grep :8000

# Should show: 0.0.0.0:8000 or specific IP:8000
# NOT: 127.0.0.1:8000

# Check firewall
ufw status | grep 8000
# Should show: 8000 ALLOW IN Anywhere
```

## 5. Service Restart
```bash
# Restart web server (adjust based on your setup)
sudo systemctl restart nginx
sudo systemctl restart php8.2-fpm

# Or if using Docker:
docker-compose restart

# Or if using PM2:
pm2 restart spidernet
```

## 6. Test Locally
```bash
# Test health endpoint
curl http://localhost:8000/api/health

# Test Hermes endpoints
curl http://localhost:8000/api/hermes/status

# Test with sample data
curl -X POST http://localhost:8000/api/hermes/coordinate \
  -H "Content-Type: application/json" \
  -d '{"message": "Test coordination", "channel": "api"}'
```

## 7. Test Externally
```bash
# From another machine (like your Hermes server)
curl http://5.223.68.233:8000/api/health
curl http://5.223.68.233:8000/api/hermes/status
```

## 8. Common Issues

### Issue: Connection refused
**Cause**: Server not running or not binding to external IP
**Fix**: 
```bash
# Check server binding
php artisan serve --host=0.0.0.0 --port=8000
# Or update your web server config
```

### Issue: 404 on /api/hermes/*
**Cause**: Routes not deployed
**Fix**:
```bash
php artisan route:clear
php artisan route:cache
php artisan route:list | grep hermes
```

### Issue: 500 Internal Server Error
**Cause**: Missing dependencies or code errors
**Fix**:
```bash
# Check logs
tail -f storage/logs/laravel.log

# Test specific endpoint
php artisan tinker
# Then: app(\App\Http\Controllers\HermesController::class)->status()
```

### Issue: Firewall blocking
**Cause**: Port 8000 not open
**Fix**:
```bash
sudo ufw allow 8000
sudo ufw reload
```

## 9. Production Monitoring
```bash
# Monitor logs
tail -f storage/logs/laravel.log

# Check resource usage
htop

# Monitor network connections
netstat -tlnp | grep :8000
```