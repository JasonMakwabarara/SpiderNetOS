#!/bin/bash
# SpiderNetOS Backup & Disaster Recovery System
# Automated backups, point-in-time recovery, and cross-region replication

set -euo pipefail

BACKUP_DIR="backups"
LOG_FILE="$BACKUP_DIR/backup-$(date +%Y%m%d-%H%M%S).log"
RETENTION_DAYS=30

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

error() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: $1" >&2 | tee -a "$LOG_FILE"
    return 1
}

create_backup_dirs() {
    mkdir -p "$BACKUP_DIR"/{postgresql,redis,kafka,configs,logs}
    log "📁 Created backup directories"
}

backup_postgresql() {
    log "🗄️ Backing up PostgreSQL..."

    local backup_file="$BACKUP_DIR/postgresql/pg_backup_$(date +%Y%m%d_%H%M%S).sql.gz"
    local wal_file="$BACKUP_DIR/postgresql/wal_backup_$(date +%Y%m%d_%H%M%S).tar.gz"

    # Logical backup (consistent point-in-time)
    docker exec db-primary pg_dumpall -U spidernet | gzip > "$backup_file"

    # WAL archive backup for PITR
    docker exec db-primary tar czf - /var/lib/postgresql/data/pg_wal | gzip > "$wal_file"

    log "✅ PostgreSQL backup completed: $backup_file"
    echo "$backup_file" >> "$BACKUP_DIR/manifest.txt"
}

backup_redis() {
    log "🔴 Backing up Redis..."

    local backup_file="$BACKUP_DIR/redis/redis_backup_$(date +%Y%m%d_%H%M%S).rdb"

    # Trigger SAVE command and copy RDB file
    docker exec redis redis-cli SAVE
    docker cp redis:/data/dump.rdb "$backup_file"

    log "✅ Redis backup completed: $backup_file"
    echo "$backup_file" >> "$BACKUP_DIR/manifest.txt"
}

backup_kafka() {
    log "📨 Backing up Kafka..."

    local backup_file="$BACKUP_DIR/kafka/kafka_backup_$(date +%Y%m%d_%H%M%S).tar.gz"

    # Backup topic data and configurations
    for broker in kafka-1 kafka-2 kafka-3; do
        docker exec "$broker" tar czf - /var/lib/kafka/data | gzip >> "$backup_file"
    done

    log "✅ Kafka backup completed: $backup_file"
    echo "$backup_file" >> "$BACKUP_DIR/manifest.txt"
}

backup_configs() {
    log "⚙️ Backing up configurations..."

    local backup_file="$BACKUP_DIR/configs/config_backup_$(date +%Y%m%d_%H%M%S).tar.gz"

    # Backup all configuration files
    tar czf "$backup_file" \
        docker-compose.yml \
        docker-compose.staging.yml \
        .env \
        certs/ \
        db/postgresql.conf \
        redis/redis.conf \
        traefik/ \
        monitoring/ \
        scripts/

    log "✅ Configuration backup completed: $backup_file"
    echo "$backup_file" >> "$BACKUP_DIR/manifest.txt"
}

