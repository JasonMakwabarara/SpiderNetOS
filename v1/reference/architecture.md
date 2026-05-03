# Architecture Deep Dive — Telegram AI Command Bot

The internal patterns, cost model, and extension points. Read this after you have the bot running and want to understand or extend the internals.

---

## 1. Prime --> Task Pattern (Persistent Sessions)

The core execution model has two steps:

```
Step 1: PRIME
  - Spawns a Claude Code agent
  - Agent reads workspace context files (via prime command)
  - Returns a session_id

Step 2: TASK
  - Resumes the primed session using session_id
  - Sends the user's actual request
  - Agent works with full context already loaded
  - Returns the result text, cost, and new session_id
```

**Why two steps?**

The prime step is expensive (agent reads 5-15 files, $0.02-0.10). But once primed, subsequent tasks on the same session are cheap because context is already loaded. The General agent primes once and then handles dozens of follow-up messages on the same session.

**Implementation in `agent_sdk.py` (the worker layer):**

```python
# Step 1: Prime — creates a new session
result = await run_prime(workspace_dir=..., model="sonnet")
session_id = result.session_id

# Step 2: Task — resumes the session
result = await run_task_on_session(
    prompt="What were last month's revenue numbers?",
    session_id=session_id,
    workspace_dir=...,
)
```

The `ClaudeAgentOptions` object uses `resume = session_id` to continue an existing session instead of starting a new one.

---

## 2. Session Resume (Persistent Agents)

The General agent and spawned agent topics use persistent sessions that survive across messages AND bot restarts.

**How it works:**

1. `SessionManager` stores session metadata in `data/command/agent_sessions.json`
2. Each session is keyed by a topic identifier (e.g., `"general"`, `"12345"` for a topic ID)
3. When the bot restarts, it reads the JSON file and reconnects to existing sessions
4. The Claude Agent SDK's `resume` parameter handles the actual reconnection

**Session lifecycle:**

```
User sends first message in General
  --> No session found
  --> Prime (creates session)
  --> Save session_id to disk
  --> Run task on session
  --> Return response

User sends second message
  --> Session found in manager
  --> Run task on existing session (no prime needed)
  --> Agent has full context from previous interactions
  --> Return response

Bot restarts
  --> Load sessions from disk
  --> User sends message
  --> Session found (loaded from disk)
  --> Resume session via SDK
  --> Agent has all prior context
```

**Data format (`agent_sessions.json`):**

```json
{
  "general": {
    "session_id": "sess_abc123...",
    "model": "sonnet",
    "created": "2026-02-25T10:30:00Z",
    "name": "General",
    "total_cost": 1.45,
    "total_turns": 87,
    "last_input_tokens": 145000
  },
  "98765": {
    "session_id": "sess_def456...",
    "model": "opus",
    "created": "2026-02-25T14:00:00Z",
    "name": "Agent - Feb 25 2:00PM",
    "total_cost": 0.32,
    "total_turns": 12,
    "last_input_tokens": 52000
  }
}
```

**Thread safety:** The SessionManager uses `fcntl.flock()` for read locking and atomic writes (`tempfile` + `os.replace`) for write safety. Multiple processes can safely read; writes are serialized.

---

## 3. Message Debounce (1.5s Batching)

Telegram splits long pasted text into multiple messages. Users also fire off rapid successive messages. Without debouncing, each message would trigger a separate agent call.

**The debounce mechanism:**

```
Message 1 arrives --> start 1.5s timer
Message 2 arrives (0.5s later) --> cancel timer, restart 1.5s timer
Message 3 arrives (0.3s later) --> cancel timer, restart 1.5s timer
Timer expires (1.5s after Message 3) --> flush all 3 as one combined message
```

**Implementation in `bot.py`:**

```python
_DEBOUNCE_SECONDS = 1.5

def _enqueue_and_debounce(item: _BufferedItem) -> None:
    global _message_buffer, _debounce_task
    _message_buffer.append(item)
    if _debounce_task and not _debounce_task.done():
        _debounce_task.cancel()
    async def _debounce_fire():
        await asyncio.sleep(_DEBOUNCE_SECONDS)
        await _flush_message_buffer()
    _debounce_task = asyncio.create_task(_debounce_fire())
```

