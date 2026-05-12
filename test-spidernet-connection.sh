#!/bin/bash
# SpiderNetOS Connection Test Script
# Run on Hermes server to test connectivity to SpiderNetOS

echo "🔍 Testing SpiderNetOS connection..."

# Configuration
SPIDERNET_API_URL="${SPIDERNET_API_URL:-http://100.111.175.105:8000}"

echo "Target URL: $SPIDERNET_API_URL"

# Test basic connectivity
echo "📡 Testing basic connectivity..."
if curl -f -s --max-time 10 "$SPIDERNET_API_URL/api/health" > /dev/null 2>&1; then
    echo "✅ SpiderNetOS API is reachable"
else
    echo "❌ SpiderNetOS API is not reachable at $SPIDERNET_API_URL"
    echo "   Make sure SpiderNetOS is running and the URL is correct"
    exit 1
fi

# Test Hermes endpoints
echo "🔧 Testing Hermes endpoints..."
if curl -f -s --max-time 10 "$SPIDERNET_API_URL/api/hermes/status" > /dev/null 2>&1; then
    echo "✅ Hermes endpoints are available"
else
    echo "❌ Hermes endpoints are not available"
    echo "   Make sure the Hermes controller and routes are deployed"
    exit 1
fi

# Test full integration
echo "🚀 Testing full SpiderNet integration..."
python3 << 'EOF'
import os
import sys
import requests

# Set environment variables
os.environ['SPIDERNET_API_URL'] = '$SPIDERNET_API_URL'

# Import integration (adjust path as needed)
sys.path.append('/opt/hermes-integrations')
try:
    from spidernet_integration import spidernet_status_check
    
    result = spidernet_status_check()
    if result.get('status') == 'operational':
        print("✅ SpiderNet integration test passed")
        print(f"   Supported channels: {len(result.get('details', {}).get('supported_channels', []))}")
        print(f"   Supported integrations: {len(result.get('details', {}).get('supported_integrations', []))}")
    else:
        print("❌ SpiderNet integration test failed")
        print(f"   Result: {result}")
        
except Exception as e:
    print(f"❌ Integration test error: {e}")
EOF

echo ""
echo "🎯 If all tests pass, your integration is ready!"
echo "   You can now use the Hermes skills to coordinate with SpiderNetOS."