#!/bin/bash
# Production SpiderNetOS Connection Debug Script

echo "🔍 Debugging SpiderNetOS Production Server Connection"
echo "Target: 5.223.68.233:8000"
echo "=================================================="

# Basic connectivity tests
echo "📡 Testing basic network connectivity..."
ping -c 3 5.223.68.233

echo ""
echo "🔍 Testing port 8000..."
timeout 10 nc -zv 5.223.68.233 8000 2>/dev/null && echo "✅ Port 8000 is open" || echo "❌ Port 8000 is closed/filtered"

echo ""
echo "🌐 Testing HTTP connectivity..."
curl -v --max-time 10 --connect-timeout 5 http://5.223.68.233:8000/api/health 2>&1 | head -20

echo ""
echo "🔧 Testing specific Hermes endpoints..."
curl -v --max-time 10 --connect-timeout 5 http://5.223.68.233:8000/api/hermes/status 2>&1 | head -20

echo ""
echo "📋 Diagnostics Summary:"
echo "1. If ping fails: Network connectivity issue"
echo "2. If port closed: Firewall blocking or server not running"
echo "3. If connection timeout: Server overloaded or network issue"
echo "4. If 404 on /api/health: SpiderNetOS not deployed or wrong path"
echo "5. If 404 on /api/hermes/status: Hermes controller not deployed"