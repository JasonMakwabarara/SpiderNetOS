#!/bin/bash
# SpiderNetOS Monitoring Stack Setup
# Automated deployment of Prometheus + Grafana + AlertManager

set -euo pipefail

MONITORING_DIR="monitoring"
STACK_NAME="spidernet-monitoring"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

create_monitoring_config() {
    log "📊 Creating monitoring configuration..."

    mkdir -p "$MONITORING_DIR"/{prometheus,grafana,alertmanager}

    # Prometheus configuration
    cat > "$MONITORING_DIR/prometheus/prometheus.yml" << 'EOF'
global:
  scrape_interval: 15s
  evaluation_interval: 15s

rule_files:
  - "alert_rules.yml"

alerting:
  alertmanagers:
    - static_configs:
        - targets:
          - alertmanager:9093

scrape_configs:
  - job_name: 'spidernet-services'
    static_configs:
      - targets: ['api:8000', 'cpl-service:9100', 'inference:9000', 'simulation-service:9200']
    metrics_path: '/metrics'
    scrape_interval: 10s

  - job_name: 'infrastructure'
    static_configs:
      - targets: ['node-exporter:9100']
    scrape_interval: 30s

  - job_name: 'kafka'
    static_configs:
      - targets: ['kafka-1:9092', 'kafka-2:9093', 'kafka-3:9094']
    scrape_interval: 30s

  - job_name: 'database'
    static_configs:
      - targets: ['db-primary:9187', 'db-replica:9187']
    scrape_interval: 30s

  - job_name: 'redis'
    static_configs:
      - targets: ['redis:9121']
    scrape_interval: 30s
EOF

    # Alert rules
    cat > "$MONITORING_DIR/prometheus/alert_rules.yml" << 'EOF'
groups:
  - name: spidernet_alerts
    rules:
      - alert: HighErrorRate
        expr: rate(http_requests_total{status=~"5.."}[5m]) / rate(http_requests_total[5m]) > 0.05
        for: 5m
        labels:
          severity: critical
        annotations:
          summary: "High error rate detected"
          description: "Error rate is {{ $value }}%"

      - alert: ServiceDown
        expr: up == 0
        for: 2m
        labels:
          severity: critical
        annotations:
          summary: "Service {{ $labels.job }} is down"
          description: "Service {{ $labels.job }} has been down for more than 2 minutes"

      - alert: HighCPUUsage
        expr: rate(cpu_usage_percent[5m]) > 85
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High CPU usage on {{ $labels.instance }}"
          description: "CPU usage is {{ $value }}%"

      - alert: LowMemory
        expr: (1 - memory_available_bytes / memory_total_bytes) > 0.9
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "Low memory on {{ $labels.instance }}"
          description: "Memory usage is {{ $value }}%"

      - alert: KafkaLag
        expr: kafka_consumer_group_lag > 1000
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High Kafka consumer lag"
          description: "Consumer group {{ $labels.consumergroup }} lag is {{ $value }}"

      - alert: DatabaseConnectionsHigh
        expr: pg_stat_activity_count{state="active"} > 50
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High database connections"
          description: "Active connections: {{ $value }}"

      - alert: RedisMemoryHigh
        expr: redis_memory_used_bytes / redis_memory_max_bytes > 0.8
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "Redis memory usage high"
          description: "Redis memory usage: {{ $value }}%"
EOF

    # AlertManager configuration
    cat > "$MONITORING_DIR/alertmanager/alertmanager.yml" << 'EOF'
global:
  smtp_smarthost: 'smtp.gmail.com:587'
  smtp_from: 'alerts@spidernet.os'
  smtp_auth_username: 'alerts@spidernet.os'
  smtp_auth_password: 'your-smtp-password'

templates:
  - '/etc/alertmanager/templates/*.tmpl'

route:
  group_by: ['alertname']
  group_wait: 10s
  group_interval: 10s
  repeat_interval: 1h
  receiver: 'email-notifications'
  routes:
  - match:
      severity: critical
    receiver: 'critical-pager'
  - match:
      severity: warning
    receiver: 'warning-email'

receivers:
- name: 'critical-pager'
  pagerduty_configs:
  - service_key: 'your-pagerduty-key'
  description: '{{ .CommonAnnotations.summary }}'
  details:
    firing: '{{ .Firing }}'
    resolved: '{{ .Resolved }}'

- name: 'warning-email'
  email_configs:
  - to: 'team@spidernet.os'
    subject: 'SpiderNetOS Alert: {{ .GroupLabels.alertname }}'
    body: |
      Alert: {{ .GroupLabels.alertname }}
      Severity: {{ .CommonLabels.severity }}
      Description: {{ .CommonAnnotations.description }}
      Time: {{ .StartsAt }}

- name: 'email-notifications'
  email_configs:
  - to: 'alerts@spidernet.os'
    subject: 'SpiderNetOS System Alert'
    body: |
      {{ range .Alerts }}
      Alert: {{ .Annotations.summary }}
      Description: {{ .Annotations.description }}
      Severity: {{ .Labels.severity }}
      {{ end }}
EOF

    log "✅ Monitoring configuration created"
}

