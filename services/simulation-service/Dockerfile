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
# Drop root. Everything above this line needs it (apt, pip); nothing below does.
# The service binds a port above 1024, so no capability is given up, and /app is
# handed over so uvicorn can read what was copied in.
RUN useradd --create-home --uid 10001 appuser && chown -R appuser:appuser /app
USER appuser

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "9200"]