The buffer collects `_BufferedItem` objects which can be text, transcribed voice, or photos. When the timer fires, all items are concatenated and processed as a single request.

**The 1.5s value** is a balance between responsiveness (lower = faster) and batching effectiveness (higher = catches more split messages). Adjust `_DEBOUNCE_SECONDS` if needed.

---

## 4. Owner Lock

The bot locks to the first human user who sends a message. All subsequent messages from other users are silently ignored.

**Implementation in `bot.py`:**

```python
_owner_id: int | None = None

def _is_authorized(message: Message) -> bool:
    global _owner_id
    if not message.from_user or message.from_user.is_bot:
        return False
    if _owner_id is None:
        _owner_id = message.from_user.id
        return True
    return message.from_user.id == _owner_id
```

**Why auto-capture instead of a config value?** It avoids a setup step. The first time you message the bot, it locks to you. If you need to change the owner, restart the bot and have the new owner send the first message.

**For multi-user support:** Replace this with a whitelist check against configured user IDs:
```python
AUTHORIZED_USERS = {123456789, 987654321}  # From config

def _is_authorized(message: Message) -> bool:
    if not message.from_user or message.from_user.is_bot:
        return False
    return message.from_user.id in AUTHORIZED_USERS
```

---

## 5. Context Warning (180K Token Threshold)

Claude's context window is 200K tokens. As conversations grow, the context fills up. At 180K tokens (90%), the bot sends a warning.

**How it is tracked:**

After each agent interaction, the result includes `usage` with `input_tokens`. The SessionManager stores `last_input_tokens` per session.

```python
if input_tokens > self.config.context_warning_threshold:
    pct = int((input_tokens / 200000) * 100)
    await bot.send_message(
        text=f"Context at {pct}% ({input_tokens:,} tokens). Use /compact or /new.",
    )
```

**User options when context is high:**
- `/compact` — asks the agent to summarize and compress its context (partial relief)
- `/reset` — deletes the session entirely, next message starts fresh
- `/new` — spawns a fresh agent in a new topic (preserves the old session)

The threshold is configurable via `COMMAND_CONTEXT_WARNING_TOKENS` in `.env`. Default: 180,000.

---

## 6. File Path Detection (Auto-Delivery)

When agents create files (charts, PDFs, documents), the bot automatically detects the file paths in the response text and delivers them.

**Detection patterns:**

```python
# Chart images: ![title](outputs/charts/filename.png)
extract_image_paths(text)  # returns [(title, path), ...]

# Other created files: detects paths like outputs/reports/file.pdf
_extract_created_files(text)  # returns [path, ...]
```

**Delivery logic:**
- Image files (`.png`, `.jpg`) are sent as Telegram photos with captions
- PDFs and other files are sent as Telegram documents
- The agent does not need to know about Telegram — it just writes files normally and the delivery system handles it

---

## 7. Table Rendering

When an agent's response contains markdown tables, they are rendered as monospace `<pre>` blocks for clean display in Telegram.

**How it works:**

1. `formatting.py` detects markdown tables in the agent output (lines with `|` column separators)
2. Tables are parsed into headers and rows
3. Each table is rendered as a `<pre>` monospace block with aligned columns
4. The result displays cleanly on both mobile and desktop Telegram clients

**The splitting logic** (`extract_and_split_on_tables` in `formatting.py`) preserves reading order. A response like "Here are the numbers: [TABLE] And here is the analysis:" becomes segments sent in order — text, rendered table, text.

---

## 8. PDF Generation Pipeline

Long reports are auto-converted to PDF for easy reading on mobile.

**The pipeline:**

```
Agent markdown output
  |
  v
Split into summary + full report
  |
  v
Render markdown --> HTML (with tables, code blocks, TOC)
  |
  v
Apply report.css stylesheet
  |
  v
If charts referenced: embed as base64 data URIs
  |
  v
WeasyPrint: HTML --> PDF bytes
  |
  v
Send to Telegram as BufferedInputFile
```

