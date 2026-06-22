# SpiderNetOS unified stack verification
$ErrorActionPreference = "Stop"
$pass = 0
$fail = 0
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path

function Test-Endpoint($name, $scriptBlock) {
    try {
        & $scriptBlock
        Write-Host "[PASS] $name" -ForegroundColor Green
        $script:pass++
    } catch {
        Write-Host "[FAIL] $name - $($_.Exception.Message)" -ForegroundColor Red
        $script:fail++
    }
}

Write-Host "`n=== SpiderNetOS Unified Verification ===`n" -ForegroundColor Cyan

# Objective 1: Landing branch + frontend structure
Test-Endpoint "Obj1: landing branch" {
    $branch = git -C $repoRoot branch --show-current
    if ($branch -ne "landing") { throw "Expected landing branch, got $branch" }
    foreach ($f in @("frontend/src/pages/LandingPage.jsx","frontend/src/pages/SignInPage.jsx","frontend/src/pages/RegisterWizard.jsx","frontend/src/components/MarketingHeader.jsx")) {
        if (-not (Test-Path (Join-Path $repoRoot $f))) { throw "Missing $f" }
    }
}

# Objective 2: App routes wired
Test-Endpoint "Obj2: App.js routes" {
    $appPath = Join-Path $repoRoot "frontend\src\App.js"
    $app = [System.IO.File]::ReadAllText($appPath)
    foreach ($r in @('path="/"', 'path="/sign-in"', 'path="/enterprise/register"', 'path="/cockpit/*"', 'CockpitRedirect')) {
        if ($app -notmatch [regex]::Escape($r)) { throw "Route missing: $r" }
    }
}

# Objective 3: Docker + Traefik config
Test-Endpoint "Obj3: docker-compose.unified.yml" {
    $yml = [System.IO.File]::ReadAllText((Join-Path $repoRoot "docker-compose.unified.yml"))
    foreach ($s in @("frontend:", "cockpit-api:", "semantic-gateway:", "atlas-perception:")) {
        if ($yml -notmatch $s) { throw "Service missing: $s" }
    }
    $traefik = [System.IO.File]::ReadAllText((Join-Path $repoRoot "traefik\dynamic.yml"))
    if ($traefik -notmatch "landing:" -or $traefik -notmatch "frontend:") { throw "Traefik landing route missing" }
}

# Objective 5: IntelligenceGateway + V2 services
Test-Endpoint "Obj5: IntelligenceGateway + V2 services" {
    if (-not (Test-Path (Join-Path $repoRoot "backend\app\Services\IntelligenceGateway.php"))) { throw "IntelligenceGateway.php missing" }
    foreach ($svc in @("atlas-perception","dag-compiler","atlas-rl","runtime-guardian","semantic-gateway")) {
        if (-not (Test-Path (Join-Path $repoRoot "services\$svc\main.py"))) { throw "V2 service missing: $svc" }
    }
}

# Live API tests
Test-Endpoint "API: cockpit-api health" {
    $r = Invoke-RestMethod -Uri "http://localhost:8001/api/health" -TimeoutSec 10
    if ($r.status -ne "ok") { throw "Unexpected health: $($r | ConvertTo-Json -Compress)" }
}

Test-Endpoint "API: V2 semantic gateway health" {
    $r = Invoke-RestMethod -Uri "http://localhost:8005/api/health" -TimeoutSec 10
    if ($r.status -ne "ok") { throw "Gateway unhealthy" }
}

Test-Endpoint "Obj4: admin password login (enterprise)" {
    $body = '{"email":"admin@spidernetos.com","password":"Zukaarimoto01!"}'
    $r = Invoke-RestMethod -Uri "http://localhost:8001/api/enterprise/auth/password/login" -Method POST -ContentType "application/json" -Body $body -TimeoutSec 15
    if (-not $r.access_token) { throw "No access_token returned" }
    if ($r.user.email -ne "admin@spidernetos.com") { throw "Wrong user email" }
}

Test-Endpoint "Obj4: admin password login (cockpit)" {
    $body = '{"email":"admin@spidernetos.com","password":"Zukaarimoto01!"}'
    $r = Invoke-RestMethod -Uri "http://localhost:8001/api/auth/login" -Method POST -ContentType "application/json" -Body $body -TimeoutSec 15
    if (-not $r.token) { throw "No token returned" }
    if ($r.user.role -ne "super_admin") { throw "Expected super_admin" }
}

