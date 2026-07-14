# SpiderNetOS Hermes Agent Setup for Windows
# Uses Docker with local Ollama models (gemma4:31b, qwen3.6)

param(
    [string]$OllamaUrl = "http://host.docker.internal:11434",
    [string]$SpiderNetApiUrl = "http://host.docker.internal:8000",
    [switch]$SkipModelCheck = $false
)

Write-Host "=== SpiderNetOS Hermes Agent Setup (Windows) ===" -ForegroundColor Cyan
Write-Host "Using Ollama models: gemma4:31b (primary), qwen3.6:latest (fallback)" -ForegroundColor Gray
Write-Host ""

# Check Docker
Write-Host "[1/6] Checking Docker..." -ForegroundColor Yellow
if (!(Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "Docker not found. Install Docker Desktop first."
    exit 1
}
$dockerInfo = docker info 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Error "Docker not running. Start Docker Desktop."
    exit 1
}
Write-Host "  Docker is running" -ForegroundColor Green

# Check Ollama
Write-Host "[2/6] Checking Ollama..." -ForegroundColor Yellow
try {
    $ollamaResponse = Invoke-RestMethod -Uri "$OllamaUrl/api/tags" -Method GET -TimeoutSec 5
    Write-Host "  Ollama is accessible at $OllamaUrl" -ForegroundColor Green
    
    # Check for required models
    $models = $ollamaResponse.models.name
    Write-Host "  Available models: $($models -join ', ')" -ForegroundColor Gray
    
    if (!$SkipModelCheck) {
        if ($models -notcontains "gemma4:31b") {
            Write-Warning "gemma4:31b not found. Pull with: ollama pull gemma4:31b"
        }
        if ($models -notcontains "qwen3.6:latest") {
            Write-Warning "qwen3.6:latest not found. Pull with: ollama pull qwen3.6:latest"
        }
    }
} catch {
    Write-Error "Cannot connect to Ollama at $OllamaUrl. Ensure Ollama is running."
    exit 1
}

# Create directories
Write-Host "[3/6] Creating directories..." -ForegroundColor Yellow
New-Item -ItemType Directory -Force -Path "hermes-config" | Out-Null
New-Item -ItemType Directory -Force -Path "hermes-data" | Out-Null
Write-Host "  Directories created" -ForegroundColor Green

# Create Hermes configuration
Write-Host "[4/6] Creating Hermes configuration..." -ForegroundColor Yellow
$hermesConfig = @"
{
  "name": "SpiderNetOS-Hermes",
  "version": "1.0.0",
  "models": {
    "primary": "gemma4:31b",
    "fallback": "qwen3.6:latest"
  },
  "ollama": {
    "url": "$OllamaUrl",
    "timeout": 120
  },
  "spidernet": {
    "api_url": "$SpiderNetApiUrl",
    "coordination_endpoint": "/api/hermes/coordinate",
    "learning_sync_endpoint": "/api/hermes/learning-sync"
  },
  "capabilities": [
    "multi_agent_coordination",
    "workflow_orchestration",
    "external_integration",
    "learning_synchronization",
    "voice_mode",
    "api_webhook_handling"
  ],
  "platforms": [
    "telegram",
    "discord", 
    "slack",
    "whatsapp",
    "email",
    "sms",
    "voice",
    "api"
  ]
}
"@
$hermesConfig | Out-File -FilePath "hermes-config/hermes.json" -Encoding UTF8
Write-Host "  Configuration saved" -ForegroundColor Green

# Check for SpiderNetOS network
Write-Host "[5/6] Checking SpiderNetOS network..." -ForegroundColor Yellow
$networkExists = docker network ls --format '{{.Name}}' | Select-String -Pattern "spidernet"
if (!$networkExists) {
    Write-Host "  Creating spidernet network..." -ForegroundColor Yellow
    docker network create spidernet
}
Write-Host "  Network ready" -ForegroundColor Green

# Start Hermes services
Write-Host "[6/6] Starting Hermes Agent..." -ForegroundColor Yellow
docker compose -f docker-compose.hermes.yml up -d

if ($LASTEXITCODE -eq 0) {
    Write-Host "  Hermes Agent started" -ForegroundColor Green
} else {
    Write-Error "Failed to start Hermes services"
    exit 1
}

# Wait for services
Write-Host ""
Write-Host "Waiting for services to be ready..." -ForegroundColor Yellow
Start-Sleep -Seconds 5

# Health check
try {
    $health = Invoke-RestMethod -Uri "http://localhost:8090/health" -Method GET -TimeoutSec 5
    Write-Host "  Hermes health: $($health.status)" -ForegroundColor Green
} catch {
    Write-Warning "Health check failed - services may still be starting"
}

Write-Host ""
Write-Host "=== Setup Complete ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Hermes Agent is running with:" -ForegroundColor White
Write-Host "  API: http://localhost:8090" -ForegroundColor Gray
Write-Host "  WebSocket: ws://localhost:8092" -ForegroundColor Gray
Write-Host ""
Write-Host "Integration Points:" -ForegroundColor White
Write-Host "  SpiderNetOS API: $SpiderNetApiUrl" -ForegroundColor Gray
Write-Host "  Ollama Models: gemma4:31b, qwen3.6:latest" -ForegroundColor Gray
Write-Host ""
Write-Host "Test the integration:" -ForegroundColor White
Write-Host "  curl http://localhost:8090/health" -ForegroundColor Yellow
Write-Host "  curl -X POST http://localhost:8090/api/coordinate -H 'Content-Type: application/json' -d '{`"message`":`"Hello`"}'" -ForegroundColor Yellow
Write-Host ""
Write-Host "View logs: docker compose -f docker-compose.hermes.yml logs -f" -ForegroundColor Gray
