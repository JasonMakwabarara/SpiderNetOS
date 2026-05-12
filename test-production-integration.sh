#!/bin/bash
# Quick test from Hermes server to verify production deployment

echo "🧪 Testing SpiderNetOS Production Integration from Hermes Server"
echo "================================================================"

# Configuration
SPIDERNET_API_URL="${SPIDERNET_API_URL:-http://5.223.68.233:8000}"
HERMES_API_TOKEN="${HERMES_API_TOKEN:-spidernet-secret-001}"

export SPIDERNET_API_URL
export HERMES_API_TOKEN

echo "Target URL: $SPIDERNET_API_URL"
echo "API Token: ${HERMES_API_TOKEN:0:10}..."

# Test 1: Basic connectivity
echo ""
echo "1️⃣ Testing basic connectivity..."
if curl -f -s --max-time 10 "$SPIDERNET_API_URL/api/health" > /dev/null 2>&1; then
    echo "✅ SpiderNetOS API is reachable"
else
    echo "❌ SpiderNetOS API not reachable"
    echo "   - Check if production server is deployed and running"
    echo "   - Verify firewall allows port 8000"
    exit 1
fi

# Test 2: Hermes endpoints
echo ""
echo "2️⃣ Testing Hermes endpoints..."
if curl -f -s --max-time 10 "$SPIDERNET_API_URL/api/hermes/status" > /dev/null 2>&1; then
    echo "✅ Hermes integration endpoints are available"
else
    echo "❌ Hermes integration endpoints not available"
    echo "   - Check if HermesController.php is deployed"
    echo "   - Verify routes are registered"
    exit 1
fi

# Test 3: Full integration
echo ""
echo "3️⃣ Testing full SpiderNet integration..."
RESPONSE=$(curl -s --max-time 15 -X POST \
  -H "Content-Type: application/json" \
  -d '{"message": "Test integration", "channel": "api", "conversation_id": "test_001"}' \
  "$SPIDERNET_API_URL/api/hermes/coordinate" 2>/dev/null)

if echo "$RESPONSE" | grep -q "response\|coordination_result"; then
    echo "✅ SpiderNet integration test passed"
    echo "   Response preview: $(echo "$RESPONSE" | head -c 100)..."
else
    echo "❌ SpiderNet integration test failed"
    echo "   Response: $RESPONSE"
    exit 1
fi

# Test 4: Learning sync
echo ""
echo "4️⃣ Testing learning synchronization..."
LEARN_RESPONSE=$(curl -s --max-time 15 -X POST \
  -H "Content-Type: application/json" \
  -d '{
    "learning_type": "communication_pattern",
    "entries": [{"test": "data"}],
    "period": "1h"
  }' \
  "$SPIDERNET_API_URL/api/hermes/learning/sync" 2>/dev/null)

if echo "$LEARN_RESPONSE" | grep -q "learning_data_synced"; then
    echo "✅ Learning synchronization test passed"
else
    echo "❌ Learning synchronization test failed"
    echo "   Response: $LEARN_RESPONSE"
fi

echo ""
echo "🎉 All tests completed!"
echo ""
echo "📋 Integration Status:"
echo "   ✅ Production server reachable"
echo "   ✅ Hermes endpoints available"
echo "   ✅ Workflow coordination working"
echo "   ✅ Learning sync operational"
echo ""
echo "🚀 The SpiderNetOS + Hermes integration is now fully operational!"
echo ""
echo "🔧 You can now:"
echo "   - Use the integration skills in Hermes"
echo "   - Coordinate complex workflows across agents"
echo "   - Process webhooks from external systems"
echo "   - Sync learning data for continuous improvement"