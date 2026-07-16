# CommandOS — Your AI on Your Phone

> Phase 2 module from the AAA Accelerator AIOS Library.
> "Talk to your AI from anywhere — text, voice, photos. Full Claude Code on your phone."

## What This Does

CommandOS gives you a Telegram bot powered by Claude Code. You message it from your phone — text, voice notes, photos — and it dispatches AI agents with full access to your workspace. Think of it as having Claude Code in your pocket, available 24/7.

## Features

- **Persistent General agent** — always remembers your conversation
- **Spawn fresh agents** — `/new` creates a dedicated agent in its own topic
- **Voice notes** — speak into your phone, AI transcribes and processes
- **Photo analysis** — send screenshots, documents, charts — the agent sees everything
- **Topic management** — `/name` gives topics descriptive titles based on conversation
- **Context compression** — `/compact` when conversations get long
- **Smart delivery** — tables, charts, and PDF reports delivered right to Telegram
- **Auto-restart** — crashes recover automatically (launchd/systemd)

## Quick Numbers

| | |
|---|---|
| **Setup time** | 30-45 minutes |
| **API keys** | 2 required (Telegram + Anthropic) + 1 optional (OpenAI for voice) |
| **Platform** | Mac or Linux |
| **Dependencies** | Python 3.11+, Node.js, Claude Code CLI |
| **Always-on** | Yes — launchd (Mac) or systemd (Linux) |

## Install

Open Claude Code in your workspace and say:

```
Read INSTALL.md and help me set this up
```

## What's Inside

```
v1/
├── INSTALL.md           ← Claude reads this and walks you through setup
├── README.md            ← You are here
├── scripts/
│   ├── requirements.txt
│   ├── .env.example
│   ├── apps/command/    ← Bot source code (15 Python files)
│   └── .claude/commands/ ← Prime command templates
├── templates/
│   └── CLAUDE.md.template
├── config/
│   ├── com.commandos.bot.plist  ← macOS always-on config
│   └── command-bot.service      ← Linux always-on config
└── reference/
    ├── architecture.md  ← Deep patterns and extension guide
    └── customization.md ← Persona, branding, and context guide
```

## Dependencies

- **Requires:** ContextOS (you need a CLAUDE.md)
- **Enhanced by:** IntelOS (gives your bot access to meeting/Slack data), DataOS (database queries)
- **Unlocks:** Daily Brief (delivered through this bot)

---

> A plug-and-play module from Liam Ottley's AAA Accelerator