create_monitoring_stack() {
    log "🐳 Creating monitoring Docker Compose stack..."

    cat > "$MONITORING_DIR/docker-compose.yml" << 'EOF'
version: '3.8'

services:
  prometheus:
    image: prom/prometheus:latest
    ports:
      - "9090:9090"
    volumes:
      - ./prometheus/prometheus.yml:/etc/prometheus/prometheus.yml:ro
      - ./prometheus/alert_rules.yml:/etc/prometheus/alert_rules.yml:ro
      - prometheus_data:/prometheus
    command:
      - '--config.file=/etc/prometheus/prometheus.yml'
      - '--storage.tsdb.path=/prometheus'
      - '--web.console.libraries=/etc/prometheus/console_libraries'
      - '--web.console.templates=/etc/prometheus/consoles'
      - '--storage.tsdb.retention.time=200h'
      - '--web.enable-lifecycle'
    networks:
      - spidernet
    restart: unless-stopped

  alertmanager:
    image: prom/alertmanager:latest
    ports:
      - "9093:9093"
    volumes:
      - ./alertmanager/alertmanager.yml:/etc/alertmanager/alertmanager.yml:ro
    command:
      - '--config.file=/etc/alertmanager/alertmanager.yml'
      - '--storage.path=/alertmanager'
    networks:
      - spidernet
    restart: unless-stopped

  grafana:
    image: grafana/grafana:latest
    ports:
      - "3000:3000"
    environment:
      GF_SECURITY_ADMIN_PASSWORD: 'admin'
      GF_USERS_ALLOW_SIGN_UP: 'false'
      GF_INSTALL_PLUGINS: 'grafana-piechart-panel,grafana-worldmap-panel'
    volumes:
      - grafana_data:/var/lib/grafana
      - ./grafana/provisioning:/etc/grafana/provisioning:ro
      - ./grafana/dashboards:/var/lib/grafana/dashboards:ro
    networks:
      - spidernet
    restart: unless-stopped
    depends_on:
      - prometheus

  node-exporter:
    image: prom/node-exporter:latest
    ports:
      - "9100:9100"
    volumes:
      - /proc:/host/proc:ro
      - /sys:/host/sys:ro
      - /:/rootfs:ro
    command:
      - '--path.procfs=/host/proc'
      - '--path.rootfs=/rootfs'
      - '--path.sysfs=/host/sys'
      - '--collector.filesystem.mount-points-exclude=^/(sys|proc|dev|host|etc)($$|/)'
    networks:
      - spidernet
    restart: unless-stopped

  postgres-exporter:
    image: prometheuscommunity/postgres-exporter:latest
    ports:
      - "9187:9187"
    environment:
      DATA_SOURCE_NAME: "postgresql://spidernet:spidernet@db-primary:5432/spidernet?sslmode=require"
    networks:
      - spidernet
    restart: unless-stopped
    depends_on:
      - db-primary

  redis-exporter:
    image: oliver006/redis_exporter:latest
    ports:
      - "9121:9121"
    environment:
      REDIS_ADDR: 'redis:6379'
      REDIS_PASSWORD: 'redis_secure_password_2026'
    networks:
      - spidernet
    restart: unless-stopped
    depends_on:
      - redis

volumes:
  prometheus_data:
  grafana_data:

networks:
  spidernet:
    external: true
EOF

    log "✅ Monitoring stack configuration created"
}

create_grafana_provisioning() {
    log "📈 Creating Grafana dashboards and datasources..."

    mkdir -p "$MONITORING_DIR/grafana"/{provisioning/{datasources,dashboards},dashboards}

    # Grafana datasource configuration
    cat > "$MONITORING_DIR/grafana/provisioning/datasources/prometheus.yml" << 'EOF'
apiVersion: 1

datasources:
  - name: Prometheus
    type: prometheus
    access: proxy
    url: http://prometheus:9090
    isDefault: true
    editable: true
EOF

    # Dashboard provider configuration
    cat > "$MONITORING_DIR/grafana/provisioning/dashboards/dashboard.yml" << 'EOF'
apiVersion: 1

providers:
  - name: 'SpiderNetOS'
    type: file
    disableDeletion: false
    updateIntervalSeconds: 10
    allowUiUpdates: true
    options:
      path: /var/lib/grafana/dashboards
EOF

    # Main dashboard
    cat > "$MONITORING_DIR/grafana/dashboards/spidernet-overview.json" << 'EOF'
{
  "dashboard": {
    "id": null,
    "title": "SpiderNetOS Overview",
    "tags": ["spidernet", "overview"],
    "timezone": "browser",
    "panels": [
      {
        "id": 1,
        "title": "Service Health",
        "type": "stat",
        "targets": [
          {
            "expr": "up",
            "legendFormat": "{{job}}"
          }
        ]
      },
      {
        "id": 2,
        "title": "HTTP Request Rate",
        "type": "graph",
        "targets": [
          {
            "expr": "rate(http_requests_total[5m])",
            "legendFormat": "{{method}} {{status}}"
          }
        ]
      },
      {
        "id": 3,
        "title": "Error Rate",
        "type": "graph",
        "targets": [
          {
            "expr": "rate(http_requests_total{status=~"5.."}[5m]) / rate(http_requests_total[5m]) * 100",
            "legendFormat": "Error Rate %"
          }
        ]
      },
      {
        "id": 4,
        "title": "Kafka Consumer Lag",
        "type": "graph",
        "targets": [
          {
            "expr": "kafka_consumer_group_lag",
            "legendFormat": "{{consumergroup}}"
          }
        ]
      },
      {
        "id": 5,
        "title": "Database Connections",
        "type": "graph",
        "targets": [
          {
            "expr": "pg_stat_activity_count",
            "legendFormat": "{{state}}"
          }
        ]
      },
      {
        "id": 6,
        "title": "Redis Memory Usage",
        "type": "graph",
        "targets": [
          {
            "expr": "redis_memory_used_bytes / redis_memory_max_bytes * 100",
            "legendFormat": "Memory Usage %"
          }
        ]
      }
    ],
    "time": {
      "from": "now-1h",
      "to": "now"
    },
    "refresh": "30s"
  }
}
EOF

    log "✅ Grafana provisioning created"
}

