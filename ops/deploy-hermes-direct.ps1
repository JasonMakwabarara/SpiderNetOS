# SpiderNetOS Hermes Direct Deployment (No Docker)
# Uses conda/uv virtual environment

param(
    [string]$OllamaUrl = "http://127.0.0.1:11434",
    [string]$SpiderNetApiUrl = "http://127.0.0.1:8000"
)

Write-Host "=== Hermes Direct Deployment (conda/uv) ===" -ForegroundColor Cyan
Write-Host ""

# Check Ollama
Write-Host "[1/4] Checking Ollama..." -ForegroundColor Yellow
try {
    $resp = Invoke-RestMethod -Uri "$OllamaUrl/api/tags" -Method GET -TimeoutSec 5
    Write-Host "  Ollama running with models: $($resp.models.name -join ', ')" -ForegroundColor Green
} catch {
    Write-Error "Ollama not running at $OllamaUrl. Start with: ollama serve"
    exit 1
}

# Check Python/uv environment
Write-Host ""
Write-Host "[2/4] Checking Python environment..." -ForegroundColor Yellow
$pythonPath = (Get-Command python -ErrorAction SilentlyContinue).Source
if (-not $pythonPath) {
    $pythonPath = (Get-Command python3 -ErrorAction SilentlyContinue).Source
}
if (-not $pythonPath) {
    Write-Error "Python not found. Ensure conda/uv environment is activated."
    exit 1
}
Write-Host "  Python: $pythonPath" -ForegroundColor Green

# Install dependencies
Write-Host ""
Write-Host "[3/4] Installing dependencies..." -ForegroundColor Yellow
& $pythonPath -m pip install -q fastapi uvicorn redis pydantic requests schedule aiohttp
Write-Host "  Dependencies installed" -ForegroundColor Green

# Create runtime directory and copy files
Write-Host ""
Write-Host "[4/4] Setting up runtime..." -ForegroundColor Yellow
$hermesDir = "hermes-runtime"
New-Item -ItemType Directory -Force -Path $hermesDir | Out-Null
Copy-Item "hermes-skills/hermes_local_bridge.py" "$hermesDir/" -Force
Copy-Item "hermes-skills/hermes_worker.py" "$hermesDir/" -Force

# Create startup script
$startScript = @"
# Start Hermes Bridge
cd "$PWD\$hermesDir"
`$env:OLLAMA_URL = "$OllamaUrl"
`$env:SPIDERNET_API_URL = "$SpiderNetApiUrl"
`$env:REDIS_URL = "redis://127.0.0.1:6379/2"

Write-Host "Starting Hermes API on port 8090..." -ForegroundColor Green
Start-Process python -ArgumentList "hermes_local_bridge.py" -WindowStyle Normal

Start-Sleep -Seconds 3

Write-Host "Starting Hermes Worker..." -ForegroundColor Green
Start-Process python -ArgumentList "hermes_worker.py" -WindowStyle Normal

Write-Host ""
Write-Host "Hermes deployed! Test with:" -ForegroundColor Cyan
Write-Host "  curl http://localhost:8090/health" -ForegroundColor Yellow
"@
$startScript | Out-File -FilePath "$hermesDir\start-hermes.ps1" -Encoding UTF8

Write-Host "  Runtime ready at .\$hermesDir\" -ForegroundColor Green
Write-Host ""
Write-Host "=== Deployment Complete ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "To start Hermes:" -ForegroundColor White
Write-Host "  cd $hermesDir; .\start-hermes.ps1" -ForegroundColor Yellow
Write-Host ""
Write-Host "Or start manually:" -ForegroundColor White
Write-Host "  cd $hermesDir" -ForegroundColor Gray
Write-Host "  `$env:OLLAMA_URL='$OllamaUrl'" -ForegroundColor Gray
Write-Host "  python hermes_local_bridge.py" -ForegroundColor Gray
