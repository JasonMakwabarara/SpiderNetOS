# Partner outreach: rollout runbook

Recruiting affiliates for a tenant (first: Hannah AI) from an Affonso Finder
shortlist. Email is automated; TikTok and Facebook DMs are drafted here and sent
by a human, because neither platform has a cold-DM API.

Everything ships off. One command tells you where you are:

```bash
php artisan outreach:doctor hannah-ai --probe
```

It lists every precondition, the one fix for each, and the next command to run.
`outreach:enable` refuses to advance a stage while a blocking check fails.

## 1. What only an operator can do

| # | Action | Why it cannot be scripted |
|---|--------|---------------------------|
| 1 | Create the `partners@` mailbox (real user, IMAP on, app password) and paste it into **Cockpit → Connectors → Zoho Mail** | Credentials |
| 2 | Create an Affonso API key (affiliates read/write, finder read) + the creators group, and paste key, program id, group id into **Cockpit → Connectors → Affonso** | Credentials, shown once |
| 3 | Fill **Cockpit → Partners → Settings**: join URL (the group invite link) and postal address | Business facts; the address is a CAN-SPAM requirement |
| 4 | Register the webhook in **Affonso → Settings → Webhooks** and paste its signing secret into the connector | Affonso account access |

The webhook URL is `https://api.spidernetos.com/api/webhooks/affonso/<tenant-uuid>`
(`outreach:doctor` prints it with the real UUID). Verify it before registering:

```bash
php artisan outreach:spike webhook hannah-ai
```

An unsigned POST must come back 401 and a signed one 200.

## 2. Import and enrich

```bash
php artisan outreach:import hannah-ai ~/Downloads/shortlist-all-2026-09-10.csv --dry-run
php artisan outreach:import hannah-ai ~/Downloads/shortlist-all-2026-09-10.csv
```

Re-importing is safe: rows dedupe on the normalised profile URL and an existing
prospect's email, status and timestamps are never touched.

**The shortlist export carries no email column.** Both 2026-09 exports imported
as 98 prospects (77 TikTok, 21 Facebook) with **zero** email addresses, so the
email sequence has nothing to send and all 98 queue as manual DMs. To get the
automated channel working, get emails in by either:

- **Finder over MCP** (its items do carry `emails`) — verify, then enable:
  ```bash
  php artisan outreach:spike finder hannah-ai
  php artisan outreach:enable finder --tenant=hannah-ai
  php artisan outreach:finder-sync hannah-ai --dry-run
  ```
- **By hand** — Cockpit → Partners, paste the address from the creator's bio.
  Each one is validated (syntax, role/disposable, MX) and logged as
  `legitimate_interest_b2b` consent before it becomes sendable.

Prospects with no email get a DM draft instead: Cockpit → Partners → DM queue,
copy, send it in the app, then paste their reply back into the thread.

## 3. The ladder

Each step is one command, and each refuses to run early.

```bash
php artisan outreach:enable digest --tenant=hannah-ai       # do this first: 08:30 summary + bounce auto-pause
php artisan outreach:enable send --tenant=hannah-ai         # invites start; warm-up clock starts today
php artisan outreach:enable bot --tenant=hannah-ai          # bot drafts replies for your approval
php artisan outreach:enable affiliate --tenant=hannah-ai    # bot may create the Affonso affiliate on request
php artisan outreach:enable auto --tenant=hannah-ai         # bot sends without approval (gated on 20 clean drafts)
```

Warm-up is automatic from the day `send` is enabled: 5 emails/day for days 1–3,
15 for days 4–7, 30 from day 8, with quiet hours, a jittered gap between sends
and an hourly burst cap. Never raise it past the provider's burst ceiling
(Zoho starts blocking around 50).

Week 1: clear approvals daily, watch the digest. The bot is approve-first, so
nothing reaches a creator unless you pass it. Week 2: if the first 20 drafts
needed no edits, `enable auto` lets it answer on its own; handoffs still come to
you. The command counts the record itself and refuses if any draft was edited.

## 4. Kill switches

```bash
php artisan outreach:enable send --tenant=hannah-ai --off    # stop sending now
php artisan outreach:enable bot --tenant=hannah-ai --off     # stop drafting
php artisan outreach:enable auto --tenant=hannah-ai --off    # back to approve-first
```

Sending also pauses itself: the daily digest switches it off and raises an
escalation when the trailing bounce rate crosses 5% over at least 20 sends.
Removing the Affonso webhook stops signup events. Setting a tenant's
`automation_level` to `manual` stops the bot regardless of flags.

## 5. What the spikes settled

| Spike | Status | Finding |
|-------|--------|---------|
| Public ingress | **Closed** | `https://api.spidernetos.com/api/health` answers `{"status":"healthy"}` and unrouted `/api/*` returns a Laravel JSON 404, so the prefix reaches Laravel intact |
| Deploy shape | **Closed** | `spidernetos-horizon.service` running with 8 workers; scheduler in `/etc/cron.d/spidernetos-scheduler` every minute as `www-data` |
| Model JSON adherence | **Closed** | 20 synthetic threads on the live plane: 20/20 valid JSON, 19/20 accepted by the post-filter, 18/20 the expected action, ~$0.0017 and ~13 s per reply. Keep drafts on the flash model |
| Affonso create-affiliate email | **Open** (needs the API key) | `outreach:spike affonso hannah-ai --email=you@…`, then set `program.api_signup_emails`. While false the bot never promises an inbox, it sends the join link |
| Finder over MCP | **Open** (needs the API key) | `outreach:spike finder hannah-ai`. CSV import is the fallback and the sync stays flagged off |
| Zoho plus-addressing | **Open** (needs the mailbox) | `outreach:spike mail hannah-ai`. Not blocking: matching falls back to Message-ID then sender address |

The two misses in the JSON run were both safe and both informative. A
negotiation case echoed "50%" while refusing it, and the post-filter caught the
figure and handed off. A creator saying "ok I'm in" was read as a signup, so a
bot-claimed signup now stops the sequence but stays flagged `verify_signup`
until Affonso confirms an affiliate exists.

## 6. Routine checks

```bash
php artisan outreach:doctor hannah-ai --probe          # go/no-go + next action
php artisan outreach:send-due hannah-ai --dry-run      # what the next tick would do
php artisan outreach:digest hannah-ai --dry-run --force
php artisan outreach:poll-inbox hannah-ai --dry-run --force
php artisan outreach:settings hannah-ai                # dump effective settings
```
