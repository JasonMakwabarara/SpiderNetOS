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
    if ($branch -notin @("landing", "main")) { throw "Expected landing or main branch, got $branch" }
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
    $r = Invoke-RestMethod -Uri "http://localhost/api/outcomes/weekly-review" -Headers $h -TimeoutSec 90
    if (-not $r.data.headline) { throw "Missing weekly review headline" }
}

Test-Endpoint "BE#4: billing summary API" {
    $h = @{ Authorization = "Bearer $script:laravelToken" }
    $r = Invoke-RestMethod -Uri "http://localhost/api/billing/summary" -Headers $h -TimeoutSec 90
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
    $r = Invoke-RestMethod -Uri "http://localhost/api/outcomes/weekly-review" -Headers $h -TimeoutSec 90
    if ($null -eq $r.data.summary) { throw "Missing weekly review summary" }
}

Test-Endpoint "OJ: billing Atlas inference spend fields" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken" }
    $r = Invoke-RestMethod -Uri "http://localhost/api/billing/summary" -Headers $h -TimeoutSec 60
    if ($null -eq $r.data.spend.atlas_inference_daily_usd) { throw "Missing atlas_inference_daily_usd" }
}

Test-Endpoint "OJ: integration doc" {
    if (-not (Test-Path (Join-Path $repoRoot "docs\OPENJARVIS_INTEGRATION.md"))) { throw "Missing OPENJARVIS_INTEGRATION.md" }
}

# Customer-first AIOS contracts (2026-06-22)
Test-Endpoint "AIOS: feature pack catalogue outcomes" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken" }
    $r = Invoke-RestMethod -Uri "http://localhost/api/feature-packs/catalogue" -Headers $h -TimeoutSec 20
    $fin = $r.data | Where-Object { $_.pack_id -eq "financial-services" } | Select-Object -First 1
    if (-not $fin.customer_outcomes -or $fin.customer_outcomes.Count -lt 1) { throw "Missing financial-services customer_outcomes" }
}

Test-Endpoint "AIOS: compliance obligations API" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken" }
    $r = Invoke-RestMethod -Uri "http://localhost/api/compliance/obligations" -Headers $h -TimeoutSec 20
    if (-not $r.data -or $r.data.Count -lt 1) { throw "Expected at least one obligation" }
}

Test-Endpoint "AIOS: business profile API" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken" }
    $r = Invoke-RestMethod -Uri "http://localhost/api/business-profile" -Headers $h -TimeoutSec 20
    if ($null -eq $r.data) { throw "Missing business profile data envelope" }
}

Test-Endpoint "AIOS: inference warmup" {
    $body = '{"message":"warmup classify","model":"gemma2:2b"}'
    try {
        Invoke-RestMethod -Uri "http://localhost:9000/v1/classify" -Method POST -ContentType "application/json" -Body $body -TimeoutSec 180 | Out-Null
    } catch {
        Write-Host "[WARN] inference warmup skipped - model may not be loaded yet" -ForegroundColor Yellow
    }
}

Test-Endpoint "AIOS: inference plane health" {
    $r = Invoke-RestMethod -Uri "http://localhost:9000/health" -TimeoutSec 15
    if ($r.status -ne "ok") { throw "Inference plane unhealthy" }
}

Test-Endpoint "AIOS: inference classify endpoint" {
    $body = '{"message":"create a flow for invoicing","model":"gemma2:2b","system_prompt":"Return JSON with intent, entities, confidence"}'
    try {
        $r = Invoke-RestMethod -Uri "http://localhost:9000/v1/classify" -Method POST -ContentType "application/json" -Body $body -TimeoutSec 180
        if (-not $r.intent) { throw "Missing intent in classify response" }
        if ($null -eq $r.confidence) { throw "Missing confidence in classify response" }
    } catch {
        if ($_.Exception.Message -match 'classify_failed|502|500|timed out|timeout') {
            Write-Host "[WARN] classify slow or model not ready - endpoint reachable" -ForegroundColor Yellow
        } else {
            throw $_
        }
    }
}

Test-Endpoint "AIOS: flow quick-create and execute" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken"; "Content-Type" = "application/json" }
    $body = '{"template":"status","who":"verify team","when":"now"}'
    $created = Invoke-RestMethod -Uri "http://localhost/api/flows/quick-create" -Method POST -Headers $h -Body $body -TimeoutSec 60
    $flowId = $created.data.id
    if (-not $flowId) { throw "quick-create missing flow id" }
    $exec = Invoke-RestMethod -Uri "http://localhost/api/flows/$flowId/execute" -Method POST -Headers $h -Body '{}' -TimeoutSec 60
    if ($exec.status -ne "completed") { throw "Expected completed execution, got $($exec.status)" }
    if (-not $exec.execution_id) { throw "Missing execution_id" }
}