Test-Endpoint "API: wrong password rejected" {
    try {
        Invoke-RestMethod -Uri "http://localhost:8001/api/enterprise/auth/password/login" -Method POST -ContentType "application/json" -Body '{"email":"admin@spidernetos.com","password":"wrong"}' -TimeoutSec 10
        throw "Should have returned 401"
    } catch {
        if ($_.Exception.Response.StatusCode.value__ -ne 401) { throw $_ }
    }
}

Test-Endpoint "API: V2 intelligence evaluate" {
    $uri = "http://localhost:8005/v2/gateway/evaluate?workspace_id=00000000-0000-0000-0000-000000000001&event_payload=verify"
    $r = Invoke-RestMethod -Uri $uri -Method POST -TimeoutSec 15
    if (-not $r.route) { throw "Missing route in evaluate response" }
}

# Production nginx (:80) + Laravel business API
$creds = '{"email":"admin@spidernetos.com","password":"Zukaarimoto01!"}'
$script:laravelToken = $null
$script:laravelSession = $null

Test-Endpoint "Nginx :80 landing + sign-in + cockpit" {
    foreach ($url in @("http://localhost/", "http://localhost/sign-in", "http://localhost/cockpit/")) {
        $resp = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 15
        if ($resp.StatusCode -ne 200) { throw "$url returned $($resp.StatusCode)" }
    }
}

Test-Endpoint "Nginx: Laravel auth + enterprise auth" {
    $ent = Invoke-RestMethod -Uri "http://localhost/api/enterprise/auth/password/login" -Method POST -ContentType "application/json" -Body $creds -TimeoutSec 20
    if (-not $ent.access_token) { throw "No enterprise token via nginx" }
    $lar = Invoke-RestMethod -Uri "http://localhost/api/auth/login" -Method POST -ContentType "application/json" -Body $creds -TimeoutSec 20
    if (-not $lar.token) { throw "No Laravel token via nginx" }
    $script:laravelToken = $lar.token
    $script:laravelSession = $lar
}

Test-Endpoint "Nginx: Laravel intelligence evaluate" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken"; "Content-Type" = "application/json" }
    $body = '{"event_payload":{"type":"verify","source":"verify-unified.ps1"},"workspace_id":"00000000-0000-0000-0000-000000000001"}'
    $r = Invoke-RestMethod -Uri "http://localhost/api/v2/intelligence/evaluate" -Method POST -Headers $h -Body $body -TimeoutSec 30
    if ($r.ok -ne $true -or -not $r.route) { throw "Bad evaluate: $($r | ConvertTo-Json -Compress)" }
}

Test-Endpoint "Nginx: command dispatch + agents + platform" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken"; "Content-Type" = "application/json" }
    $cmd = Invoke-RestMethod -Uri "http://localhost/api/command" -Method POST -Headers $h -Body '{"command":"System verification ping","context":{}}' -TimeoutSec 45
    if (-not $cmd.status) { throw "Command missing status" }
    $agents = Invoke-RestMethod -Uri "http://localhost/api/agents" -Headers @{ Authorization = "Bearer $script:laravelToken" } -TimeoutSec 20
    if ($null -eq $agents) { throw "Empty agents list" }
    $overview = Invoke-RestMethod -Uri "http://localhost/api/platform/overview" -Headers @{ Authorization = "Bearer $script:laravelToken" } -TimeoutSec 20
    if (-not $overview.tenants) { throw "Platform overview missing tenants" }
}

Test-Endpoint "Cockpit bundle sign-in redirect" {
    $js = Get-ChildItem (Join-Path $repoRoot "frontend\public\cockpit\assets\index-*.js") | Select-Object -First 1
    if (-not $js) { throw "Missing cockpit bundle" }
    $content = [System.IO.File]::ReadAllText($js.FullName)
    if ($content -notmatch "/sign-in") { throw "Cockpit bundle missing /sign-in redirect" }
}

# Objective 6: Frontend HTTP (prod nginx or dev server)
Test-Endpoint "Obj6: landing frontend HTTP" {
    $ports = @(80, 3010, 3000)
    $ok = $false
    foreach ($p in $ports) {
        try {
            $resp = Invoke-WebRequest -Uri "http://localhost:$p" -UseBasicParsing -TimeoutSec 5
            if ($resp.StatusCode -eq 200) { $ok = $true; break }
        } catch {}
    }
    if (-not $ok) { throw "Landing not reachable on ports 80, 3010, or 3000" }
}

