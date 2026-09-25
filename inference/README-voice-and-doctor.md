# Inference doctor and Atlas voice previews

Two PR 0 tools that live in `inference/` next to the plane they check, and ship with it to the
production host (`/opt/spidernet-inference`, uvicorn on `127.0.0.1:9001`).

| Tool | Python | Artisan wrapper | What it produces |
|---|---|---|---|
| Doctor | `inference/doctor.py` | `php artisan inference:doctor` | one PASS/WARN/FAIL line per check, exit 1 on any FAIL |
| Voice previews | `inference/voice_previews.py` + `voice_personas.yaml` + `templates/voice-options.html` | `php artisan voice:render-previews` | `<out>/<slug>/{greeting,verdict,apology}.mp3` (or `take-N.mp3` for Voice Design), `<out>/manifest.json`, `<out>/index.html` |

Both are plain modules with the plane's flat-import convention, so they run either as
`python -m inference.<module>` from the repo root or as `python -m <module>` from inside the
inference directory (which is how the prod host is laid out). Neither prints a secret value.

---

## 1. Doctor — `python -m inference.doctor`

```
python -m inference.doctor [--env-file path] [--inference-url http://127.0.0.1:9001] [--json]
```

| Check | Verdict logic |
|---|---|
| `env:DEEPSEEK_API_KEY` | FAIL if missing (only "set (N chars)" is ever printed) |
| `env:DEEPSEEK_BASE_URL`, `env:DEEPSEEK_ARK_MODEL_FLASH`, `env:DEEPSEEK_ARK_MODEL_PRO` | WARN if unset (the code default from `config.py` applies) |
| `env:OPENAI_API_KEY` | WARN if missing (no OpenAI fallback) |
| `env:OLLAMA_*` | PASS listing the names/values set; WARN if none (routing will try a local Ollama) |
| `modelark:host` | PASS for `ark.ap-southeast.bytepluses.com`; **FAIL for any `cn-beijing` / `volces.com` host**; WARN otherwise |
| `modelark:chat[flash]`, `modelark:chat[pro]` | live `POST {DEEPSEEK_BASE_URL}/chat/completions` with a ~5-token prompt for **both** model ids: latency, token usage, HTTP status, first 60 chars of the reply. WARN (skipped) without a key |
| `router:rank_models` | `policy_router.rank_models()` for a default request must put a modelark model first with OpenAI/Ollama behind it; FAIL names the model that outranks it and why |
| `inference:/health`, `inference:/v1/classify`, `inference:/generate[flash]`, `inference:/generate[pro]` | only with `--inference-url`; `/v1/classify` and `/generate` pin `deepseek-v4-*` so the round-trip goes through ModelArk; `/generate` WARNs if a fallback served it |
| `tts:providers` | names only — elevenlabs / fishaudio / intron / piper / azure (TTS), deepgram / whisper (STT) and `VOICE_TTS_PROVIDER` |

Exit code: `0` when no check FAILs, `1` otherwise. `--json` prints `{ok, summary, checks[]}`
for CI. `--env-file` fills in names the process environment does **not** already define
(process env always wins), then `config.py` is imported so the checks see exactly what the
plane would use.

### A finding the doctor will report today

`rank_models()` sorts candidates by `cost_per_1k_tokens`, and in `MODEL_COST_TABLE`
`gpt-5-nano` ($0.00005) is cheaper than `deepseek-v4-flash` ($0.0004) while every Ollama model is
$0. So whenever `OPENAI_API_KEY` is set or `OLLAMA_ENABLED=1`, the default request is **not**
routed to ModelArk first and `router:rank_models` FAILs with the actual order. That is the
"fix whatever it finds" item for `policy_router.py` (a provider-preference tier before the cost
sort); the doctor deliberately reports it rather than papering over it. Until then, a host that
wants ModelArk first needs `OLLAMA_ENABLED=0` and no OpenAI key — which the doctor then reports
as WARN (modelark first, but no fallback).

