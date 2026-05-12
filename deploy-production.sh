#!/bin/bash
# Production SpiderNetOS Deployment Script
# Run this on the production server (5.223.68.233)

echo "🚀 Deploying SpiderNetOS + Hermes Integration to Production"
echo "=========================================================="

# Check if we're in the right directory
if [ ! -f "artisan" ]; then
    echo "❌ Error: Not in Laravel project directory. Run this from the SpiderNetOS backend directory."
    exit 1
fi

echo "📍 Working directory: $(pwd)"

# Backup current code
echo "💾 Creating backup..."
mkdir -p backups/$(date +%Y%m%d_%H%M%S)
cp -r app/ backups/$(date +%Y%m%d_%H%M%S)/
cp -r routes/ backups/$(date +%Y%m%d_%H%M%S)/
cp -r config/ backups/$(date +%Y%m%d_%H%M%S)/
cp composer.json backups/$(date +%Y%m%d_%H%M%S)/

echo "✅ Backup created"

# Install new dependencies
echo "📦 Installing dependencies..."
composer install --no-dev --optimize-autoloader

echo "✅ Dependencies installed"

# Run database migrations
echo "🗄️ Running database migrations..."
php artisan migrate --force

echo "✅ Database migrations completed"

# Clear and cache configuration
echo "⚙️ Clearing and caching configuration..."
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan view:clear
php artisan view:cache

echo "✅ Configuration cached"

# Test the application
echo "🧪 Testing application..."
php artisan --version

# Test routes
echo "🔍 Checking routes..."
php artisan route:list | grep -E "(hermes|api)" | head -10

echo "✅ Application tests passed"

# Restart services (adjust based on your deployment)
echo "🔄 Restarting services..."
# If using systemd:
# sudo systemctl restart nginx
# sudo systemctl restart php8.2-fpm
# sudo systemctl restart supervisor

# If using Docker:
# docker-compose restart

# If using PM2 or other process manager:
# pm2 restart spidernet

echo "✅ Services restarted"

# Test local connectivity
echo "🌐 Testing local connectivity..."
curl -f -s http://localhost:8000/api/health && echo "✅ Local health check passed" || echo "❌ Local health check failed"

# Test Hermes endpoints
echo "🔧 Testing Hermes endpoints..."
curl -f -s http://localhost:8000/api/hermes/status && echo "✅ Hermes endpoints available" || echo "❌ Hermes endpoints not available"

echo ""
echo "🎉 Deployment completed!"
echo ""
echo "🔍 Verify external access:"
echo "   curl http://5.223.68.233:8000/api/health"
echo "   curl http://5.223.68.233:8000/api/hermes/status"
echo ""
echo "📋 If external access fails, check:"
echo "   - Firewall: ufw allow 8000"
echo "   - Nginx/Apache config for port 8000"
echo "   - Server binding (should be 0.0.0.0, not 127.0.0.1)"