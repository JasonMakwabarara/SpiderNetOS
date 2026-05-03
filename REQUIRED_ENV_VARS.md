# SpiderNetOS Production Deployment - Required Environment Variables

**⚠️ CRITICAL:** All values must be set. There are NO fallback defaults.
Fail-fast behavior enforced.

---

## Required Secrets (Docker Swarm)

Run `scripts/secrets/init-secrets.sh` to generate automatically, or create manually:

```bash
# Example: Create secrets manually
echo "your_secure_password" | docker secret create db_password -
echo "your_repl_password" | docker secret create db_repl_password -
echo "your_redis_password" | docker secret create redis_password -
echo "32_char_encryption_key_hex" | docker secret create db_encryption_key -
echo "pusher_secret_key" | docker secret create pusher_app_secret -
```

---

## Required Environment Variables

### Database
```bash
export DB_PASSWORD=""              # PostgreSQL password (also in Docker secret)
export DB_REPL_PASSWORD=""         # Replication password (also in Docker secret)
export DB_ENCRYPTION_KEY=""        # 32-character hex encryption key
```

### Cache
```bash
export REDIS_PASSWORD=""           # Redis password (also in Docker secret)
```

### WebSocket/Real-time
```bash
export PUSHER_APP_KEY=""           # Pusher app key
export PUSHER_APP_SECRET=""        # Pusher app secret (also in Docker secret)
```

### Laravel
```bash
export APP_KEY=""                  # Laravel app key (generate with: php artisan key:generate)
```

### External APIs (Optional but recommended)
```bash
export OPENAI_API_KEY=""           # OpenAI API key (optional)
export OLLAMA_URL=""               # Ollama endpoint (optional, default: http://host.docker.internal:11434)
```

### Cost Control
```bash
export COST_CEILING_DEFAULT="50.00"  # Default cost ceiling in USD
```

---

## Pre-Deployment Checklist

- [ ] Docker Swarm initialized (`docker swarm init`)
- [ ] All secrets created (`docker secret ls` shows 5+ secrets)
- [ ] All env vars set (run `env | grep -E 'DB_|REDIS_|PUSHER_|APP_KEY'`)
- [ ] No fallback defaults triggered (docker-compose will fail fast if missing)
- [ ] TLS certificates generated (`./scripts/pki/setup-ca.sh && ./scripts/pki/generate-service-certs.sh`)

---

## Verification

```bash
# Check secrets exist
docker secret ls

# Check environment
./scripts/verify-env.sh

# Verify TLS
./scripts/pki/verify-tls.sh
```
