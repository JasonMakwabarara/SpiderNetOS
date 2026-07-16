# SpiderNet OS - Simulation Service Dockerfile
FROM python:3.11-slim

WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y \
    gcc \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# Copy requirements
COPY services/simulation-service/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy shared components
COPY services/shared /app/shared

# Copy simulation service
COPY services/simulation-service /app/services/simulation-service
WORKDIR /app/services/simulation-service

# Create directories
RUN mkdir -p /app/policies /app/logs

# Expose port
EXPOSE 9200

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:9200/health')" || exit 1

# Run service
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "9200"]
