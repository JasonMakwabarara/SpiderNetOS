# SpiderNet OS — k3s Deploy Scaffold

Tier 1 deliverable: **scaffold only**. These manifests are not applied during Tier 1 — they target the Tier 2 Hetzner + k3s rollout (weeks 4–6 of the critical path).

## Layout

```
deploy/k3s/
├── README.md                          (this file)
├── external-secrets/
│   ├── operator-values.yaml           (Helm values for ESO install)
│   ├── vault-secret-store.yaml        (ClusterSecretStore pointing to Vault)
│   └── external-secrets.yaml          (one ExternalSecret per bundle)
└── traefik/
    ├── middleware-rate-limit.yaml     (per-surface rate limits at edge)
    ├── middleware-security-headers.yaml (strip Server / X-Powered-By + enforce HSTS)
    └── middleware-redirect-https.yaml (force HTTPS)
```

## Tier 2 bring-up order

1. Install External Secrets Operator:
   ```bash
   helm repo add external-secrets https://charts.external-secrets.io
   helm upgrade --install external-secrets external-secrets/external-secrets \
     -n external-secrets-system --create-namespace \
     -f deploy/k3s/external-secrets/operator-values.yaml
   ```
2. Apply the Vault SecretStore (requires Vault running + a Kubernetes auth method mounted):
   ```bash
   kubectl apply -f deploy/k3s/external-secrets/vault-secret-store.yaml
   ```
3. Apply ExternalSecret bundles — they will materialise native `Secret` objects that pod envFroms reference.
4. Apply Traefik middlewares and attach them to ingress routes.

## Vault paths (production layout)

```
kv/spidernet/app/        → APP_KEY, APP_PREVIOUS_KEYS
kv/spidernet/db/         → DB_USERNAME, DB_PASSWORD
kv/spidernet/redis/      → REDIS_PASSWORD
kv/spidernet/twilio/     → TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_AUTH_TOKEN_PREVIOUS
kv/spidernet/llm/        → OPENAI_API_KEY, ANTHROPIC_API_KEY
kv/spidernet/pusher/     → PUSHER_APP_ID, PUSHER_APP_KEY, PUSHER_APP_SECRET
kv/spidernet/tenants/{id}/event_signing → per-tenant signing keys (migrated from tenant_secrets)
```

Rotation playbook for each path is in `docs/runbooks/key-rotation.md`.