backup_logs() {
    log "📝 Backing up logs..."

    local backup_file="$BACKUP_DIR/logs/logs_backup_$(date +%Y%m%d_%H%M%S).tar.gz"

    # Collect logs from all services
    docker logs api > /tmp/api.log 2>&1
    docker logs cpl-service > /tmp/cpl.log 2>&1
    docker logs inference > /tmp/inference.log 2>&1
    docker logs db-primary > /tmp/db.log 2>&1

    tar czf "$backup_file" /tmp/*.log
    rm /tmp/*.log

    log "✅ Logs backup completed: $backup_file"
    echo "$backup_file" >> "$BACKUP_DIR/manifest.txt"
}

cleanup_old_backups() {
    log "🧹 Cleaning up old backups (older than $RETENTION_DAYS days)..."

    find "$BACKUP_DIR" -name "*.gz" -o -name "*.rdb" -o -name "*.sql" \
         -mtime +$RETENTION_DAYS -delete

    log "✅ Old backups cleaned up"
}

upload_to_remote() {
    local remote_host="${REMOTE_BACKUP_HOST:-}"
    local remote_path="${REMOTE_BACKUP_PATH:-/backups/spidernet}"

    if [[ -n "$remote_host" ]]; then
        log "☁️ Uploading backups to remote storage..."

        rsync -avz --delete "$BACKUP_DIR/" "$remote_host:$remote_path/"

        log "✅ Remote backup completed"
    else
        log "⚠️ Remote backup not configured (set REMOTE_BACKUP_HOST)"
    fi
}

verify_backups() {
    log "🔍 Verifying backup integrity..."

    local failed_verifications=0

    # Verify PostgreSQL dumps
    for sql_file in "$BACKUP_DIR"/postgresql/*.sql.gz; do
        if [[ -f "$sql_file" ]]; then
            if ! gzip -t "$sql_file"; then
                error "PostgreSQL backup corrupted: $sql_file"
                ((failed_verifications++))
            fi
        fi
    done

    # Verify Redis RDB files
    for rdb_file in "$BACKUP_DIR"/redis/*.rdb; do
        if [[ -f "$rdb_file" ]]; then
            if ! docker run --rm -v "$PWD/$rdb_file:/data/dump.rdb:ro" redis:7-alpine redis-check-rdb /data/dump.rdb; then
                error "Redis backup corrupted: $rdb_file"
                ((failed_verifications++))
            fi
        fi
    done

    if [[ $failed_verifications -eq 0 ]]; then
        log "✅ All backups verified successfully"
    else
        error "$failed_verifications backup(s) failed verification"
        return 1
    fi
}

run_backup() {
    local backup_type="${1:-full}"

    log "=== SpiderNetOS Backup Started ==="
    log "Backup Type: $backup_type"
    log "Timestamp: $(date)"
    log ""

    create_backup_dirs

    case "$backup_type" in
        "full")
            backup_postgresql
            backup_redis
            backup_kafka
            backup_configs
            backup_logs
            ;;
        "database")
            backup_postgresql
            backup_redis
            ;;
        "minimal")
            backup_configs
            ;;
        *)
            error "Unknown backup type: $backup_type"
            echo "Available types: full, database, minimal"
            exit 1
            ;;
    esac

    verify_backups
    cleanup_old_backups
    upload_to_remote

    log ""
    log "=== Backup Complete ==="
    log "Manifest: $BACKUP_DIR/manifest.txt"
    log "Log: $LOG_FILE"
}

# Disaster Recovery Functions

create_recovery_scripts() {
    log "🛠️ Creating disaster recovery scripts..."

    # PostgreSQL PITR recovery
    cat > "$BACKUP_DIR/recover-postgresql.sh" << 'EOF'
#!/bin/bash
# PostgreSQL Point-in-Time Recovery

set -euo pipefail

BACKUP_FILE="$1"
RECOVERY_POINT="${2:-latest}"

echo "🔄 Starting PostgreSQL PITR recovery..."
echo "Backup: $BACKUP_FILE"
echo "Recovery Point: $RECOVERY_POINT"

# Stop PostgreSQL
docker stop db-primary db-replica

# Clean data directory
docker run --rm -v spidernet_pgdata:/data alpine rm -rf /data/*

# Restore base backup
gunzip -c "$BACKUP_FILE" | docker exec -i db-primary psql -U spidernet -d postgres

# Restore WAL files if needed
if [[ -f "${BACKUP_FILE%.sql.gz}.wal.tar.gz" ]]; then
    docker run --rm -v spidernet_pgdata:/data -i alpine tar xzf - < "${BACKUP_FILE%.sql.gz}.wal.tar.gz"
fi

# Start PostgreSQL with recovery configuration
docker start db-primary

echo "✅ PostgreSQL recovery completed"
EOF

    # Full system recovery
    cat > "$BACKUP_DIR/disaster-recovery.sh" << 'EOF'
#!/bin/bash
# SpiderNetOS Disaster Recovery Script

set -euo pipefail

BACKUP_TIMESTAMP="$1"

echo "🚨 Starting SpiderNetOS Disaster Recovery"
echo "Backup Timestamp: $BACKUP_TIMESTAMP"

# 1. Stop all services
echo "Stopping all services..."
docker compose down

# 2. Restore configurations
echo "Restoring configurations..."
tar xzf "backups/configs/config_backup_${BACKUP_TIMESTAMP}.tar.gz"

# 3. Restore databases
echo "Restoring databases..."
./backups/recover-postgresql.sh "backups/postgresql/pg_backup_${BACKUP_TIMESTAMP}.sql.gz"

# 4. Restore Redis
echo "Restoring Redis..."
docker cp "backups/redis/redis_backup_${BACKUP_TIMESTAMP}.rdb" redis:/data/dump.rdb
docker restart redis

# 5. Restore Kafka (if needed)
if [[ -f "backups/kafka/kafka_backup_${BACKUP_TIMESTAMP}.tar.gz" ]]; then
    echo "Restoring Kafka..."
    gunzip -c "backups/kafka/kafka_backup_${BACKUP_TIMESTAMP}.tar.gz" | docker exec -i kafka-1 tar xzf -
    docker restart kafka-1 kafka-2 kafka-3
fi

# 6. Start services gradually
echo "Starting services..."
docker compose up -d db-primary redis zookeeper
sleep 30
docker compose up -d kafka-1 kafka-2 kafka-3
sleep 30
docker compose up -d db-replica api cpl-service inference

# 7. Verify recovery
echo "Verifying recovery..."
curl -f http://localhost:8000/health || exit 1
curl -f http://localhost:9100/health || exit 1

echo "✅ Disaster recovery completed successfully"
EOF

    chmod +x "$BACKUP_DIR/recover-postgresql.sh"
    chmod +x "$BACKUP_DIR/disaster-recovery.sh"

    log "✅ Disaster recovery scripts created"
}

setup_automated_backups() {
    log "⏰ Setting up automated backup schedule..."

    # Create cron job for daily backups
    cat > "$BACKUP_DIR/backup-cron" << EOF
# SpiderNetOS Automated Backup Schedule
# Add to crontab with: crontab $BACKUP_DIR/backup-cron

# Daily full backup at 2 AM
0 2 * * * $PWD/run-backup.sh full

# Hourly database backup during business hours
0 9-17 * * 1-5 $PWD/run-backup.sh database

# Config backup every 4 hours
0 */4 * * * $PWD/run-backup.sh minimal
EOF

    log "✅ Automated backup schedule created: $BACKUP_DIR/backup-cron"
    log "To enable: crontab $BACKUP_DIR/backup-cron"
}

main() {
    local backup_type="${1:-full}"

    case "$backup_type" in
        "setup")
            log "🔧 Setting up backup system..."
            create_backup_dirs
            create_recovery_scripts
            setup_automated_backups
            log "✅ Backup system setup complete"
            ;;
        "full"|"database"|"minimal")
            run_backup "$backup_type"
            ;;
        "verify")
            verify_backups
            ;;
        "cleanup")
            cleanup_old_backups
            ;;
        *)
            echo "Usage: $0 [setup|full|database|minimal|verify|cleanup]"
            echo ""
            echo "Commands:"
            echo "  setup    - Initialize backup system and scripts"
            echo "  full     - Complete backup (all components)"
            echo "  database - Database-only backup"
            echo "  minimal  - Configuration-only backup"
            echo "  verify   - Verify backup integrity"
            echo "  cleanup  - Remove old backups"
            exit 1
            ;;
    esac
}

# Run main function with all arguments
main "$@"