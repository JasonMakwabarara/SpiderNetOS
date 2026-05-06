# SpiderNetOS Production Deployment Checklist

## Phase 1: Prerequisites
- [ ] Docker Swarm initialized (`docker swarm init`)
- [ ] All secrets created (`./scripts/secrets/init-secrets.sh`)
- [ ] Environment variables exported (source `.env`)
- [ ] TLS certificates generated (`./scripts/pki/setup-ca.sh && ./scripts/pki/generate-service-certs.sh`)

## Phase 2: Validation
- [ ] Run `./scripts/verify-env.sh` - all checks pass
- [ ] Run `./scripts/pki/verify-tls.sh` - certificates valid
- [ ] Run `./scripts/security/security-audit.sh` - no issues
- [ ] Run `pytest tests/integration -v` - all tests pass
- [ ] Run `pytest tests/behavioral -v` - CPL convergence verified

## Phase 3: Staging
- [ ] Deploy to staging: `docker stack deploy -c docker-compose.staging.yml spidernet-staging`
- [ ] Run chaos tests: `./scripts/chaos/chaos-test.sh 10`
- [ ] Run load tests: `locust -f tests/load/locustfile.py --host=http://staging`
- [ ] Verify all services recover from failures

## Phase 4: Production
- [ ] Final production validation: `./scripts/validate-production.sh`
- [ ] Backup existing data if migrating
- [ ] Deploy: `docker stack deploy -c docker-compose.yml spidernet`
- [ ] Monitor health endpoints for 5 minutes
- [ ] Verify SSL certificates active
- [ ] Check cost ceilings are enforced

## Training Data Quality Gate
- [ ] Run training quality gate: `make training-gate TRAINING_INPUT=/path/to/export.md`
- [ ] Verify all gates pass (min SFT rows, min preference rows, quality ratios)
- [ ] Confirm `training_data/*/quality_gate.json` shows `"passed": true`
- [ ] Verify gated data copied to `intelligence/atlas/training/current/`
- [ ] If gate fails: review `quality_report.json`, fix transcripts, re-run

## Post-Deployment
- [ ] Run smoke tests against production
- [ ] Verify logging aggregation
- [ ] Confirm backup jobs scheduled
- [ ] Document any environment-specific settings