**Dual delivery:** For reports, the bot sends a brief summary inline (in chat) AND the full PDF as a document. The user gets a quick overview without opening the file, plus the full details when they want them.

**Chart embedding:** When a report references chart images (`outputs/charts/*.png`), the PDF generator reads the image files, encodes them as base64, and embeds them directly in the HTML before PDF conversion. This makes the PDF self-contained.

**The CSS** (`templates/report.css`) uses WeasyPrint's `@page` directive for A4 margins, headers, and page numbers.

---

## 9. Progress Tracking (Live Status Edits)

For long-running tasks, the bot shows live progress by editing a status message in place.

**The pattern:**

```
User sends message
  --> Bot sends "Working on it..."
  --> Agent starts running
  --> Agent uses Read tool --> Bot edits message to "Reading files..."
  --> Agent uses Bash tool --> Bot edits message to "Running analysis..."
  --> Agent uses WebSearch --> Bot edits message to "Researching online..."
  --> Agent completes --> Bot deletes status message, sends result
```

**Tool-to-status mapping** (in `orchestrator.py`):
```python
TOOL_STATUS_MAP = {
    "Read": "Reading files...",
    "Glob": "Reading files...",
    "Grep": "Searching codebase...",
    "Bash": "Running analysis...",
    "WebSearch": "Researching online...",
    "WebFetch": "Researching online...",
    "Write": "Writing output...",
    "Edit": "Writing output...",
    "Task": "Running sub-task...",
}
```

**Throttling:** Status edits are throttled to one every 6 seconds (`PROGRESS_THROTTLE_SECONDS`) to avoid hitting Telegram's rate limits.

**Implementation:** The `on_tool_use` callback passed to `_run_agent()` receives tool names as the agent runs. The orchestrator maps them to human-readable statuses and edits the progress message.

---

## 10. Cost Model

Every agent interaction costs money. Here is the pricing structure (as of early 2026):

| Operation | Model | Typical Cost | Tokens |
|-----------|-------|-------------|--------|
| Prime (first message) | Sonnet | $0.02-0.10 | 5K-20K input |
| General agent reply | Sonnet | $0.01-0.05 | 10K-50K input |
| Spawned agent prime+task | Sonnet | $0.05-0.15 | 15K-40K input |
| Spawned Opus agent | Opus | $0.10-0.50 | 15K-40K input |
| Follow-up on existing session | Sonnet | $0.005-0.02 | Incremental |

**Cost tracking:**

Every interaction is logged to `data/command/costs.jsonl` with:
- Task ID, topic, model
- Cost in USD
- Duration in milliseconds
- Number of turns (agent steps)
- Timestamps

The `/cost` command reads this file and sums today's entries.

**Budget controls:**

| Config Variable | Default | What It Controls |
|----------------|---------|-----------------|
| `COMMAND_GENERAL_MAX_BUDGET` | $5.00 | Hard cap per agent message |
| `COMMAND_GENERAL_MAX_TURNS` | 30 | Max agent steps per message |
| `COMMAND_CONTEXT_WARNING_TOKENS` | 180,000 | Token threshold for context warning |

The Agent SDK enforces these limits. If an agent hits the budget or turn cap, it stops and returns whatever it has.

**Typical daily costs:**
- Light use (5-10 messages): $2-5/day
- Moderate use (20-30 messages): $5-15/day
- Heavy use (50+ messages, Opus agents): $15-30/day

---

## 11. Extending the System

### Add Special Topics

See [customization.md](customization.md) section 9. The pattern is: config entry --> load in config.py --> route in bot.py --> handler in orchestrator.py.

### Add Cron Jobs

Agents can be triggered on a schedule to produce daily reports, check metrics, or run maintenance tasks.

**Pattern:**

1. Write a Python script that calls the agent worker directly:
```python
# scripts/daily_report.py
import asyncio
from apps.command.worker import run_worker

async def main():
    result = await run_worker(
        prompt="Generate today's business summary. Query the database for metrics...",
        workspace_dir="/path/to/workspace",
        model="sonnet",
        max_turns=30,
        max_budget_usd=3.00,
        output_format="report",
    )
    # Send result to Telegram using the bot API
    # Or write to a file for later review

asyncio.run(main())
```

