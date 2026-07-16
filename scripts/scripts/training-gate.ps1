param(
    [Parameter(Mandatory = $true)]
    [string]$InputPath,

    [string]$OutDir = "training_data/atlas",
    [int]$MinQuality = 2,
    [int]$MinQualitySft = 2,
    [int]$MinSftRows = 20,
    [int]$MinPreferenceRows = 20,
    [double]$MinDistinctPreferenceRatio = 0.95,
    [string]$AtlasApplyDir = "intelligence/atlas/training/current",
    [switch]$NoAutoApply
)

$ErrorActionPreference = "Stop"

function Resolve-WorkspacePath([string]$PathValue) {
    if ([System.IO.Path]::IsPathRooted($PathValue)) {
        return $PathValue
    }
    return (Join-Path (Get-Location) $PathValue)
}

$inputFile = Resolve-WorkspacePath $InputPath
if (-not (Test-Path $inputFile)) {
    throw "Input markdown file not found: $inputFile"
}

$resolvedOutDir = Resolve-WorkspacePath $OutDir
$resolvedAtlasApplyDir = Resolve-WorkspacePath $AtlasApplyDir

Write-Host "Running training conversion..."
python "scripts/convert_cursor_markdown_to_jsonl.py" `
    --input "$inputFile" `
    --out-dir "$resolvedOutDir" `
    --min-quality $MinQuality `
    --min-quality-sft $MinQualitySft `
    --quality-report

Write-Host "Running quality gate..."
python "scripts/eval_training_data_quality.py" `
    --sft (Join-Path $resolvedOutDir "sft.jsonl") `
    --preference (Join-Path $resolvedOutDir "preference.jsonl") `
    --min-quality $MinQuality `
    --min-sft-rows $MinSftRows `
    --min-preference-rows $MinPreferenceRows `
    --min-distinct-preference-ratio $MinDistinctPreferenceRatio

if (-not $NoAutoApply) {
    Write-Host "Applying gated bundle to Atlas training path..."
    New-Item -ItemType Directory -Path $resolvedAtlasApplyDir -Force | Out-Null

    Copy-Item (Join-Path $resolvedOutDir "sft.jsonl") (Join-Path $resolvedAtlasApplyDir "sft.jsonl") -Force
    Copy-Item (Join-Path $resolvedOutDir "preference.jsonl") (Join-Path $resolvedAtlasApplyDir "preference.jsonl") -Force
    Copy-Item (Join-Path $resolvedOutDir "quality_gate.json") (Join-Path $resolvedAtlasApplyDir "quality_gate.json") -Force

    if (Test-Path (Join-Path $resolvedOutDir "quality_report.json")) {
        Copy-Item (Join-Path $resolvedOutDir "quality_report.json") (Join-Path $resolvedAtlasApplyDir "quality_report.json") -Force
    }
}

Write-Host "Training gate complete."
Write-Host "Bundle directory: $resolvedOutDir"
if (-not $NoAutoApply) {
    Write-Host "Atlas applied directory: $resolvedAtlasApplyDir"
}
