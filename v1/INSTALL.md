# CommandOS — AIOS Module Installer

> A plug-and-play module from the AAA Accelerator.
> Grab this and 15+ more at [aaaaccelerator.com](https://aaaaccelerator.com)

<!-- MODULE METADATA
module: command-os
version: v1
status: RELEASED
released: 2026-02-27
requires: [context-os]
phase: 2
category: Core OS
complexity: complex
api_keys: 2-3
setup_time: 30-45 minutes
-->

---

## YOUR AIOS JOURNEY

**Layer 3 — Intelligence (Part 2).** Your AI knows your business, sees your data, and captures your meetings. Now it needs to be in your pocket.

Your AIOS is only as useful as your ability to access it. Right now it lives on your laptop. CommandOS puts it on your phone — Telegram becomes your AI command center. Text, voice notes, photos, brain dumps — all processed with full workspace context.

This is where the Away-From-Desk Autonomy KPI starts climbing. Make decisions on the go. Get answers while you're walking the dog. Run your business from your phone. The Operator Trap relied on you being physically present — CommandOS breaks that.

**After this → Daily Brief** (the capstone of Layer 3). Everything you've built gets synthesized into one morning report.

---

## FOR CLAUDE

You are helping a user install CommandOS — a Telegram bot that gives them an AI assistant on their phone, powered by Claude Code. This is a complex module with multiple moving parts (Telegram bot, Claude Agent SDK, persistent sessions). Follow these rules:

**Critical rule — the user NEVER touches the terminal:**
- YOU execute every single command. The user does not type in a terminal, ever.
- The user's job is: answer your questions, do things in Telegram / browser (creating bots, getting API keys), and paste keys to you.
- You handle ALL file creation, package installation, environment setup, configuration, testing, and deployment silently.
- When you run a command, tell the user what you're doing in plain English ("Setting up your bot's environment..." / "Installing the packages your bot needs...") — never show them the command or ask them to run it.
- The only time the user interacts with a terminal is if they explicitly choose to — but assume they won't and don't ask them to.

**Behavior:**
- Assume the user is non-technical — they may have never opened a terminal before
- Explain what you are doing at each step in plain English BEFORE doing it
- Celebrate milestones — getting the first bot response is a huge moment
- If something fails, do not dump error logs — explain the problem simply and fix it yourself
- Never skip verification steps — if a check fails, stop and fix it before moving on
- Use encouraging language throughout — they are building something genuinely powerful

**Pacing:**
- Do NOT rush. This is the most complex module. Pause after major milestones.
- After prerequisites: "We've got all the tools. Ready to create your bot?"
- After Telegram setup: "Your bot exists! Now let's give it a brain."
- After first test message: "It's alive! You just sent a message from your phone and an AI agent processed it. That's the foundation of your entire AIOS command system."
- After deployment: "Your bot is now running 24/7. From anywhere in the world, you can open Telegram and talk to your AI."

**Error handling:**
- If Python version is too old → provide exact upgrade instructions for their OS
- If `claude` CLI not found → `npm install -g @anthropic-ai/claude-code`
- If `claude_agent_sdk` import fails → `pip install claude-agent-sdk`
- If Telegram bot doesn't respond → check: (1) bot is admin in group, (2) group ID is correct (negative number starting with -100), (3) Topics are enabled
- If "Prime failed" → prime command references files that don't exist yet
- If WeasyPrint fails to install → try `brew install weasyprint` on macOS, `apt install python3-weasyprint` on Linux, or fall back to installing system dependencies: `brew install pango cairo gdk-pixbuf libffi`
- Never say "check the logs" — find the problem and explain it

**Important — Workspace context:**
The user may already have a workspace with a CLAUDE.md (from ContextOS) or may be starting fresh. If they have ContextOS installed, their CLAUDE.md and prime commands may already exist — adapt the setup to their existing structure rather than overwriting. If they're starting fresh, create everything from scratch.

**Important — File placement:**
When copying module files into the user's workspace, maintain the `apps/command/` package structure. The bot is a Python package that imports modules from within itself. The final structure in their workspace should be:
```
workspace/
├── CLAUDE.md
├── .env
├── .claude/commands/
│   ├── prime.md
│   └── prime-telegram.md
├── apps/
│   └── command/
│       ├── __init__.py
│       ├── main.py
│       ├── agent_sdk.py
│       ├── bot.py
│       ├── config.py
│       ├── orchestrator.py
│       ├── worker.py
│       ├── session_manager.py
│       ├── cost_tracker.py
│       ├── formatting.py
│       ├── telegram_utils.py
│       ├── logger.py
│       ├── chart_style.py
│       ├── pdf_generator.py
│       └── templates/
│           └── report.css
├── requirements.txt
└── data/command/  (created automatically)
```

---

## OVERVIEW

Read this to the user before starting:

We're about to set up **CommandOS** — a Telegram bot that puts a full Claude Code agent on your phone. You message it — text, voice notes, photos, screenshots, brain dumps — and it dispatches AI agents with full access to your workspace.

Here's what you'll have when we're done:

- **An AI assistant on your phone** — message it from anywhere, get intelligent responses based on your full workspace context
- **Persistent conversations** — the General agent remembers everything you've discussed, even across bot restarts
- **Isolated task agents** — spawn fresh agents for specific tasks, each in their own Telegram topic thread (like having multiple Claude Code sessions organized by project)
- **Voice notes** — speak into your phone, it transcribes and processes your message (optional, needs OpenAI key)
- **Photo/screenshot analysis** — send an image, the agent sees and analyzes it
- **Smart formatting** — tables render cleanly, charts get generated as images, long reports become professional PDFs
- **Topic management** — use /name to rename your agent topics with descriptive titles based on the conversation
- **Context awareness** — agents read your CLAUDE.md and workspace files, so they know your business

**Setup time:** 30-45 minutes
**Prerequisites:** ContextOS should be installed first (you need a CLAUDE.md). Everything else we'll set up together.

---

## SCOPING

Before installation, we need to figure out your setup. Ask the user these questions:

### Question 1: Voice Notes

"Do you want to be able to send voice notes to your bot? You'd speak into your phone and the AI transcribes and processes it. This requires an OpenAI API key (for Whisper transcription)."

**Options:**
- **A) Yes, set up voice notes** — We'll need your OpenAI API key. If you don't have one, I'll walk you through creating it.
- **B) Skip for now** — No problem, text and photos still work perfectly. You can always add voice notes later by adding an OpenAI key.

