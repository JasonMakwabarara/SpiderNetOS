# Hermes Discord Integration Setup

## Quick Start

### 1. Create Discord Bot
1. Go to https://discord.com/developers/applications
2. Click "New Application" → Name it "Hermes"
3. Go to "Bot" tab → Click "Add Bot"
4. Enable these Privileged Gateway Intents:
   - MESSAGE CONTENT INTENT (required for reading messages)
   - SERVER MEMBERS INTENT
5. Copy the **Bot Token** (click "Reset Token" if needed)

### 2. Invite Bot to Server
1. Go to OAuth2 → URL Generator
2. Select scopes: `bot`, `applications.commands`
3. Bot permissions needed:
   - Send Messages
   - Read Messages/View Channels
   - Read Message History
   - Mention Everyone
   - Embed Links
   - Add Reactions
   - Use Slash Commands
4. Copy the generated URL and open it in browser
5. Select your server and authorize

### 3. Configure Environment
```powershell
# Set Discord bot token
$env:DISCORD_BOT_TOKEN = "YOUR_BOT_TOKEN_HERE"

# Verify Hermes API is running
$env:HERMES_API_URL = "http://127.0.0.1:8090"
$env:SPIDERNET_API_URL = "http://127.0.0.1:8000"
```

### 4. Install Dependencies
```powershell
cd hermes-runtime
pip install discord.py aiohttp python-dotenv
```

### 5. Start Discord Bot
```powershell
cd hermes-runtime
copy ..\hermes-skills\discord_bot.py .\discord_bot.py
$env:DISCORD_BOT_TOKEN = "YOUR_TOKEN"
$env:HERMES_API_URL = "http://127.0.0.1:8090"
python discord_bot.py
```

## Usage

### DM the Bot
- Direct message Hermes for private conversations
- All messages are processed through SpiderNetOS agents

### Mention in Channels
- Type `@Hermes your message` in any channel
- Bot will reply with coordinated agent response

### Commands
- `!help` - Show help information
- `!status` - Check Hermes/SpiderNetOS status
- `!agents` - List available SpiderNetOS agents

## How It Works

```
Discord Message → Discord Bot → Hermes API (8090)
                                      ↓
                              Ollama (gemma4:31b)
                                      ↓
                         SpiderNetOS Agents
                                      ↓
                         Response → Discord
```

## Troubleshooting

**Bot doesn't respond:**
- Check bot token: `$env:DISCORD_BOT_TOKEN`
- Verify Hermes API: `curl http://localhost:8090/health`
- Check bot permissions in Discord

**"Connection error":**
- Ensure Hermes API is running on port 8090
- Check firewall settings

**Timeout on first message:**
- gemma4:31b takes time to load (31B parameters)
- Wait 2-3 minutes after starting Hermes

## Production Deployment

Add to your `.env` file:
```
DISCORD_BOT_TOKEN=your_token_here
DISCORD_ENABLED=true
```

Start with SpiderNetOS:
```powershell
cd hermes-runtime
.\start-hermes-discord.ps1  # (create this script)
```