### Locally

```bash
# from the repo root (worktree)
python -m inference.doctor                                   # env checks + ModelArk probes if a key is set
python -m inference.doctor --env-file inference/.env --json  # read keys from a dotenv file, machine-readable
python -m inference.doctor --inference-url http://127.0.0.1:9000   # also round-trip a running plane

# same thing from inside the directory
cd inference && python -m doctor --inference-url http://127.0.0.1:9000
```

Through Laravel (from `backend/`):

```bash
php artisan inference:doctor                     # --url defaults to services.inference.url
php artisan inference:doctor --url=http://127.0.0.1:9001 --json
php artisan inference:doctor --env-file=/opt/spidernet-inference/.env
```

The wrapper finds the inference directory via `config('services.inference.path')`, then
`base_path('../inference')`, then `base_path('inference')`; it runs `python -m doctor` there
with `cwd` set, streams the output and returns the doctor's exit code. The interpreter is
`config('services.inference.python')`, else a `.venv/` or `venv/` inside the inference directory,
else `python3` (`python` on Windows).

> **Follow-up outside this PR's file list:** add to `backend/config/services.php`
> `'path' => env('INFERENCE_PATH'), 'python' => env('INFERENCE_PYTHON'),` under `inference`, and on
> the prod host set `INFERENCE_PATH=/opt/spidernet-inference` — Laravel lives at
> `/opt/spidernetos/backend`, so `base_path('../inference')` does not exist there.

### On the production host (keys are only there)

```bash
ssh root@5.223.49.137

# 1. See how the plane is actually started and where its env comes from
systemctl cat spidernet-inference 2>/dev/null | grep -E 'ExecStart|EnvironmentFile|WorkingDirectory'
ls /opt/spidernet-inference

# 2. Doctor against the live plane. Values from the env file are read, never printed.
cd /opt/spidernet-inference && python3 -m doctor --env-file /opt/spidernet-inference/.env --inference-url http://127.0.0.1:9001

# 2b. If ExecStart uses a venv interpreter, use the same one:
cd /opt/spidernet-inference && .venv/bin/python -m doctor --env-file .env --inference-url http://127.0.0.1:9001

# 3. Machine-readable copy for the PR
cd /opt/spidernet-inference && python3 -m doctor --env-file .env --inference-url http://127.0.0.1:9001 --json > /root/doctor-$(date +%F).json; echo exit=$?
```

`doctor.py` needs only `httpx` (already in `inference/requirements.txt`). The new files
(`doctor.py`, `voice_previews.py`, `voice_personas.yaml`, `templates/`) reach the host the same
way the plane does — copy the `inference/` directory over `/opt/spidernet-inference` in the
deploy step; nothing else on the host changes.

---

## 2. Voice previews — `python -m inference.voice_previews`

The plan's slice 0: one page, "Atlas voice options", with every candidate voice saying the same
three lines so Jason compares voices, not scripts.

**Scope change (Jason, 2026-09-16): "I do not like any of the Azure voice options."** Every
Azure persona was removed from the catalogue. The providers are now ElevenLabs (primary, incl.
Voice Design), Fish Audio (the provider from the reel), Intron Sahara (African accents, the only
provider with a Shona voice) and Piper as the dev baseline. The Azure renderer stays in the code
behind `--include-azure` for any future catalogue that lists Azure voices.

```
python -m inference.voice_previews --out <dir> [--only elevenlabs|fishaudio|intron|piper]
                                   [--personas inference/voice_personas.yaml]
                                   [--env-file inference/.env] [--no-discover] [--include-azure]
```

The three lines (Atlas voice — wise, crisp, warm):

- greeting: *Good morning. I have read the week's numbers; three things need you, and the rest can wait.*
- verdict: *The pipeline is healthy, but two deals have gone quiet. I recommend we follow up today.*
- apology: *I was wrong about the invoice date. I have corrected it, and here is what changed.*