# Laravel API health
Test-Endpoint "API: Laravel health" {
    $r = Invoke-RestMethod -Uri "http://localhost:8000/api/health" -TimeoutSec 10
    if ($r.status -notin @("healthy", "operational")) { throw "Laravel unhealthy: $($r | ConvertTo-Json -Compress)" }
}

# Business Engineering improvements (#1-#7)
Test-Endpoint "BE#1: unified login session shape" {
    $lar = $script:laravelSession
    if (-not $lar) { throw "No login session from prior test" }
    if (-not $lar.access_token -and -not $lar.token) { throw "Missing token" }
    if (-not $lar.user.email) { throw "Missing user" }
    if (-not $lar.tenant.id) { throw "Missing tenant in unified session" }
}

Test-Endpoint "BE#2: outcomes weekly review API" {
    $h = @{ Authorization = "Bearer $script:laravelToken" }
    $r = Invoke-RestMethod -Uri "http://localhost/api/outcomes/weekly-review" -Headers $h -TimeoutSec 20
    if (-not $r.data.headline) { throw "Missing weekly review headline" }
}

Test-Endpoint "BE#4: billing summary API" {
    $h = @{ Authorization = "Bearer $script:laravelToken" }
    $r = Invoke-RestMethod -Uri "http://localhost/api/billing/summary" -Headers $h -TimeoutSec 20
    if (-not $r.data.plan) { throw "Missing billing plan" }
}

Test-Endpoint "BE#5: feature pack catalogue" {
    $h = @{ Authorization = "Bearer $script:laravelToken" }
    $r = Invoke-RestMethod -Uri "http://localhost/api/feature-packs/catalogue" -Headers $h -TimeoutSec 20
    if (-not $r.data -or $r.data.Count -lt 1) { throw "Catalogue empty" }
}

Test-Endpoint "BE#6: setupProxy mirrors nginx (file check)" {
    $proxy = [System.IO.File]::ReadAllText((Join-Path $repoRoot "frontend\src\setupProxy.js"))
    if ($proxy -notmatch "LARAVEL_PROXY_TARGET" -or $proxy -notmatch "v2/intelligence") { throw "setupProxy not aligned" }
}

Test-Endpoint "BE#3: canonical stack doc" {
    if (-not (Test-Path (Join-Path $repoRoot "docs\CANONICAL_STACK.md"))) { throw "Missing CANONICAL_STACK.md" }
}

Test-Endpoint "BE#7: communications module in cockpit bundle" {
    $js = Get-ChildItem (Join-Path $repoRoot "frontend\public\cockpit\assets\Communications-*.js") | Select-Object -First 1
    if (-not $js) { throw "Missing Communications chunk in cockpit bundle" }
}

# OpenJarvis × Atlas business engineering (background servant — not user-facing)
Test-Endpoint "OJ: bridge health (internal)" {
    $r = Invoke-RestMethod -Uri "http://localhost:8010/api/health" -TimeoutSec 10
    if ($r.status -ne "ok") { throw "OpenJarvis bridge unhealthy" }
    if ($r.skills_loaded -lt 3) { throw "Expected vertical + core skills" }
}

Test-Endpoint "OJ: weekly review Atlas briefing (Growth+)" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken" }
    $r = Invoke-RestMethod -Uri "http://localhost/api/outcomes/weekly-review" -Headers $h -TimeoutSec 45
    if ($null -eq $r.data.summary) { throw "Missing weekly review summary" }
}

Test-Endpoint "OJ: billing Atlas inference spend fields" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken" }
    $r = Invoke-RestMethod -Uri "http://localhost/api/billing/summary" -Headers $h -TimeoutSec 15
    if ($null -eq $r.data.spend.atlas_inference_daily_usd) { throw "Missing atlas_inference_daily_usd" }
}

Test-Endpoint "OJ: integration doc" {
    if (-not (Test-Path (Join-Path $repoRoot "docs\OPENJARVIS_INTEGRATION.md"))) { throw "Missing OPENJARVIS_INTEGRATION.md" }
}

Write-Host "`n=== Results: $pass passed, $fail failed ===`n" -ForegroundColor Cyan
if ($fail -gt 0) { exit 1 }
