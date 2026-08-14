<#
.SYNOPSIS
    Starts every clipper-tools process in its own PowerShell window: Redis,
    Laravel API, queue worker, Next.js frontend, and (if enabled) the
    self-hosted whisper-engine transcription service.

.PARAMETER WhisperEngine
    Force-start the whisper-engine window even if AI_TRANSCRIPTION_PROVIDER
    in backend\.env isn't "whisper_engine".

.PARAMETER NoWhisperEngine
    Force-skip the whisper-engine window even if AI_TRANSCRIPTION_PROVIDER
    in backend\.env is "whisper_engine".

.EXAMPLE
    .\start-all.ps1
    .\start-all.ps1 -WhisperEngine
#>
param(
    [switch]$WhisperEngine,
    [switch]$NoWhisperEngine
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

function Start-Service {
    param([string]$Title, [string]$WorkDir, [string]$Command)
    Start-Process powershell -ArgumentList @(
        '-NoExit', '-Command',
        "`$Host.UI.RawUI.WindowTitle = '$Title'; Set-Location '$WorkDir'; $Command"
    )
}

# --- decide whether whisper-engine should be started ---
$startWhisper = $false
if ($WhisperEngine) {
    $startWhisper = $true
} elseif (-not $NoWhisperEngine) {
    $envPath = Join-Path $root 'backend\.env'
    if (Test-Path $envPath) {
        $line = Select-String -Path $envPath -Pattern '^AI_TRANSCRIPTION_PROVIDER=' -ErrorAction SilentlyContinue
        if ($line -and ($line.Line -replace '^AI_TRANSCRIPTION_PROVIDER=', '').Trim() -eq 'whisper_engine') {
            $startWhisper = $true
        }
    }
}

Write-Host "Starting clipper-tools..." -ForegroundColor Cyan

# 1. Redis
Start-Service -Title 'clipper: redis' `
    -WorkDir (Join-Path $root 'tools\redis') `
    -Command '.\redis-server.exe redis.conf'

# 2. Laravel API
Start-Service -Title 'clipper: laravel api' `
    -WorkDir (Join-Path $root 'backend') `
    -Command 'php artisan serve --host=127.0.0.1 --port=8000'

# 3. Queue worker
Start-Service -Title 'clipper: queue worker' `
    -WorkDir (Join-Path $root 'backend') `
    -Command 'php artisan queue:work redis --queue=default'

# 4. Next.js frontend
Start-Service -Title 'clipper: frontend' `
    -WorkDir (Join-Path $root 'frontend') `
    -Command 'npm run dev'

# 5. Whisper engine (optional)
if ($startWhisper) {
    $venvPython = Join-Path $root 'tools\whisper-engine\venv\Scripts\python.exe'
    if (-not (Test-Path $venvPython)) {
        Write-Host "Skipping whisper-engine: venv not found at $venvPython (see tools\whisper-engine\README.md)" -ForegroundColor Yellow
    } else {
        Start-Service -Title 'clipper: whisper engine' `
            -WorkDir (Join-Path $root 'tools\whisper-engine') `
            -Command '.\venv\Scripts\python.exe main.py'
    }
} else {
    Write-Host "Skipping whisper-engine (AI_TRANSCRIPTION_PROVIDER isn't whisper_engine; pass -WhisperEngine to force it)" -ForegroundColor DarkGray
}

Write-Host ""
Write-Host "5 windows opened (or 4 if whisper-engine was skipped). Close a window to stop that process." -ForegroundColor Cyan
Write-Host "Frontend:  http://localhost:3000"
Write-Host "API:       http://localhost:8000"
if ($startWhisper) {
    Write-Host "Whisper:   http://127.0.0.1:8100/health"
}