### Providers, env vars, endpoints (verified against vendor docs 2026-09-16)

| Provider | Env | How it renders |
|---|---|---|
| **ElevenLabs** | `ELEVENLABS_API_KEY` | Library voices: `POST https://api.elevenlabs.io/v1/text-to-speech/{voice_id}` with `eleven_multilingual_v2`, `output_format=mp3_44100_128`. Discovery placeholders (`provider_voice_id: null` + `discover: {accent}`) are filled from `GET /v1/shared-voices?accent=nigerian|south african|kenyan|ghanaian|zimbabwean&language=en` (free-text `search` fallback) and written to the manifest with the library `preview_url`. **Voice Design** candidates (`kind: design`): `POST /v1/text-to-voice/design` with `voice_description`, `model_id: eleven_multilingual_ttv_v2`, a fixed `seed` and `text` — the endpoint requires 100–1000 characters, so the text is the three lines in one clip (~270 chars). The response's `previews[]` each carry `audio_base_64` and a distinct `generated_voice_id`; every preview is saved as `<slug>/take-N.mp3` and described in `<slug>/design.json`. **Nothing is created in the ElevenLabs account**: promoting a chosen take is `POST /v1/text-to-voice` with `{voice_name, voice_description, generated_voice_id}` → `voice_id`, which PR 5 runs once for the take Jason picks (`design.json` carries `created: false` and the exact call). |
| **Fish Audio** | `FISH_AUDIO_API_KEY` | `POST https://api.fish.audio/v1/tts`, `Authorization: Bearer`, header `model: s2.1-pro` (S2.1 Pro; `s1`, `s2-pro`, `s2.1-pro-free`, `drama-3-preview` also exist), JSON `{text, reference_id, format: mp3, mp3_bitrate: 128, latency: normal, normalize: true}` → MP3 bytes. `reference_id` is the library model `_id`. Library search: `GET https://api.fish.audio/model?title=…&page_size=10&sort_by=score` (answers without a key; the docs list Bearer auth) → `items[]{_id, title, description, languages, tags, author, like_count, task_count, samples}`. The six catalogued voices were found this way: Zimbabwean male + female (Chiedza), Nigerian male (calm) + female (Storyteller), South African female narrator, Kenyan male; one Ghanaian placeholder is discovered at render time. |
| **Intron Sahara** | `INTRON_API_KEY` | `POST https://infer.voice.intron.io/tts/v1/enqueue`, `Authorization: Bearer`, JSON `{text, voice_language, voice_accent, voice_gender, output_audio_format: wav}` → `data.text_id`; then `GET https://infer.voice.intron.io/tts/v1/status/{text_id}` until `processing_status` is `TTS_TEXT_AUDIO_GENERATED` (or `…_PROCESSING_FAILED`), honouring `Retry-After` on 429; the `audio_path` URL is downloaded (`.wav`). Limits: 4,096 chars, 60 enqueues/min, 100 status calls/min. There is no voice id — the voice is (language, accent, gender). Catalogued: English with accents zulu, xhosa, swahili, yoruba, igbo, hausa, sepedi, setswana (male + female = 16), plus **Shona (`sn`, accent `shona`)** male + female tagged `experimental` — Intron lists Shona as a TTS language, the first Shona voice found at any provider; the English lines pushed through it are a Zimbabwean-accent experiment. (`POST /tts/v1/generate` is a synchronous alternative returning `audio_path`; the enqueue/poll path is what the renderer uses.) |
| **Piper** | `PIPER_URL` | `POST {PIPER_URL}/synthesize` with `{text, voice, speed}` — the same request `speech.py` makes. Returns WAV, so `.wav`. |
| Azure (off) | `AZURE_SPEECH_KEY` + `AZURE_SPEECH_REGION` | Only with `--include-azure`. No personas in the catalogue; the renderer (SSML + `voices/list` id check) is kept. |

