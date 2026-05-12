# Quick Fix for Hermes-SpiderNetOS Connection
# Run these commands on your Hermes server

# 1. Set environment variables
export SPIDERNET_API_URL="http://100.111.175.105:8000"
export HERMES_API_TOKEN=""

# 2. Update the integration file (replace the wrong IP)
sed -i 's/http:\/\/5\.223\.68\.233:8000/http:\/\/100\.111\.175\.105:8000/g' /opt/hermes-integrations/spidernet_integration.py

# 3. Or manually edit the file
# nano /opt/hermes-integrations/spidernet_integration.py
# Change: SPIDERNET_API_URL = os.getenv("SPIDERNET_API_URL", "http://5.223.68.233:8000")
# To:     SPIDERNET_API_URL = os.getenv("SPIDERNET_API_URL", "http://100.111.175.105:8000")

# 4. Test the connection
python3 /opt/hermes-integrations/spidernet_integration.py

# Expected output when working:
# Testing SpiderNetOS Hermes integration...
# ✅ SpiderNet integration test passed
#    Supported channels: 9
#    Supported integrations: 7