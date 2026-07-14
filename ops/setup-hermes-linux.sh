#!/bin/bash
# SpiderNetOS Hermes Agent Setup for Linux
# Run this AFTER: ollama serve and ollama pull gemma4:31b qwen3.6:latest

set -e

HERMES_DIR="${HERMES_DIR:-./hermes-runtime}"
OLLAMA_URL="${OLLAMA_URL:-http://127.0.0.1:11434}"
SPIDERNET_API="${SPIDERNET_API:-http://127.0.0.1:8000}"

echo "=== SpiderNetOS Hermes Agent Setup (Linux) ==="
echo "Ollama: $OLLAMA_URL"
echo "SpiderNetOS: $SPIDERNET_API"
echo ""

# Check Ollama
echo "[1/4] Checking Ollama..."
if ! curl -s "$OLLAMA_URL/api/tags" > /dev/null; then
    echo "❌ Ollama not running at $OLLAMA_URL"
    echo "Start with: ollama serve"
    exit 1
fi

MODELS=$(curl -s "$OLLAMA_URL/api/tags" | grep -o '"name":"[^"]*"' | cut -d'"' -f4)
echo "✅ Ollama running. Models: $MODELS"

# Check for required models
if ! echo "$MODELS" | grep -q "gemma4:31b"; then
    echo "⚠️  gemma4:31b not found. Pull with: ollama pull gemma4:31b"
fi
if ! echo "$MODELS" | grep -q "qwen3.6"; then
    echo "⚠️  qwen3.6:latest not found. Pull with: ollama pull qwen3.6:latest"
fi

# Create runtime directory
echo ""
echo "[2/4] Setting up Hermes runtime..."
mkdir -p "$HERMES_DIR"
cp hermes-skills/hermes_local_bridge.py "$HERMES_DIR/"
cp hermes-skills/hermes_worker.py "$HERMES_DIR/"

# Create virtual environment
cd "$HERMES_DIR"
python3 -m venv venv
source venv/bin/activate
pip install -q fastapi uvicorn aiohttp redis pydantic requests schedule

echo "✅ Runtime ready at $HERMES_DIR"

# Create startup script
echo ""
echo "[3/4] Creating startup scripts..."
cat > start-hermes.sh << EOF
#!/bin/bash
source venv/bin/activate
export OLLAMA_URL=$OLLAMA_URL
export SPIDERNET_API_URL=$SPIDERNET_API
export REDIS_URL=redis://127.0.0.1:6379/2

# Start API server
python hermes_local_bridge.py &
HERMES_PID=\$!
echo "Hermes API started (PID: \$HERMES_PID) on port 8090"

# Start worker
python hermes_worker.py &
WORKER_PID=\$!
echo "Hermes worker started (PID: \$WORKER_PID)"

echo ""
echo "Test: curl http://localhost:8090/health"
echo "Stop: kill \$HERMES_PID \$WORKER_PID"
wait
EOF
chmod +x start-hermes.sh

echo "✅ Startup script created"

# Test connectivity
echo ""
echo "[4/4] Testing connectivity..."
sleep 2

if curl -s "$SPIDERNET_API/health" > /dev/null 2>&1; then
    echo "✅ SpiderNetOS API reachable"
else
    echo "⚠️  SpiderNetOS API not reachable at $SPIDERNET_API"
    echo "   Start SpiderNetOS before running Hermes"
fi

echo ""
echo "=== Setup Complete ==="
echo ""
echo "To start Hermes:"
echo "  cd $HERMES_DIR && ./start-hermes.sh"
echo ""
echo "Test endpoints:"
echo "  curl http://localhost:8090/health"
echo "  curl -X POST http://localhost:8090/api/coordinate \\"
echo "    -H 'Content-Type: application/json' \\"
echo "    -d '{\"message\":\"Hello from Hermes\"}'"
