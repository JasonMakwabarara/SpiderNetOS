#!/usr/bin/env pwsh
# Start SpiderNetOS MCP Server
# This script builds and launches the MCP server for AI integration

$ErrorActionPreference = "Stop"
$serverPath = Join-Path $PSScriptRoot ".." "services" "mcp-server"

Write-Host "=== SpiderNetOS MCP Server ===" -ForegroundColor Cyan

# Check if node_modules exists
if (-not (Test-Path (Join-Path $serverPath "node_modules"))) {
    Write-Host "Installing dependencies..." -ForegroundColor Yellow
    Set-Location $serverPath
    npm install
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Failed to install dependencies"
        exit 1
    }
}

# Build if needed
if (-not (Test-Path (Join-Path $serverPath "dist" "server.js"))) {
    Write-Host "Building MCP server..." -ForegroundColor Yellow
    Set-Location $serverPath
    npm run build
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Build failed"
        exit 1
    }
}

# Start the server
Write-Host "Starting MCP server..." -ForegroundColor Green
$serverJs = Join-Path $serverPath "dist" "server.js"
& node $serverJs