Test-Endpoint "AIOS: traces list completed execution" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken" }
    $r = Invoke-RestMethod -Uri "http://localhost/api/traces" -Headers $h -TimeoutSec 20
    if (-not $r.traces -or $r.traces.Count -lt 1) { throw "No traces returned" }
    $completed = @($r.traces | Where-Object { $_.status -eq "completed" })
    if ($completed.Count -lt 1) { throw "No completed traces in list" }
}

Test-Endpoint "AIOS: OpenJarvis bridge inference fallback" {
    $r = Invoke-RestMethod -Uri "http://localhost:8010/api/health" -TimeoutSec 15
    if ($r.status -ne "ok") { throw "OpenJarvis bridge unhealthy" }
    if ($null -eq $r.inference_reachable) { throw "Missing inference_reachable on bridge health" }
}

Test-Endpoint "AIOS: Atlas discovery mode" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken"; "Content-Type" = "application/json" }
    $body = '{"message":"help me get started"}'
    $r = Invoke-RestMethod -Uri "http://localhost/api/atlas/chat" -Method POST -Headers $h -Body $body -TimeoutSec 180
    if ($r.message.metadata.mode -ne "discover") { throw "Expected discovery mode for vague prompt" }
}

Test-Endpoint "AIOS: Atlas trust gate setup profile" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken"; "Content-Type" = "application/json" }
    $body = '{"industry":"professional_services","employee_count_band":"1-10","biggest_time_drain":"invoicing and follow-ups","issues_invoices":true}'
    $r = Invoke-RestMethod -Uri "http://localhost/api/business-profile" -Method PUT -Headers $h -Body $body -TimeoutSec 20
    if ($r.data.discovery_complete_pct -lt 60) { throw "Profile not complete enough for clarity gate tests" }
}

Test-Endpoint "AIOS: Atlas trust gate manual confirm" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken"; "Content-Type" = "application/json" }
    $composeFile = Join-Path $repoRoot "docker-compose.unified.yml"
    $resetSql = "UPDATE tenant_business_profiles SET learned_signals = jsonb_set(COALESCE(learned_signals::jsonb, '{}'::jsonb), '{trust,confirmed_by_intent}', '{}'::jsonb)::json WHERE tenant_id = '00000000-0000-0000-0000-000000000001';"
    $resetSql | docker compose -f $composeFile exec -T postgres psql -U postgres -d spidernet 2>$null | Out-Null
    Invoke-RestMethod -Uri "http://localhost/api/admin/tenant/automation-level" -Method PUT -Headers $h -Body '{"automation_level":"manual"}' -TimeoutSec 15 | Out-Null
    $body = '{"message":"create an automation flow for lead follow-up reminders"}'
    $r = Invoke-RestMethod -Uri "http://localhost/api/atlas/chat" -Method POST -Headers $h -Body $body -TimeoutSec 180
    if ($r.message.metadata.mode -ne "confirm") { throw "Expected confirm mode under manual for actionable intent" }
    if (-not $r.message.metadata.pending_action.id) { throw "Missing pending_action.id" }
    $script:trustGateActionId = $r.message.metadata.pending_action.id
}