2. Schedule it with cron (Linux) or launchd (macOS):
```bash
# crontab -e
0 7 * * * cd /path/to/workspace && .venv/bin/python scripts/daily_report.py
```

### Add Database Integration

See [customization.md](customization.md) section 10. Agents can query any SQLite database via the Bash tool. Document your schema in CLAUDE.md and the agent system prompt.

### Add Slack/Discord Delivery

The delivery layer (`telegram_utils.py`) is Telegram-specific, but the agent layer is platform-agnostic. To add another delivery channel:

1. Create a new delivery module (e.g., `slack_utils.py`) with equivalent send functions
2. Add a routing option in the orchestrator that sends results to the new channel
3. The agent worker, formatting, and PDF generation all work unchanged

### Add Image Generation

Agents can generate images by writing Python scripts that call image generation APIs. The file path detection system will automatically deliver any created images.

Example: add to the General agent prompt:
```
## Image Generation
When asked to create images, write a Python script that calls the OpenAI DALL-E API
or GPT Image API. Save results to outputs/images/. The delivery system will automatically
send them to Telegram.
```

### Add Web Scraping

Agents already have `WebSearch` and `WebFetch` tools built in via Claude Code. For heavier scraping needs:

1. Install `playwright` in your virtual environment: `pip install playwright && playwright install`
2. Add scraping instructions to the agent prompt
3. Agents can write and execute Playwright scripts via the Bash tool

---

## Message Flow Diagram

```
Telegram Message
  |
  v
bot.py: handle_message()
  |-- Authorization check (owner lock)
  |-- Voice? --> transcribe with Whisper
  |-- Photo? --> download, base64 encode
  |-- Text? --> use directly
  |
  v
bot.py: _enqueue_and_debounce()
  |-- Add to buffer
  |-- Reset 1.5s timer
  |
  v (timer fires)
bot.py: _flush_message_buffer()
  |-- Concatenate all buffered items
  |-- Route based on topic:
      |
      |-- Agent topic (spawned) --> orchestrator.handle_agent_topic_message()
      |-- General topic --> orchestrator.handle_general_message()
          |
          |-- /new --> _spawn_new_agent()
          |-- /reset --> delete session
          |-- /compact --> compress context
          |-- /cost, /tasks, /help --> direct responses
          |-- text --> _handle_cc_agent_message()
              |
              |-- Session exists? Resume. No? Prime first.
              |-- Run task on session via Agent SDK
              |-- Clean output, split on tables
              |-- Render tables as <pre> blocks
              |-- Convert to Telegram HTML
              |-- Send segments in order
              |-- Detect and send created files
              |-- Send cost footer
              |-- Check context warning threshold
```

---

## File Responsibility Map

| File | Responsibility | Depends On |
|------|---------------|------------|
| `main.py` | Boot, wire components, start polling | config, logger, orchestrator, bot |
| `bot.py` | Receive messages, debounce, route | orchestrator, config |
| `orchestrator.py` | Agent lifecycle, delivery, commands | worker, sessions, formatting, telegram_utils |
| `worker.py` | System prompts, agent SDK wrappers | agent_sdk |
| `agent_sdk.py` | Claude Agent SDK interface | claude_agent_sdk package |
| `config.py` | Environment variable loading | python-dotenv |
| `session_manager.py` | Persistent session storage (JSON + file locks) | - |
| `formatting.py` | Markdown to Telegram HTML, table extraction | - |
| `telegram_utils.py` | Message splitting, sending, file delivery | aiogram |
| `cost_tracker.py` | JSONL cost logging, daily totals | - |
| `logger.py` | Colored console logging, boot banner | - |
| `chart_style.py` | Matplotlib brand styling | matplotlib |
| `pdf_generator.py` | Markdown to PDF via WeasyPrint | weasyprint, markdown |
| `templates/report.css` | PDF report stylesheet | - |