Record: `VOICE_ENABLED = true | false`

### Question 2: PDF Reports

"Do you want the bot to generate professional PDF reports? When you ask for analysis or reports, instead of a wall of text, you get a nicely formatted PDF delivered right to Telegram."

**Options:**
- **A) Yes, set up PDFs** (recommended) — Requires installing WeasyPrint (a PDF rendering library). I'll handle the installation.
- **B) Skip for now** — Reports will still be delivered as text messages or markdown files.

Record: `PDF_ENABLED = true | false`

### Question 3: Charts

"Do you want the bot to generate data visualizations? When you ask about metrics or data, the agent can create charts and deliver them as images in Telegram."

**Options:**
- **A) Yes, set up charts** (recommended) — Requires matplotlib. Easy to install.
- **B) Skip for now** — Data will be presented as text/tables instead of visual charts.

Record: `CHARTS_ENABLED = true | false`

### Question 4: Platform

"What machine will this bot run on? It needs to be always-on to receive Telegram messages."

**Options:**
- **A) Mac** (Mac Mini, MacBook, iMac) — We'll use macOS launchd to keep it running
- **B) Linux** (VPS, cloud server, Raspberry Pi) — We'll use systemd
- **C) Just my laptop for now** — That's fine for getting started. We'll skip the always-on setup and you can run it manually.

Record: `PLATFORM = mac | linux | laptop`

After scoping, summarize: "Here's what we're setting up: CommandOS with {voice status}, {PDF status}, {chart status}, on {platform}. Sound good?"

---

