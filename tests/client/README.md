# SpiderNet test client

External integration / smoke test harness that speaks **only** the public HTTP
contract (`/api/auth/login` + `/api/atlas/chat`). No backend imports, no
Redis, no direct DB access.

## Why it lives at the workspace root

This suite runs against a **live stack** (`docker compose up`), not inside
the Laravel PHPUnit or Python unittest processes. Placing it here keeps that
boundary obvious:

| Suite | Location | Runs against |
|---|---|---|
| Unit / feature | `backend/tests/`, `intelligence/tests/` | In-process mocks |
| **Contract / smoke** | **`tests/client/`** | **Live HTTP stack** |

## Run

```bash
# 1. Bring up the stack
docker compose up -d

# 2. Install test deps (once)
python -m pip install -r tests/client/requirements.txt

# 3. Run
pytest tests/client/ -v
```

If the API is unreachable, the suite auto-skips instead of failing.

## Configuration

All config is via environment variables:

| Variable | Default | Purpose |
|---|---|---|
| `SPIDERNET_URL` | `http://localhost:8000` | Laravel API base URL |
| `SPIDERNET_EMAIL` | `admin@spidernet.local` | Login email |
| `SPIDERNET_PASSWORD` | `password` | Login password |
| `SPIDERNET_TIMEOUT` | `30` | Per-request seconds |
| `SPIDERNET_VERIFY_TLS` | `1` | Set `0` to skip cert verify in dev |

## Contract invariant

Every `/api/atlas/chat` response MUST satisfy:

- `contract_version == "1"`
- `message.contract.{future_state, value, emotional_shift, action_summary}`
  are all non-empty strings

`SpiderNetClient._assert_contract` enforces this on every call.

## Writing a new test

```python
def test_hannah_onboarding_reply(authed_client):
    reply = authed_client.chat(
        "Hannah, I'm new here — where do I start?",
        as_dto=True,
    )
    assert reply.metadata.get("agent_used") in {"hannah", "atlas"}
    assert reply.action_summary          # contract guard already ran
```

Do **not**:

- Import anything from `backend/` or `intelligence/`.
- Assert on specific LLM wording — the contract is structural.
- Tear down DB state; these tests are read-mostly smoke tests.
