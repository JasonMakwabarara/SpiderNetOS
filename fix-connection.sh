#!/bin/bash
# Quick fix for Hermes-SpiderNetOS connection

# Set the correct environment variables
export SPIDERNET_API_URL="http://100.111.175.105:8000"
export HERMES_API_TOKEN=""

# Update the integration file
sed -i 's|http://5.223.68.233:8000|http://100.111.175.105:8000|g' /opt/hermes-integrations/spidernet_integration.py

# Test the connection
echo "Testing connection to SpiderNetOS..."
python3 /opt/hermes-integrations/spidernet_integration.py

echo "If this works, your integration is ready!"