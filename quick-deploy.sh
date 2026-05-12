# Quick Production Deployment Script
# Run this on your production SpiderNetOS server

#!/bin/bash

echo "🚀 Quick SpiderNetOS Production Deployment"

# Navigate to backend directory (adjust path as needed)
cd /var/www/spidernet/backend || cd ~/spidernet/backend || echo "Please navigate to your SpiderNetOS backend directory"

# Install dependencies
echo "📦 Installing dependencies..."
composer install --no-dev --optimize-autoloader

# Run migrations
echo "🗄️ Running migrations..."
php artisan migrate --force

# Clear caches
echo "⚙️ Clearing caches..."
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache

# Test application
echo "🧪 Testing application..."
php artisan --version
php artisan route:list | grep hermes

# Check if server is running
echo "🌐 Checking server status..."
curl -f -s http://localhost:8000/api/health && echo "✅ Local server responding" || echo "❌ Local server not responding"

echo ""
echo "🎯 Next steps:"
echo "1. Make sure your web server is configured for external access (0.0.0.0:8000, not 127.0.0.1)"
echo "2. Open port 8000 in firewall: sudo ufw allow 8000"
echo "3. Restart web server: sudo systemctl restart nginx && sudo systemctl restart php8.2-fpm"
echo "4. Test external access: curl http://5.223.68.233:8000/api/health"