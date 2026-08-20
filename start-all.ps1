<#
.SYNOPSIS
    Starts every clipper-tools process in its own PowerShell window: Redis,
    Laravel API, queue worker, batch downloads worker, scheduler, Next.js
    frontend, and (if enabled) the self-hosted whisper-engine transcription,
    face-tracker reframing, and instagram-automation services.

.PARAMETER WhisperEngine
    Force-start the whisper-engine window even if AI_TRANSCRIPTION_PROVIDER
    in backend\.env isn't "whisper_engine".

.PARAMETER NoWhisperEngine
    Force-skip the whisper-engine window even if AI_TRANSCRIPTION_PROVIDER
    in backend\.env is "whisper_engine".

.PARAMETER FaceTracker
    Force-start the face-tracker window even if AI_REFRAMING_PROVIDER
    in backend\.env isn't "face_tracker".

.PARAMETER NoFaceTracker
    Force-skip the face-tracker window even if AI_REFRAMING_PROVIDER
    in backend\.env is "face_tracker".

.PARAMETER InstagramAutomation
    Force-start the instagram-automation window even if node_modules isn't
    installed there yet (it will fail to start until you run `npm install`).

.PARAMETER NoInstagramAutomation
    Force-skip the instagram-automation window even though node_modules is
    installed. Only needed if you don't plan to connect/publish an Instagram
    account this session — there's no mock fallback for it like the AI providers.

.EXAMPLE
    .\start-all.ps1
    .\start-all.ps1 -WhisperEngine
    .\start-all.ps1 -NoInstagramAutomation
#>
param(
    [switch]$WhisperEngine,
    [switch]$NoWhisperEngine,
    [switch]$FaceTracker,
    [switch]$NoFaceTracker,
    [switch]$InstagramAutomation,
    [switch]$NoInstagramAutomation
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

# --- decide whether face-tracker should be started ---
$startFaceTracker = $false
if ($FaceTracker) {
    $startFaceTracker = $true
} elseif (-not $NoFaceTracker) {
    $envPath = Join-Path $root 'backend\.env'
    if (Test-Path $envPath) {
        $line = Select-String -Path $envPath -Pattern '^AI_REFRAMING_PROVIDER=' -ErrorAction SilentlyContinue
        if ($line -and ($line.Line -replace '^AI_REFRAMING_PROVIDER=', '').Trim() -eq 'face_tracker') {
            $startFaceTracker = $true
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
    -Command 'php artisan serve --host=0.0.0.0 --port=8000'

# 3. Queue worker
Start-Service -Title 'clipper: queue worker' `
    -WorkDir (Join-Path $root 'backend') `
    -Command 'php artisan queue:work redis --queue=default'

# 3a. Batch downloads worker — dedicated queue for ProcessVideoBatchJob (the batch
# autobot's downloader). Kept separate from the 'default' worker above so a batch's
# downloads can run at the same time as that batch's own items being processed
# (transcribe/analyze/render/publish, still on 'default') instead of blocking
# behind them — see ProcessVideoBatchJob's docblock. Required, not optional: without
# it, a created batch just sits there since nothing ever downloads its videos.
Start-Service -Title 'clipper: batch downloads' `
    -WorkDir (Join-Path $root 'backend') `
    -Command 'php artisan queue:work redis --queue=batch-downloads'

# 3b. Scheduler — runs App\Console\Commands\ReapStalledProcessingJobs every 5min
# (see bootstrap/app.php withSchedule) to auto-fail ProcessingJob rows stuck at
# "running" because their worker died (crash, PC restart) without ever getting to
# mark them failed itself.
Start-Service -Title 'clipper: scheduler' `
    -WorkDir (Join-Path $root 'backend') `
    -Command 'php artisan schedule:work'

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

# 6. Face tracker (optional)
if ($startFaceTracker) {
    $venvPython = Join-Path $root 'tools\face-tracker\venv\Scripts\python.exe'
    if (-not (Test-Path $venvPython)) {
        Write-Host "Skipping face-tracker: venv not found at $venvPython (see tools\face-tracker\README.md)" -ForegroundColor Yellow
    } else {
        Start-Service -Title 'clipper: face tracker' `
            -WorkDir (Join-Path $root 'tools\face-tracker') `
            -Command '.\venv\Scripts\python.exe main.py'
    }
} else {
    Write-Host "Skipping face-tracker (AI_REFRAMING_PROVIDER isn't face_tracker; pass -FaceTracker to force it)" -ForegroundColor DarkGray
}

# --- decide whether instagram-automation should be started ---
# No env-based "mode" toggle for this one (unlike the AI providers) — Instagram
# connect/publish always calls it, so default to on whenever it looks installed.
$startInstagram = $false
if ($InstagramAutomation) {
    $startInstagram = $true
} elseif (-not $NoInstagramAutomation) {
    $igNodeModules = Join-Path $root 'tools\instagram-automation\node_modules'
    if (Test-Path $igNodeModules) {
        $startInstagram = $true
    }
}

# 7. Instagram automation (optional)
if ($startInstagram) {
    Start-Service -Title 'clipper: instagram automation' `
        -WorkDir (Join-Path $root 'tools\instagram-automation') `
        -Command 'npm start'
} else {
    Write-Host "Skipping instagram-automation: node_modules not found (run 'npm install' in tools\instagram-automation, or pass -InstagramAutomation to force) - only needed to connect/publish Instagram accounts" -ForegroundColor DarkGray
}

Write-Host ""
Write-Host "Windows opened (optional services included only if enabled). Close a window to stop that process." -ForegroundColor Cyan
Write-Host "Frontend:  http://localhost:3000"
Write-Host "API:       http://localhost:8000"
if ($startWhisper) {
    Write-Host "Whisper:   http://127.0.0.1:8100/health"
}
if ($startFaceTracker) {
    Write-Host "Face tracker: http://127.0.0.1:8200/health"
}
if ($startInstagram) {
    Write-Host "Instagram automation: http://127.0.0.1:8300/health"
}