## PREREQUISITES

Check each prerequisite. Verify it works before proceeding.

### Python 3.11+
```bash
python3 --version
```
If not installed or too old: provide OS-specific install instructions.
- macOS: `brew install python@3.12` or download from python.org
- Linux: `sudo apt install python3.12` or equivalent

### Node.js (for Claude Code CLI)
```bash
node --version
```
If not installed:
- macOS: `brew install node`
- Linux: `curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt install -y nodejs`

### Claude Code CLI
```bash
claude --version
```
If not installed:
```bash
npm install -g @anthropic-ai/claude-code
```

### pip
```bash
python3 -m pip --version
```
If not installed:
```bash
python3 -m ensurepip --upgrade
```

### Existing workspace (from ContextOS)
```bash
ls CLAUDE.md
```
If CLAUDE.md doesn't exist: "It looks like you don't have a workspace set up yet. That's okay — we'll create a basic one. But for the best experience, set up ContextOS first — it creates the workspace foundation that CommandOS plugs into."

If no CLAUDE.md exists, create a minimal one:
```markdown
# My Workspace

## What This Is
[Ask the user to describe their business/project in 2-3 sentences and fill this in]

## Key Files
- `CLAUDE.md` — This file (workspace overview)
```

[VERIFY] All prerequisites should show version numbers without errors.
Ask: "Everything checks out. Ready to create your Telegram bot?"

---

## API KEYS

Collect API keys based on the scoping decisions.

### Telegram Bot Token (required)

This takes about 2 minutes.

