#!/usr/bin/env pwsh
# SQLite AI Training Tables Migration Script
# Run this to set up only the AI training tables in SQLite (skips pgvector-dependent tables)

$ErrorActionPreference = "Stop"
$backendPath = Join-Path (Join-Path $PSScriptRoot "..") "backend"
$envFile = Join-Path $backendPath ".env"
$envSqliteFile = Join-Path $backendPath ".env.sqlite"
$databasePath = Join-Path (Join-Path $backendPath "database") "database.sqlite"
$phpScript = Join-Path (Join-Path $backendPath "scripts") "migrate-ai-training-sqlite.php"

Write-Host "=== SQLite AI Training Tables Migration ===" -ForegroundColor Cyan

# Ensure .env.sqlite exists and copy to .env
if (-not (Test-Path $envSqliteFile)) {
    Write-Error ".env.sqlite not found at $envSqliteFile"
    exit 1
}

Copy-Item $envSqliteFile $envFile -Force
Write-Host "Copied .env.sqlite to .env" -ForegroundColor Green

# Ensure SQLite database exists
if (-not (Test-Path $databasePath)) {
    New-Item -ItemType File -Path $databasePath -Force | Out-Null
    Write-Host "Created database.sqlite" -ForegroundColor Green
}

Set-Location $backendPath

# Run the PHP migration script
Write-Host "Running AI training tables migration..." -ForegroundColor Yellow

if (-not (Test-Path $phpScript)) {
    Write-Error "Migration script not found at $phpScript"
    exit 1
}

& php $phpScript 2>&1
if ($LASTEXITCODE -eq 0) {
    Write-Host "`nMigration completed successfully!" -ForegroundColor Green
} else {
    Write-Error "Migration failed"
    exit 1
}
