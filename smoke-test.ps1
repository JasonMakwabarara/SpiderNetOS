$apiBase = "http://localhost:8000"
$email = "admin@spidernetos.com"
$password = "Zukaarimoto01!"

Write-Host "=== SpiderNetOS Smoke Test ===" -ForegroundColor Magenta

# Health Check
Write-Host "[1/5] Health check..." -ForegroundColor Cyan
$health = Invoke-RestMethod -Uri "$apiBase/health" -ErrorAction Stop
if ($health.status -eq "healthy") { Write-Host "PASSED" -ForegroundColor Green } else { exit 1 }

# Login
Write-Host "[2/5] Login..." -ForegroundColor Cyan
$body = @{ email = $email; password = $password } | ConvertTo-Json
$login = Invoke-RestMethod -Uri "$apiBase/api/login" -Method Post -Body $body -ContentType "application/json" -ErrorAction Stop
$token = $login.token
Write-Host "PASSED" -ForegroundColor Green

# List Agents
Write-Host "[3/5] List agents..." -ForegroundColor Cyan
$headers = @{ Authorization = "Bearer $token" }
$agents = Invoke-RestMethod -Uri "$apiBase/api/agents" -Method Get -Headers $headers -ErrorAction Stop
Write-Host "PASSED (Found $($agents.Count) agents)" -ForegroundColor Green

# List Flows
Write-Host "[4/5] List flows..." -ForegroundColor Cyan
$flows = Invoke-RestMethod -Uri "$apiBase/api/flows" -Method Get -Headers $headers -ErrorAction Stop
Write-Host "PASSED (Found $($flows.Count) flows)" -ForegroundColor Green

# Atlas Intent
Write-Host "[5/5] Test Atlas intent..." -ForegroundColor Cyan
$intentBody = @{ message = "create agent SmokeTestBot" } | ConvertTo-Json
$intent = Invoke-RestMethod -Uri "$apiBase/api/atlas/intent" -Method Post -Body $intentBody -ContentType "application/json" -Headers $headers -ErrorAction Stop
Write-Host "PASSED" -ForegroundColor Green

Write-Host "`nALL TESTS PASSED!" -ForegroundColor Green
