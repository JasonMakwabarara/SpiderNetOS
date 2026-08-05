# Voice AI — Operations Runbook (Phase A)

**Team:** Platform / Voice AI
**Updated:** 2026-04-18

---

## Kill-switches

### Global voice kill-switch (takes effect within 5 s)
```bash
php artisan feature:set voice.inbound off
```
Every subsequent Twilio webhook returns a static "service temporarily unavailable" TwiML.
Callers hear the message and are disconnected gracefully.

### Per-tenant kill-switch
```bash
php artisan feature:set voice.inbound off <tenant-uuid>
```

### Restore
```bash
php artisan feature:set voice.inbound on
php artisan feature:set voice.inbound on <tenant-uuid>
```

### Disable voice agent mode (revert to direct inference — Phase A path)
```bash
php artisan feature:set voice.agent_mode off
```

### Disable streaming mode (revert to Gather mode)
```bash
php artisan feature:set voice.streaming off
```

---

## Smoke test
```bash
# Quick config/DB check (no call placed)
php artisan voice:smoke

# Per-tenant gates
php artisan voice:smoke --tenant=<uuid>

# Real test call (requires TWILIO_TEST_* env vars)
php artisan voice:smoke --tenant=<uuid> --call
```

---

## Key Redis keys

| Key pattern | Purpose |
|---|---|
| `feature:voice.inbound` | Global voice flag |
| `feature:voice.inbound:tenant:<id>` | Per-tenant flag |
| `metrics:voice_calls_total:tenant:<id>` | Counter incremented per inbound |
| `metrics:voice:signature_rejected_total` | Unsigned request counter (alert on spike) |
| `metrics:voice_turn_latency_ms:tenant:<id>` | List of last 1000 turn latencies (ms) |

---

## Alerting thresholds

| Alert | Threshold | Action |
|---|---|---|
| `voice_signature_rejected_total` spikes | > 20 / 5 min | Investigate replay attack; check `TWILIO_AUTH_TOKEN` rotation |
| `voice.inbound` p95 latency | > 3.5 s | Check inference service; scale Ollama replicas |
| Post-call summary queue depth | > 500 | Check Horizon workers; check Nexus agent |
| ElevenLabs 5xx | > 5 / min | Auto-fallback to Piper already active; alert for SLA |

---

## Twilio console references

- **Manage phone numbers:** https://console.twilio.com/us1/develop/phone-numbers/manage/incoming
- **Webhook URL:** `https://<your-domain>/api/voice/inbound`
- **Status callback URL:** `https://<your-domain>/api/voice/status`
- **Recording callback URL:** `https://<your-domain>/api/voice/recording`
- **Error log:** https://console.twilio.com/us1/monitor/logs/notifications

---

## Rollback procedures per phase

### Phase A rollback
1. `php artisan feature:set voice.inbound off` — kills all inbound silently
2. Revert migration: `php artisan migrate:rollback --step=1` (removes hardening columns)
3. Revert `bootstrap/app.php` alias additions if needed (git revert)

### Phase B rollback
1. `php artisan feature:set voice.agent_mode off` — reverts to Phase A direct inference path
2. No migration rollback needed (agent policy cols are nullable with safe defaults)

### Phase C rollback
1. `php artisan feature:set voice.streaming off` — reverts to Gather mode per number
2. `php artisan feature:set voice.post_call_summary off` — disables summary DAG
3. Revert streaming migration: `php artisan migrate:rollback --step=1`

---

## Deployment checklist

- [ ] `TWILIO_SID` and `TWILIO_AUTH_TOKEN` set in production `.env`
- [ ] Webhook URLs registered in Twilio console
- [ ] `TWILIO_SKIP_SIGNATURE_VERIFY` is NOT set in production
- [ ] `feature:voice.inbound` flag set to `on` only after QA sign-off
- [ ] Horizon queue workers running (`php artisan horizon`)
- [ ] Prometheus metrics endpoint scraping `metrics:voice_*` keys

---

## Contact

- On-call Slack channel: `#platform-voice-oncall`
- Escalation: Platform team lead
