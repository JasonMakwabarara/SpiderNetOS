# Canonical SpiderNetOS v3 stack
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root
docker compose -f docker-compose.unified.yml up -d --build @args
Write-Host "`nSpiderNetOS v3 running at http://localhost" -ForegroundColor Cyan
Write-Host "Verify: powershell -ExecutionPolicy Bypass -File scripts\verify-unified.ps1`n"