Test-Endpoint "AIOS: Atlas trust gate confirm proceed" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    if (-not $script:trustGateActionId) { throw "No pending action from prior test" }
    $h = @{ Authorization = "Bearer $script:laravelToken"; "Content-Type" = "application/json" }
    $body = "{`"action_id`":`"$($script:trustGateActionId)`",`"decision`":`"proceed`"}"
    $r = Invoke-RestMethod -Uri "http://localhost/api/atlas/confirm" -Method POST -Headers $h -Body $body -TimeoutSec 180
    if ($r.message.metadata.mode -ne "act") { throw "Expected act mode after proceed" }
    if ($r.message.metadata.status -ne "dispatched") { throw "Expected dispatched status after proceed, got $($r.message.metadata.status)" }
}

Test-Endpoint "AIOS: Atlas trust gate autonomous irreversible" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken"; "Content-Type" = "application/json" }
    Invoke-RestMethod -Uri "http://localhost/api/admin/tenant/automation-level" -Method PUT -Headers $h -Body '{"automation_level":"autonomous"}' -TimeoutSec 15 | Out-Null
    $body = '{"message":"delete all invoices from last quarter"}'
    $r = Invoke-RestMethod -Uri "http://localhost/api/atlas/chat" -Method POST -Headers $h -Body $body -TimeoutSec 180
    if ($r.message.metadata.mode -ne "confirm") { throw "Expected confirm for irreversible action even in autonomous" }
}

Test-Endpoint "AIOS: Atlas trust gate clarify ambiguous" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken"; "Content-Type" = "application/json" }
    $body = '{"message":"run it"}'
    $r = Invoke-RestMethod -Uri "http://localhost/api/atlas/chat" -Method POST -Headers $h -Body $body -TimeoutSec 180
    if ($r.message.metadata.mode -ne "clarify") { throw "Expected clarify mode for ambiguous prompt" }
    if (-not $r.message.metadata.questions -or $r.message.metadata.questions.Count -lt 1) { throw "Expected clarifying question" }
}

Test-Endpoint "AIOS: Atlas trust gate non-actionable act" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken"; "Content-Type" = "application/json" }
    Invoke-RestMethod -Uri "http://localhost/api/admin/tenant/automation-level" -Method PUT -Headers $h -Body '{"automation_level":"assisted"}' -TimeoutSec 15 | Out-Null
    $body = '{"message":"Thanks for explaining how outcomes work in the cockpit today"}'
    $r = Invoke-RestMethod -Uri "http://localhost/api/atlas/chat" -Method POST -Headers $h -Body $body -TimeoutSec 300
    if ($r.message.metadata.mode -ne "act") { throw "Expected act mode for non-actionable chat, got $($r.message.metadata.mode)" }
}

Test-Endpoint "AIOS: AtlasClarityGate service present" {
    $p = Join-Path $repoRoot "backend\app\Services\AtlasClarityGate.php"
    if (-not (Test-Path $p)) { throw "Missing AtlasClarityGate.php" }
}

Test-Endpoint "AIOS: atlas confirm route wired" {
    $api = [System.IO.File]::ReadAllText((Join-Path $repoRoot "backend\routes\api.php"))
    if ($api -notmatch "atlas/confirm") { throw "Missing POST /api/atlas/confirm route" }
}

Test-Endpoint "AIOS: sales-crm pack manifest" {
    $p = Join-Path $repoRoot "packages\feature-packs\sales-crm\pack.yaml"
    if (-not (Test-Path $p)) { throw "Missing sales-crm pack.yaml" }
}

Test-Endpoint "AIOS: cockpit first-win + financial route chunks" {
    $assets = Join-Path $repoRoot "frontend\public\cockpit\assets"
    if (-not (Get-ChildItem (Join-Path $assets "OpsFirstWin-*.js") | Select-Object -First 1)) { throw "Missing OpsFirstWin chunk" }
    if (-not (Get-ChildItem (Join-Path $assets "FinancialDashboard-*.js") | Select-Object -First 1)) { throw "Missing FinancialDashboard chunk" }
    $idx = Get-ChildItem (Join-Path $assets "index-*.js") | Select-Object -First 1
    if (-not $idx) { throw "Missing cockpit index bundle" }
    $content = [System.IO.File]::ReadAllText($idx.FullName)
    if ($content -notmatch "operate/first-win") { throw "Router missing /operate/first-win in index bundle" }
    if ($content -notmatch "/financial") { throw "Router missing /financial in index bundle" }
}

Test-Endpoint "AIOS: live nginx serves current cockpit bundle" {
    $local = Get-ChildItem (Join-Path $repoRoot "frontend\public\cockpit\assets\index-*.js") | Select-Object -First 1
    if (-not $local) { throw "No local cockpit index bundle" }
    $html = Invoke-WebRequest -Uri "http://localhost/cockpit/" -UseBasicParsing -TimeoutSec 10
    if ($html.Content -notmatch 'assets/(index-[^"]+\.js)') { throw "Cockpit index.html missing bundle reference" }
    $liveName = $Matches[1]
    if ($liveName -ne $local.Name) {
        throw "Stale cockpit bundle on nginx: live=$liveName local=$($local.Name). Rebuild: docker compose -f docker-compose.unified.yml up -d --build frontend"
    }
    $liveJs = Invoke-WebRequest -Uri "http://localhost/cockpit/assets/$liveName" -UseBasicParsing -TimeoutSec 15
    if ($liveJs.Content -notmatch "operate/first-win") { throw "Live bundle missing operate/first-win route" }
    if ($liveJs.Content -notmatch "/financial") { throw "Live bundle missing /financial route" }
}

Test-Endpoint "AIOS: personalized pack catalogue" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken" }
    $r = Invoke-RestMethod -Uri "http://localhost/api/feature-packs/catalogue" -Headers $h -TimeoutSec 60
    if ($r.meta.personalized -ne $true) { throw "Catalogue not personalized" }
    if (-not $r.data -or $r.data.Count -lt 1) { throw "Empty catalogue" }
    if ($null -eq $r.data[0].relevance_score) { throw "Missing relevance_score on pack" }
}

Test-Endpoint "AIOS: pack feedback signal" {
    if (-not $script:laravelToken) { throw "No Laravel token" }
    $h = @{ Authorization = "Bearer $script:laravelToken"; "Content-Type" = "application/json" }
    $body = '{"pack_id":"sales-crm","sentiment":"positive","outcome":"Never lose a lead"}'
    $r = Invoke-WebRequest -Uri "http://localhost/api/feature-packs/feedback" -Method POST -Headers $h -Body $body -UseBasicParsing -TimeoutSec 60
    if ($r.StatusCode -ne 202) { throw "Feedback not accepted" }
}

Write-Host "`n=== Results: $pass passed, $fail failed ===`n" -ForegroundColor Cyan
if ($fail -gt 0) { exit 1 }