A provider whose key is absent is skipped with one log line (`fishaudio: skipped -- needs
FISH_AUDIO_API_KEY`) and its cards render greyed out with that text where the players would be.
Runs are idempotent: an existing non-empty sample file (or `design.json`) is never re-rendered,
so you can add a key later and re-run to fill only the missing cards.

Outputs in `<out>/`:

- `<slug>/greeting.mp3`, `verdict.mp3`, `apology.mp3` (Intron and Piper: `.wav`)
- `<slug>/take-1.mp3 … take-N.mp3` + `design.json` for the four Voice Design candidates
- `manifest.json` — every persona's fields plus `samples`, `takes`, `errors`, `status`
  (`rendered|partial|failed|skipped`), discovered ElevenLabs / Fish ids, per-provider
  availability, the discovery candidates and `disabled_providers`
- `index.html` — self-contained (no external assets), dark, grouped by provider (ElevenLabs →
  Fish Audio → Intron → Piper; Azure only with `--include-azure`) then accent; each card has
  tags, cost (`unverified` until pricing is re-checked), the players, a **Choose for Atlas** radio
  (one per take on Voice Design cards); the dock at the bottom has one select per seat
  (`character:hannah|forge|sentinel|prism|nexus`, `board:offer_architect|producer|leverage_philosopher|compounder|greenlight`,
  preselected from `recommended_for`) and **Copy my choices**, which produces

  ```json
  {"atlas": "elevenlabs-design-zimbabwean-man@<generated_voice_id>",
   "seats": {"character:hannah": "elevenlabs-olufunmilola", "character:sentinel": "intron-en-zulu-male",
             "board:producer": "fishaudio-south-african-narrator", "board:compounder": "elevenlabs-design-kenyan-man@<generated_voice_id>"}}
  ```

  A Voice Design pick is `slug@generated_voice_id`. Paste the blob back into the chat or the PR;
  in PR 5 it is what `PUT /api/admin/tenant/voice-default` writes to
  `tenants.settings.voice.default_persona` (creating the ElevenLabs voice for a design pick first).

### Recommended defaults in the catalogue

