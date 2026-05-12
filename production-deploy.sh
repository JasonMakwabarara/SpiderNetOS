#!/bin/bash
# SpiderNetOS Production Deployment - Run on 5.223.68.233

echo "🚀 Deploying SpiderNetOS + Hermes Integration"
echo "============================================="

# Set working directory (adjust if different)
cd /var/www/spidernet/backend || cd ~/spidernet/backend || {
    echo "❌ Cannot find SpiderNetOS backend directory"
    echo "Current directory: $(pwd)"
    echo "Please navigate to the correct directory and run this script"
    exit 1
}

echo "📍 Working in: $(pwd)"

# Create backup
echo "💾 Creating backup..."
BACKUP_DIR="backups/$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"
cp -r app/ "$BACKUP_DIR/" 2>/dev/null || echo "No app directory to backup"
cp -r routes/ "$BACKUP_DIR/" 2>/dev/null || echo "No routes directory to backup"
cp composer.json "$BACKUP_DIR/" 2>/dev/null || echo "No composer.json to backup"

echo "✅ Backup created in $BACKUP_DIR"

# Check if composer exists
if ! command -v composer >/dev/null 2>&1; then
    echo "❌ Composer not found. Installing..."
    curl -sS https://getcomposer.org/installer | php
    mv composer.phar /usr/local/bin/composer
fi

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

# Check if Hermes routes exist
echo "🔍 Checking Hermes integration..."
if php artisan route:list | grep -q hermes; then
    echo "✅ Hermes routes found"
else
    echo "❌ Hermes routes not found - integration code may not be deployed"
    echo "Please ensure the following files are in place:"
    echo "  - app/Http/Controllers/HermesController.php"
    echo "  - routes/api.php (with hermes routes)"
    echo "  - app/Services/MemoryGraph.php"
    echo "  - app/Services/ReinforcementLearning.php"
    echo "  - app/Services/AgentMesh.php"
fi

# Start server with external access
echo "🌐 Starting server with external access..."
pkill -f "artisan serve" 2>/dev/null || true
sleep 2

nohup php artisan serve --host=0.0.0.0 --port=8000 > storage/logs/server.log 2>&1 &
SERVER_PID=$!

echo "✅ Server started with PID: $SERVER_PID"
sleep 3

# Test local access
echo "🧪 Testing local access..."
if curl -f -s http://localhost:8000/api/health >/dev/null 2>&1; then
    echo "✅ Local API accessible"
else
    echo "❌ Local API not accessible"
    cat storage/logs/server.log
fi

# Configure firewall
echo "🔥 Configuring firewall..."
if command -v ufw >/dev/null 2>&1; then
    ufw allow 8000 >/dev/null 2>&1
    ufw reload >/dev/null 2>&1
    echo "✅ UFW configured"
elif command -v firewall-cmd >/dev/null 2>&1; then
    firewall-cmd --permanent --add-port=8000/tcp >/dev/null 2>&1
    firewall-cmd --reload >/dev/null 2>&1
    echo "✅ Firewalld configured"
else
    echo "⚠️ No firewall management tool found"
fi

# Get external IP
EXTERNAL_IP=$(curl -s https://api.ipify.org 2>/dev/null || echo "5.223.68.233")

echo ""
echo "🎉 Deployment completed!"
echo "======================"
echo "Server PID: $SERVER_PID"
echo "Local Access: http://localhost:8000"
echo "External Access: http://$EXTERNAL_IP:8000"
echo ""
echo "🧪 Test commands:"
echo "  curl http://localhost:8000/api/health"
echo "  curl http://$EXTERNAL_IP:8000/api/hermes/status"
echo ""
echo "📝 To test from Hermes server:"
echo "  export SPIDERNET_API_URL='http://$EXTERNAL_IP:8000'"
echo "  python3 /opt/hermes-integrations/spidernet_integration.py"