create_service_metrics() {
    log "📊 Adding metrics endpoints to services..."

    # FastAPI metrics endpoint for CPL service
    cat > "services/cpl-service/metrics.py" << 'EOF'
"""
Prometheus metrics for CPL Service
"""
from prometheus_client import Counter, Histogram, Gauge, generate_latest
from fastapi import Response
from fastapi.responses import PlainTextResponse

# Metrics
REQUEST_COUNT = Counter('cpl_requests_total', 'Total CPL requests', ['method', 'endpoint', 'status'])
REQUEST_LATENCY = Histogram('cpl_request_duration_seconds', 'CPL request duration', ['method', 'endpoint'])
ACTIVE_TRAINING = Gauge('cpl_active_training_jobs', 'Number of active training jobs')
POLICY_UPDATES = Counter('cpl_policy_updates_total', 'Total policy updates')

def get_metrics():
    """Return Prometheus metrics"""
    return PlainTextResponse(generate_latest(), media_type="text/plain; charset=utf-8")

# Integration points for existing code
def track_request(method: str, endpoint: str, status: int, duration: float):
    """Track API request metrics"""
    REQUEST_COUNT.labels(method=method, endpoint=endpoint, status=status).inc()
    REQUEST_LATENCY.labels(method=method, endpoint=endpoint).observe(duration)

def increment_training_jobs():
    """Increment active training jobs"""
    ACTIVE_TRAINING.inc()

def decrement_training_jobs():
    """Decrement active training jobs"""
    ACTIVE_TRAINING.dec()

def record_policy_update():
    """Record policy update"""
    POLICY_UPDATES.inc()
EOF

    # Update main.py to include metrics
    if ! grep -q "from metrics import" services/cpl-service/main.py; then
        sed -i '1a from metrics import get_metrics, track_request' services/cpl-service/main.py
    fi

    # Add metrics endpoint
    if ! grep -q "/metrics" services/cpl-service/main.py; then
        # Find the app definition and add metrics endpoint
        sed -i '/app = FastAPI/a @app.get("/metrics")\nasync def metrics():\n    return get_metrics()' services/cpl-service/main.py
    fi

    log "✅ Service metrics endpoints added"
}

main() {
    log "🚀 Setting up SpiderNetOS Monitoring Stack"

    create_monitoring_config
    create_monitoring_stack
    create_grafana_provisioning
    create_service_metrics

    log ""
    log "🎉 Monitoring stack setup complete!"
    log ""
    log "To start monitoring:"
    log "  cd monitoring && docker compose up -d"
    log ""
    log "Access points:"
    log "  📊 Grafana:    http://localhost:3000 (admin/admin)"
    log "  📈 Prometheus: http://localhost:9090"
    log "  🚨 AlertManager: http://localhost:9093"
    log ""
    log "Default dashboards will be automatically provisioned."
}

if [[ "${1:-}" == "--help" ]]; then
    cat << 'EOF'
SpiderNetOS Monitoring Stack Setup

This script sets up a complete observability stack including:
- Prometheus for metrics collection
- Grafana for dashboards and visualization
- AlertManager for alert routing
- Node Exporter for system metrics
- PostgreSQL and Redis exporters
- Pre-configured dashboards and alerts

Usage: ./setup-monitoring.sh

The stack will be configured to monitor:
- SpiderNetOS services (API, CPL, Inference, Simulation)
- Infrastructure (CPU, memory, disk)
- Kafka broker metrics
- Database performance
- Redis cache metrics

Alerts are configured for:
- Service downtime
- High error rates
- Resource exhaustion
- Database connection issues

EOF
    exit 0
fi

main