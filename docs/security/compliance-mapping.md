# Compliance Control Mapping (pre-audit)

Maps implemented controls to SOC 2 Trust Services Criteria (CC series) and GDPR
articles. Status is **honest**: "Built" = code exists and is tested; "Partial"
= mechanism exists but coverage/policy incomplete; "Planned" = not yet built.

_Guidance only — not a substitute for an auditor's assessment._

## Security / Common Criteria (SOC 2)

| Control | SOC 2 | Status | Evidence |
|---|---|---|---|
| Logical access — RBAC + capabilities | CC6.1 | Built | `RequireRole`, `RequireCapability`, `User::ROLE_CAPABILITIES` |
| Step-up re-authentication for sensitive ops | CC6.1 | Built | `RequireStepUp` (HTTP 428), `AuthController::stepUp` |
| Multi-factor authentication (TOTP) | CC6.1 | Built | `MfaService` (RFC 6238, tested vs vectors); required once enrolled. Mandatory-enrolment policy for admins = Planned |
| Credential protection | CC6.1 | Built | bcrypt (`password` hashed cast), `throttle:auth` 5/min IP-keyed |
| Encryption in transit | CC6.7 | Partial | nginx TLS (deploy); TLS pinning deferred (threat-model S5) |
| Encryption at rest (secrets/keys) | CC6.7 | Partial | `TenantKeyManager` per-tenant keys + rotation; DSAR export bundles encrypted (`Crypt`); full DB TDE = Planned |
| Audit logging (admin actions) | CC7.2 | Built | `admin_audit_log` (append-only), `AdminAuditLog::record`; CSV export `GET /compliance/audit-log/export` |
| Tamper-evident event log | CC7.2 | Built | hash-chained `event_log`, `spidernet:verify-event-chain` |
| Security headers / hardening | CC6.6 | Built | `SecurityHeadersMiddleware` (HSTS/CSP/COOP…) |
| Rate limiting / DoS resistance | CC6.6 | Built | `SecurityServiceProvider` limiters (auth/api/admin/platform/payment_webhook/lead_capture) |
| Change management / CI | CC8.1 | Partial | `.github/workflows` (Pint, PHPUnit CiFast, cockpit build, Ruff) |

## Privacy / GDPR

| Right / obligation | GDPR | Status | Evidence |
|---|---|---|---|
| Right of access (data export) | Art. 15 | Built | `DsarService::export` → encrypted bundle; `POST /compliance/dsar` + `/download` |
| Right to erasure | Art. 17 | Built | `DsarService::erase` — PII tombstone scrub preserving referential + audit rows |
| Consent records (messaging) | Art. 7 | Built | `consent_records`, `ConsentRecord::log/isAllowed`; WhatsApp STOP writes an opt-out record |
| Data retention enforcement | Art. 5(1)(e) | Built | `spidernet:compliance:enforce-retention` + `config/retention.php` (scheduled) |
| Records of processing | Art. 30 | Partial | event log + audit log provide evidence; formal RoPA document = Planned |
| Breach notification process | Art. 33 | Planned | runbook to be authored |
| DPA / sub-processor list | Art. 28 | Planned | legal deliverable |

## Certifications

- **SOC 2 Type I/II** — not yet pursued; this mapping is the pre-audit gap list.
- **ISO 27001** — controls partially align; not certified.

## Sign-off follow-ups (owner/legal)

- Retention default TTLs (`config/retention.php`) and erasure policy wording.
- Whether TOTP enrolment is mandatory for admin/super_admin roles.
- RoPA, breach-notification runbook, and DPA templates.