Atlas default candidate: **`elevenlabs-design-zimbabwean-man`** ("A wise, calm Zimbabwean man in
his 50s, crisp diction, warm and unhurried, Southern African English…"). Other Atlas candidates:
`fishaudio-zimbabwe-male`, `fishaudio-kenyan-male`, `fishaudio-nigerian-male-calm`,
`elevenlabs-nz-nigerian-man`, `intron-sn-shona-male` (experimental). Board seats are spread across
providers — Offer Architect → ElevenLabs design (Nigerian man), Producer → Fish (South African
narrator), Leverage Philosopher → Fish (Zimbabwean man), Compounder → ElevenLabs design (Kenyan
man), Greenlight → ElevenLabs design (South African woman) — with Intron alternates; characters:
Hannah → Olufunmilola, Forge → NZ The African Man, Sentinel → Intron Zulu male, Prism → Fish
Kenyan male, Nexus → Fish Chiedza (Zimbabwe). The first persona in file order that lists a seat
is the one the page preselects.

### Locally — the exact one-liner

Put the keys in `inference/.env` (already gitignored by the root `.gitignore`'s `*.env` rule):

```
ELEVENLABS_API_KEY=…
FISH_AUDIO_API_KEY=…
INTRON_API_KEY=…
# PIPER_URL=http://localhost:5000        # optional local baseline
```

then, from the repo root:

```bash
python -m inference.voice_previews --out build/voice-previews --env-file inference/.env
```

and open `build/voice-previews/index.html` in a browser (`file://` works; audio paths are relative).
Variants:

```bash
python -m inference.voice_previews --out build/voice-previews                       # no keys: layout check, every card greyed
python -m inference.voice_previews --out build/voice-previews --env-file inference/.env --only fishaudio
python -m inference.voice_previews --out build/voice-previews --env-file inference/.env --no-discover
python -m inference.voice_previews --out build/voice-previews --env-file inference/.env --include-azure  # only if Azure personas are added back
cd inference && python -m voice_previews --out ../build/voice-previews --env-file .env             # same, from inside the directory
```

Through Laravel (from `backend/`):

```bash
php artisan voice:render-previews                          # -> storage/app/public/voice-previews/index.html
php artisan voice:render-previews --only=elevenlabs
php artisan voice:render-previews --out=/var/www/spidernetos-web/voice-previews
php artisan storage:link                                    # once, so /storage/voice-previews/index.html is served
```

The wrapper resolves the inference directory and interpreter exactly like `inference:doctor`,
runs `python -m voice_previews --out <abs> [--only …]`, streams the log and prints the page path
(and the `/storage/...` URL when the output is under `storage/app/public`). Keys come from the
process environment of the artisan call; where they live in a dotenv file, run the Python module
directly with `--env-file`.

### On the production host

```bash
ssh root@5.223.49.137
cd /opt/spidernet-inference

# PyYAML is a dev dependency in the repo; the plane's venv may not have it
python3 -c 'import yaml' 2>/dev/null || python3 -m pip install pyyaml

# ELEVENLABS_API_KEY is already in the plane's env file; add FISH_AUDIO_API_KEY and INTRON_API_KEY there too
python3 -m voice_previews --env-file /opt/spidernet-inference/.env --out /var/www/spidernetos-web/voice-previews
# → https://spidernetos.com/voice-previews/index.html  (static, served by the existing nginx vhost)

# re-run after adding a key: only the missing cards render (existing samples and design takes are reused)
python3 -m voice_previews --env-file /opt/spidernet-inference/.env --out /var/www/spidernetos-web/voice-previews
```

Cost of one full render at research-time list prices is small (37 personas; ~270 characters per
library persona, one design call per candidate, Intron 54 enqueues) — well inside every
provider's entry tier — but `cost_per_1k_chars` stays `null` in the catalogue until the three
vendors' pricing is re-verified.

### The catalogue — `inference/voice_personas.yaml`

37 personas: ElevenLabs 4 Voice Design candidates (Zimbabwean man 50s, Kenyan man 40s, Nigerian
man 40s, South African woman 40s), 2 library voices (`gsyHQ9kWCDIipR26RqQ1` NZ The African Man –
Nigerian Voice Pro, `9Dbo4hEvXQ5l7MXGZFQA` Olufunmilola), 5 discovery placeholders (Nigerian,
South African, Kenyan, Ghanaian, Zimbabwean English); Fish Audio 6 library voices + 1 Ghanaian
placeholder; Intron 16 English-with-accent voices + 2 Shona; Piper `en_US-lessac-medium`. Every
entry carries `recommended_for` (`atlas`, `board:<seat>`, `character:<slug>`), `consent: {type:
stock}`, and `cost_per_1k_chars: null` (Piper `0.0`). A top-level `providers:` block records each
provider's env names and `disabled_by_default` (Azure: `true`).

---

## Tests

```bash
python -m pytest inference/tests -q
```

`inference/tests/test_doctor.py` and `inference/tests/test_voice_previews.py` mock httpx with
`MockTransport` and use fake renderers — no network, no keys, no server. They cover the ElevenLabs
design contract (request body, `previews[]` decoding, `design.json` persistence and reuse), the
Fish Audio `model` header / `reference_id` body and library search, the Intron enqueue → poll →
download loop (429 `Retry-After`, failure status, timeout, no key sent to a non-Intron audio
host), the Azure path behind `--include-azure`, and the page (grouping order, greyed cards,
per-take Atlas radios, seat preselection, HTML escaping, no external assets). They use the
existing `inference/tests/conftest.py` sys.path shim, like `test_extract_document.py`.
