# Start Hermes with Discord Integration
$env:OLLAMA_URL = "http://127.0.0.1:11434"
$env:SPIDERNET_API_URL = "http://127.0.0.1:8000"
$env:HERMES_API_URL = "http://127.0.0.1:8090"
$env:REDIS_URL = "redis://127.0.0.1:6379/2"

# Check if Discord token is set
if (-not $env:DISCORD_BOT_TOKEN) {
    Write-Host "WARNING: DISCORD_BOT_TOKEN not set!" -ForegroundColor Red
    Write-Host "Set it with: `$env:DISCORD_BOT_TOKEN = 'your_token'" -ForegroundColor Yellow
    Write-Host "Continuing without Discord bot..." -ForegroundColor Gray
    Start-Sleep -Seconds 3
} else {
    Write-Host "Discord bot token configured" -ForegroundColor Green
}

Write-Host "Starting Hermes API on port 8090..." -ForegroundColor Green
Start-Process python -ArgumentList "hermes_local_bridge.py" -WindowStyle Normal

Start-Sleep -Seconds 3

Write-Host "Starting Hermes Worker..." -ForegroundColor Green
Start-Process python -ArgumentList "hermes_worker.py" -WindowStyle Normal

# Start Discord bot if token is set
if ($env:DISCORD_BOT_TOKEN) {
    Write-Host "Starting Discord Bot..." -ForegroundColor Green
    Start-Process python -ArgumentList "discord_bot.py" -WindowStyle Normal
}

Write-Host ""
Write-Host "Hermes deployed with Discord integration!" -ForegroundColor Cyan
Write-Host ""
Write-Host "Test endpoints:" -ForegroundColor White
Write-Host "  curl http://localhost:8090/health" -ForegroundColor Yellow
Write-Host "  DM your Discord bot or mention @Hermes" -ForegroundColor Yellow
Write-Host ""
Write-Host "Stop all: Get-Process python | Stop-Process" -ForegroundColor Gray
