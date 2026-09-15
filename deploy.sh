#!/bin/bash
set -e

STACK_NAME="spidernet"
COMPOSE_FILE="docker-compose.prod.yml"

echo "Deploying $STACK_NAME to Docker Swarm..."
docker stack deploy -c $COMPOSE_FILE $STACK_NAME

echo "Waiting for services to stabilize..."
sleep 10

echo "Running smoke tests..."
curl -f https://your-domain.com/api/health || exit 1

echo "Deployment completed successfully."