1. Open Telegram on your phone or desktop
2. Search for **@BotFather** (it has a blue checkmark — it's official)
3. Tap **Start** if this is your first time
4. Send the message: `/newbot`
5. BotFather asks for a **display name** — type something like "My Command Bot" or "[YourBusiness] Bot"
6. BotFather asks for a **username** — this must end in `bot`. Examples: `mycommand_bot`, `acme_ai_bot`. Pick something unique.
7. BotFather replies with a token that looks like: `7123456789:AAHx_your_long_token_here`
8. **Copy that entire token** — paste it here and I'll save it

**Important:** Never share this token. Anyone with it can control your bot.

[VERIFY] Token format should be: number:letters_and_numbers (e.g., 7123456789:AAH...)

### Telegram Group ID (required)

Now we need to create the group where you'll talk to your bot.

1. Open Telegram and **create a new group**
2. Name it something like "Command Center" or "[YourBusiness] HQ"
3. Add your bot to the group (search for the username you just created)
4. Go to **Group Settings** → **Administrators** → **Add Administrator** → select your bot → grant **all permissions** → save
5. Go to **Group Settings** → scroll down → find **Topics** → **toggle it ON**

Now we need the Group ID:

6. Send any message in the group (even just "test")
7. Then I'll run this command to find your group ID:

```bash
# Replace YOUR_BOT_TOKEN with the token from above
curl -s "https://api.telegram.org/botYOUR_BOT_TOKEN/getUpdates" | python3 -c "
import json, sys
data = json.load(sys.stdin)
if data.get('result'):
    for update in data['result']:
        msg = update.get('message', {})
        chat = msg.get('chat', {})
        if chat.get('id'):
            print(f'Group ID: {chat[\"id\"]}')
            print(f'Group Name: {chat.get(\"title\", \"unknown\")}')
            break
else:
    print('No updates found. Make sure you sent a message in the group first, then try again.')
"
```

The group ID will be a negative number starting with `-100`, like `-1001234567890`.

[VERIFY]
```bash
# Quick verify — bot can see the group
curl -s "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/getChat?chat_id=$TELEGRAM_GROUP_ID" | python3 -c "
import json, sys
data = json.load(sys.stdin)
if data.get('ok'):
    print(f'Connected to group: {data[\"result\"][\"title\"]}')
    print(f'Topics enabled: {data[\"result\"].get(\"is_forum\", False)}')
else:
    print(f'Error: {data.get(\"description\", \"unknown\")}')
    print('Check that: (1) the bot token is correct, (2) the group ID is correct, (3) the bot is in the group')
"
```
Expected: "Connected to group: [name]" and "Topics enabled: True"

If Topics not enabled: "Go to your Telegram group → Settings → Topics → turn it ON. This gives each agent its own thread."

### Anthropic API Key (required)

This is the key that powers the Claude agents.

1. Go to [console.anthropic.com](https://console.anthropic.com)
2. Sign in (or create an account)
3. Click **API Keys** in the left sidebar
4. Click **Create Key**
5. Name it something like "CommandOS Bot"
6. Copy the key (starts with `sk-ant-`)
7. Paste it here

**Note:** If you're on a Claude plan (Max, Team, or Enterprise), you may already have an API key through your organization. Check with your admin.

[VERIFY]
```bash
python3 -c "
import os
from dotenv import load_dotenv
load_dotenv()
key = os.getenv('ANTHROPIC_API_KEY', '')
if not key:
    print('ERROR: Key not found in .env')
elif not key.startswith('sk-ant-'):
    print('WARNING: Key does not start with sk-ant- — double-check you copied the right key')
else:
    print(f'Anthropic key configured ({key[:12]}...)')
"
```

### OpenAI API Key [if VOICE_ENABLED = true]

This is for voice note transcription (Whisper).

1. Go to [platform.openai.com/api-keys](https://platform.openai.com/api-keys)
2. Sign in (or create an account)
3. Click **Create new secret key**
4. Name it "CommandOS Voice"
5. Copy the key (starts with `sk-`)
6. Paste it here

[VERIFY]
```bash
python3 -c "
import os
from dotenv import load_dotenv
load_dotenv()
key = os.getenv('OPENAI_API_KEY', '')
if not key:
    print('ERROR: Key not found in .env')
elif not key.startswith('sk-'):
    print('WARNING: Key does not start with sk- — check you copied the right one')
else:
    print(f'OpenAI key configured ({key[:8]}...)')
"
```

After all keys are collected: "All keys verified. The setup part is done — now we build your bot."

---

## INSTALL

Follow each step in order. Verify before moving to the next.

### Step 1: Create virtual environment and install dependencies

```bash
# Create a virtual environment (if not already present)
python3 -m venv .venv
source .venv/bin/activate

# Install core dependencies
pip install -r requirements.txt
```

The `requirements.txt` from this module includes:
- `aiogram>=3.0` — Telegram bot framework
- `claude-agent-sdk` — Claude Code Agent SDK
- `python-dotenv` — Environment variable loading
- `markdown` — Markdown processing (for PDFs)

**If PDF_ENABLED:**
```bash
pip install weasyprint
```
If WeasyPrint fails (common on macOS):
```bash
# macOS — install system dependencies first
brew install pango cairo gdk-pixbuf libffi
pip install weasyprint
```
```bash
# Linux
sudo apt install python3-weasyprint
# or: sudo apt install libpango-1.0-0 libcairo2 libgdk-pixbuf2.0-0 && pip install weasyprint
```

**If CHARTS_ENABLED:**
```bash
pip install matplotlib
```

**If VOICE_ENABLED:**
```bash
pip install openai
```

[VERIFY]
```bash
python3 -c "
from aiogram import Bot; print('aiogram OK')
from claude_agent_sdk import query; print('claude-agent-sdk OK')
from dotenv import load_dotenv; print('dotenv OK')
"
```
Optional checks:
```bash
python3 -c "from weasyprint import HTML; print('weasyprint OK')" 2>/dev/null || echo "weasyprint not installed (PDFs disabled)"
python3 -c "import matplotlib; print('matplotlib OK')" 2>/dev/null || echo "matplotlib not installed (charts disabled)"
python3 -c "from openai import AsyncOpenAI; print('openai OK')" 2>/dev/null || echo "openai not installed (voice disabled)"
```

### Step 2: Create the .env file

Write the .env file with the collected API keys. Place it in the workspace root (same folder as CLAUDE.md).

```
# === CommandOS ===
TELEGRAM_BOT_TOKEN=your_token_here
TELEGRAM_GROUP_ID=-100your_group_id
ANTHROPIC_API_KEY=sk-ant-your_key_here

# === Optional ===
OPENAI_API_KEY=sk-your_openai_key_here

# === Tuning (defaults are fine to start) ===
# COMMAND_GENERAL_MODEL=sonnet
# COMMAND_GENERAL_MAX_TURNS=30
# COMMAND_GENERAL_MAX_BUDGET=5.00
# COMMAND_CONTEXT_WARNING_TOKENS=180000
```

**If a .env file already exists:** Append these keys to it. Don't overwrite existing keys.

[VERIFY]
```bash
python3 -c "
from dotenv import load_dotenv; import os; load_dotenv()
token = os.getenv('TELEGRAM_BOT_TOKEN', '')
group = os.getenv('TELEGRAM_GROUP_ID', '')
anthropic = os.getenv('ANTHROPIC_API_KEY', '')
print(f'Bot token: {\"OK\" if token else \"MISSING\"} ({token[:10]}...)' if token else 'Bot token: MISSING')
print(f'Group ID: {\"OK\" if group else \"MISSING\"} ({group})' if group else 'Group ID: MISSING')
print(f'Anthropic: {\"OK\" if anthropic else \"MISSING\"} ({anthropic[:12]}...)' if anthropic else 'Anthropic: MISSING')
openai_key = os.getenv('OPENAI_API_KEY', '')
if openai_key: print(f'OpenAI: OK ({openai_key[:8]}...)')
else: print('OpenAI: not set (voice notes disabled)')
"
```

### Step 3: Copy bot code into workspace

Copy all the bot files from this module's `scripts/` directory into your workspace. The key structure is:

```
your-workspace/
├── apps/
│   ├── __init__.py          (empty file)
│   └── command/
│       ├── __init__.py      (empty file)
│       ├── agent_sdk.py     ← Claude Agent SDK wrapper
│       ├── bot.py           ← Telegram message handlers
│       ├── config.py        ← Environment configuration
│       ├── orchestrator.py  ← Core engine (routing, sessions, commands)
│       ├── worker.py        ← System prompts & agent wrappers
│       ├── session_manager.py ← Persistent session storage
│       ├── cost_tracker.py  ← Usage logging
│       ├── formatting.py    ← Markdown → Telegram HTML
│       ├── telegram_utils.py ← Message delivery
│       ├── logger.py        ← Colored logging & boot banner
│       ├── main.py          ← Entry point (starts the bot)
│       ├── chart_style.py   ← Chart branding (if charts enabled)
│       ├── pdf_generator.py ← PDF generation (if PDFs enabled)
│       └── templates/
│           └── report.css   ← PDF stylesheet
└── requirements.txt
```

Copy each file from `scripts/apps/` to the workspace's `apps/` directory. Make sure both `__init__.py` files exist (they can be empty).

**If the user already has an `apps/` directory:** Add the `command/` subdirectory alongside their existing apps. Don't overwrite anything.

[VERIFY]
```bash
python3 -c "
from apps.command.config import load_config
c = load_config()
print(f'Config loaded — group: {c.group_id}, model: {c.general_agent_model}')
"
```
Expected: Should print the group ID and model without import errors.

### Step 4: Set up prime commands

Prime commands tell agents which files to read when they start a session. Copy the templates from `scripts/.claude/commands/` into your workspace's `.claude/commands/` directory.

```bash
mkdir -p .claude/commands
```

**If prime commands already exist** (from ContextOS): Leave them as-is. The bot will use whatever prime commands are already configured.

**If no prime commands exist:** Copy the templates from this module:
- `.claude/commands/prime.md` — Full prime for spawned agents (/new)
- `.claude/commands/prime-telegram.md` — Shorter prime for the General agent (faster response)

Then edit both files to reference YOUR context files. At minimum, each should tell the agent to read:
1. `CLAUDE.md` — your workspace overview
2. Any key business documents or strategy files you have

The more relevant files you include in the prime command, the smarter your agents will be. But keep `prime-telegram.md` lean — it runs on every first message and the user is waiting.

[VERIFY]
```bash
ls .claude/commands/prime.md .claude/commands/prime-telegram.md
```
Both files should exist.

### Step 5: Customize the worker prompt

Open `apps/command/worker.py` and look at the `_GENERAL_AGENT_PROMPT` string near the top. This is the personality and instructions for your General agent.

The default is generic. Help the user customize it:

Ask: "What should your AI assistant know about you and your business? For example: What's your role? What kind of tasks do you want it to help with? Any specific rules it should follow?"

Based on their answer, update the `_GENERAL_AGENT_PROMPT` to reflect their business context. Keep it concise — this prompt is prepended to every agent interaction.

[VERIFY] Read back the customized prompt to the user: "Here's how your agent will introduce itself. Does this sound right?"

### Step 6: Create data directory

```bash
mkdir -p data/command
```

This is where the bot stores session data and logs.

[VERIFY]
```bash
ls -la data/command/
```

---

## TEST

### Quick test — Bot startup

```bash
source .venv/bin/activate
python -m apps.command.main
```

You should see:
1. A branded boot banner
2. Configuration summary (model, workspace path)
3. System checks (all green checkmarks)
4. "Online — polling for messages"

If you see errors, stop and fix them before continuing.

### Test 1: Basic message

1. Open your Telegram group
2. Go to the **General** topic
3. Send: "Hello! What workspace are you working in?"
4. Wait 10-30 seconds — the bot will prime the agent (first message takes longer)
5. The agent should respond with information from your CLAUDE.md

"Your bot just responded! That response came from a Claude Code agent with full access to your workspace. Everything you put in your files, it can see and act on."

### Test 2: Spawn a new agent

1. In the General topic, send: `/new`
2. A new forum topic should appear with a name like "Agent — Feb 27 2:30PM UTC"
3. Wait for the "Primed and ready" confirmation
4. Send a message in that new topic — the agent should respond

"You just spawned a dedicated agent. This is like opening a new Claude Code session — it has its own conversation thread and full context."

### Test 3: Topic naming

1. In the agent topic from Test 2, have a brief conversation about something specific
2. Then send: `/name`
3. The agent should suggest a descriptive name and rename the topic

"The /name command is how you keep your topics organized. After working on something, hit /name and the agent gives the topic a descriptive title. Way better than 'Agent — Feb 27 2:30PM'."

### Test 4: Photo analysis

1. In any topic, send a screenshot or photo with a caption like "What's in this image?"
2. The agent should describe and analyze the image

### Test 5: Voice note [if VOICE_ENABLED]

1. Record a voice note in Telegram and send it
2. The bot should reply "Transcribing voice note..."
3. Then process the transcription and respond

### Test 6: Commands

1. Send `/help` to see all available commands
2. Send `/compact` to test context compression (useful after long conversations)

If all tests pass: "CommandOS is live! You now have a full AI command center on your phone. Let's set it up to run 24/7."

---

## DEPLOYMENT

Set up the bot to run continuously so it's always ready when you message it.

### If PLATFORM = laptop

"For now, just run `python -m apps.command.main` whenever you want to use the bot. When you're ready for always-on operation, get a Mac Mini, VPS, or cloud server and come back to this section."

Skip to WHAT'S NEXT.

### If PLATFORM = mac

We'll use macOS launchd — it keeps the bot running and auto-restarts if it crashes.

1. Copy the plist template from this module's `config/` folder:
```bash
cp config/com.commandos.bot.plist ~/Library/LaunchAgents/com.commandos.bot.plist
```

2. Edit the plist to set correct paths. Replace these placeholders:
   - `__VENV_PYTHON__` → full path to your venv Python (run `which python` with venv active to find it)
   - `__WORKSPACE_ROOT__` → full path to your workspace (run `pwd` in your workspace)
   - `__USERNAME__` → your macOS username (run `whoami`)

3. Create the log directory:
```bash
mkdir -p data
```

4. Load the service:
```bash
launchctl load ~/Library/LaunchAgents/com.commandos.bot.plist
```

5. Verify:
```bash
launchctl list | grep commandos
```
Should show a PID and exit code 0.

6. Stop the manual bot process if it's still running (Ctrl+C), launchd is now managing it.

**Managing the bot:**
- **Stop:** `launchctl unload ~/Library/LaunchAgents/com.commandos.bot.plist`
- **Restart:** Unload then load, or send `/reboot` in Telegram
- **View logs:** `tail -f data/command.stdout.log`

### If PLATFORM = linux

We'll use systemd — Linux's built-in service manager.

1. Copy the service template from this module's `config/` folder to systemd:
```bash
sudo cp config/command-bot.service /etc/systemd/system/command-bot.service
```

2. Edit the service file. Replace these placeholders:
   - `__VENV_PYTHON__` → full path to your venv Python
   - `__WORKSPACE_ROOT__` → full path to your workspace
   - `__USERNAME__` → your Linux username

3. Enable and start:
```bash
sudo systemctl daemon-reload
sudo systemctl enable command-bot
sudo systemctl start command-bot
```

4. Verify:
```bash
sudo systemctl status command-bot
```
Should show "active (running)".

**Managing the bot:**
- **Stop:** `sudo systemctl stop command-bot`
- **Restart:** `sudo systemctl restart command-bot` or send `/reboot` in Telegram
- **View logs:** `journalctl -u command-bot -f`

---

## HOW TO USE IT

Now that CommandOS is running, here's your command reference:

| Command | Where | What it does |
|---------|-------|-------------|
| `/new` | General | Spawn a fresh Sonnet agent in a new topic |
| `/new opus` | General | Spawn a fresh Opus agent (more capable) |
| `/name` | Any agent topic | Rename the topic based on your conversation |
| `/compact` | Any agent topic | Compress context when the agent starts forgetting |
| `/reset` | Any agent topic | Clear the session and start fresh |
| `/help` | General | Show the command list |
| `/reboot` | Anywhere | Restart the bot process |

**Daily workflow tips:**

1. **General topic is your home base** — quick questions, status checks, small tasks. It remembers everything.
2. **Spawn agents for focused work** — use `/new` for tasks that deserve their own thread (research, analysis, project work). Use `/new opus` for complex reasoning.
3. **Name your topics** — after a productive conversation, send `/name` so you can find it later.
4. **Voice notes are powerful** — brain dump on your commute, the agent captures and processes everything.
5. **Send screenshots** — see an interesting chart, error message, or document? Snap and send.
6. **Use /compact** when the agent starts giving shorter answers or seems to forget earlier context — it compresses the conversation to free up space.

**Pro tip:** Add this to your CLAUDE.md so all agents know CommandOS is available:
```markdown
## CommandOS — Telegram AI Assistant
- Bot: Running as always-on service
- Commands: /new (spawn agent), /name (rename topic), /compact (compress context)
- The bot has full workspace access — files, database, web search, code execution
- Persistent sessions survive restarts
```

---

## WHAT'S NEXT

**Your AI is in your pocket.** From anywhere in the world, open Telegram and talk to your AIOS. That's a fundamental shift in how you run your business.

Your AIOS journey continues:
1. **Next: Daily Brief** — The capstone of Layer 3. Everything you've built (Context + Data + IntelOS + CommandOS) synthesized into a morning intelligence report delivered to Telegram. Wake up, check your phone, you're the most informed person in your org.
2. **After Layer 3: Layer 4 — Automate** — The platform is built. Time to audit your tasks and start crossing them off permanently. This is where "cool AI system" becomes "I'm getting my life back."
3. **Then: Layer 5 — Build** — Custom automations unique to your business.

Keep the bot running. The more you use it, the more natural it becomes. Soon you'll forget there was ever a time you couldn't message your business.

---

> A plug-and-play module from Liam Ottley's AAA Accelerator — the #1 AI business launch & AIOS program. Grab this and 15+ more at [aaaaccelerator.com](https://aaaaccelerator.com)
