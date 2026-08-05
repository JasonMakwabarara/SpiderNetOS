# Start Hermes Bridge
cd "C:\Users\HP\CascadeProjects\windsurf-project-2\hermes-runtime"
$env:OLLAMA_URL = "http://127.0.0.1:11434"
$env:SPIDERNET_API_URL = "http://127.0.0.1:8000"
$env:REDIS_URL = "redis://127.0.0.1:6379/2"

Write-Host "Starting Hermes API on port 8090..." -ForegroundColor Green
Start-Process python -ArgumentList "hermes_local_bridge.py" -WindowStyle Normal

Start-Sleep -Seconds 3

Write-Host "Starting Hermes Worker..." -ForegroundColor Green
Start-Process python -ArgumentList "hermes_worker.py" -WindowStyle Normal

Write-Host ""
Write-Host "Hermes deployed! Test with:" -ForegroundColor Cyan
Write-Host "  curl http://localhost:8090/health" -ForegroundColor Yellow
