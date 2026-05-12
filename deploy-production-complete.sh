#!/bin/bash
# SpiderNetOS Production Deployment Script
# Run this on your production server: 5.223.68.233

echo "🚀 SpiderNetOS Production Deployment & Hermes Integration Setup"
echo "================================================================="

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] $1${NC}"
}

error() {
    echo -e "${RED}[ERROR] $1${NC}"
}

warn() {
    echo -e "${YELLOW}[WARN] $1${NC}"
}

info() {
    echo -e "${BLUE}[INFO] $1${NC}"
}

# Check if we're in the right directory
if [ ! -f "artisan" ] && [ ! -f "composer.json" ]; then
    error "Not in Laravel project directory. Please navigate to your SpiderNetOS backend directory."
    error "Expected to find: artisan, composer.json"
    echo "Current directory: $(pwd)"
    echo "Contents:"
    ls -la
    exit 1
fi

log "Working directory: $(pwd)"

# Backup current state
log "Creating deployment backup..."
BACKUP_DIR="backups/$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

if [ -f "composer.lock" ]; then cp composer.lock "$BACKUP_DIR/"; fi
if [ -d "app" ]; then cp -r app/ "$BACKUP_DIR/"; fi
if [ -d "routes" ]; then cp -r routes/ "$BACKUP_DIR/"; fi
if [ -d "config" ]; then cp -r config/ "$BACKUP_DIR/"; fi

log "✅ Backup created in $BACKUP_DIR"

# Install/update dependencies
log "Installing PHP dependencies..."
if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --optimize-autoloader
    if [ $? -eq 0 ]; then
        log "✅ Composer dependencies installed"
    else
        error "Failed to install composer dependencies"
        exit 1
    fi
else
    error "Composer not found. Please install composer first."
    exit 1
fi

# Run database migrations
log "Running database migrations..."
if php artisan migrate --force; then
    log "✅ Database migrations completed"
else
    error "Database migration failed"
    exit 1
fi

# Clear and rebuild caches
log "Clearing and rebuilding caches..."
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan view:clear
php artisan view:cache

log "✅ Caches cleared and rebuilt"

# Verify routes exist
log "Verifying Hermes routes..."
if php artisan route:list --path=hermes | grep -q "hermes"; then
    log "✅ Hermes routes found:"
    php artisan route:list --path=hermes | grep hermes
else
    error "Hermes routes not found. Please ensure HermesController.php and routes are properly deployed."
    exit 1
fi

# Test application
log "Testing application..."
if php artisan --version >/dev/null 2>&1; then
    log "✅ Laravel application functional"
else
    error "Laravel application not functional"
    exit 1
fi

# Check database connection
log "Testing database connection..."
if php artisan tinker --execute="echo 'Database connected';" >/dev/null 2>&1; then
    log "✅ Database connection working"
else
    error "Database connection failed"
    exit 1
fi

# Configure for external access
log "Configuring for external access..."

# Check if server is already running
if pgrep -f "artisan serve" >/dev/null; then
    warn "Artisan serve process found. Stopping it first..."
    pkill -f "artisan serve"
    sleep 2
fi

# Start server with external binding
log "Starting SpiderNetOS server with external access..."
nohup php artisan serve --host=0.0.0.0 --port=8000 > storage/logs/server.log 2>&1 &
SERVER_PID=$!

# Wait a moment for server to start
sleep 3

# Check if server started successfully
if kill -0 $SERVER_PID 2>/dev/null; then
    log "✅ Server started with PID: $SERVER_PID"
else
    error "Failed to start server"
    cat storage/logs/server.log
    exit 1
fi

# Test local access
log "Testing local API access..."
if curl -f -s --max-time 10 http://localhost:8000/api/health >/dev/null 2>&1; then
    log "✅ Local API accessible"
else
    error "Local API not accessible"
    cat storage/logs/server.log
    exit 1
fi

# Test Hermes endpoints
log "Testing Hermes endpoints..."
if curl -f -s --max-time 10 http://localhost:8000/api/hermes/status >/dev/null 2>&1; then
    log "✅ Hermes endpoints accessible locally"
else
    error "Hermes endpoints not accessible locally"
    exit 1
fi

# Configure firewall
log "Configuring firewall..."
if command -v ufw >/dev/null 2>&1; then
    if sudo ufw status | grep -q "8000"; then
        log "✅ Firewall already allows port 8000"
    else
        info "Opening port 8000 in firewall..."
        sudo ufw allow 8000 >/dev/null 2>&1
        sudo ufw reload >/dev/null 2>&1
        log "✅ Port 8000 opened in firewall"
    fi
elif command -v firewall-cmd >/dev/null 2>&1; then
    if sudo firewall-cmd --list-ports | grep -q "8000"; then
        log "✅ Firewall already allows port 8000"
    else
        info "Opening port 8000 in firewall..."
        sudo firewall-cmd --permanent --add-port=8000/tcp >/dev/null 2>&1
        sudo firewall-cmd --reload >/dev/null 2>&1
        log "✅ Port 8000 opened in firewall"
    fi
else
    warn "No recognized firewall found. Please manually ensure port 8000 is open."
fi

# Get server IP for verification
SERVER_IP=$(hostname -I | awk '{print $1}')
if [ -z "$SERVER_IP" ]; then
    SERVER_IP=$(ip route get 1 | awk '{print $7}')
fi

# Final verification
log "Running final verification tests..."

# Test external access (if possible)
if curl -f -s --max-time 10 http://$SERVER_IP:8000/api/health >/dev/null 2>&1; then
    log "✅ External API accessible at http://$SERVER_IP:8000"
else
    warn "⚠️ External API not accessible at http://$SERVER_IP:8000"
    warn "   This may be due to network configuration or firewall rules"
    info "   You can still test from the Hermes server directly to 5.223.68.233:8000"
fi

# Save deployment info
cat > deployment-info.txt << EOF
SpiderNetOS Deployment Information
==================================
Deployment Date: $(date)
Server IP: $SERVER_IP
External IP: 5.223.68.233
Port: 8000
Server PID: $SERVER_PID

Hermes Integration Status:
- Routes: Deployed
- Controller: Deployed
- Database: Migrated
- Cache: Cleared

Test Endpoints:
- Health: http://$SERVER_IP:8000/api/health
- Hermes Status: http://$SERVER_IP:8000/api/hermes/status
- External Health: http://5.223.68.233:8000/api/health

Backup Location: $BACKUP_DIR
Log File: storage/logs/server.log
EOF

log "✅ Deployment completed successfully!"
log ""
log "📋 Deployment Summary:"
echo "   Server PID: $SERVER_PID"
echo "   Local Access: http://$SERVER_IP:8000"
echo "   External Access: http://5.223.68.233:8000"
echo "   Backup: $BACKUP_DIR"
echo ""
log "🔧 Next Steps:"
echo "   1. Test from Hermes server: python3 /opt/hermes-integrations/spidernet_integration.py"
echo "   2. Monitor logs: tail -f storage/logs/server.log"
echo "   3. Check deployment info: cat deployment-info.txt"
echo ""
log "🎯 The integration should now be ready for testing from the Hermes server!"

# Keep server running
wait $SERVER_PID