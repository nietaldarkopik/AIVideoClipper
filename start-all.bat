@echo off
setlocal enabledelayedexpansion

rem Starts every clipper-tools process in its own cmd window: Redis, Laravel API,
rem queue worker, batch downloads worker, scheduler, Next.js frontend, and (if
rem enabled) the self-hosted whisper-engine transcription, face-tracker reframing,
rem and instagram-automation services.
rem
rem This is the cmd.exe port of start-all.ps1 for people who don't want to run
rem PowerShell scripts. Behavior mirrors it 1:1, including the .env auto-detection.
rem
rem Usage:
rem   start-all.bat
rem   start-all.bat -WhisperEngine             (force-start whisper-engine)
rem   start-all.bat -NoWhisperEngine           (force-skip whisper-engine)
rem   start-all.bat -FaceTracker               (force-start face-tracker)
rem   start-all.bat -NoFaceTracker             (force-skip face-tracker)
rem   start-all.bat -InstagramAutomation       (force-start instagram-automation)
rem   start-all.bat -NoInstagramAutomation     (force-skip instagram-automation)

set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"

set "FORCE_WHISPER="
set "FORCE_NOWHISPER="
set "FORCE_FACETRACKER="
set "FORCE_NOFACETRACKER="
set "FORCE_INSTAGRAM="
set "FORCE_NOINSTAGRAM="

:parse_args
if "%~1"=="" goto args_done
if /i "%~1"=="-WhisperEngine" set "FORCE_WHISPER=1"
if /i "%~1"=="-NoWhisperEngine" set "FORCE_NOWHISPER=1"
if /i "%~1"=="-FaceTracker" set "FORCE_FACETRACKER=1"
if /i "%~1"=="-NoFaceTracker" set "FORCE_NOFACETRACKER=1"
if /i "%~1"=="-InstagramAutomation" set "FORCE_INSTAGRAM=1"
if /i "%~1"=="-NoInstagramAutomation" set "FORCE_NOINSTAGRAM=1"
shift
goto parse_args
:args_done

set "ENV_FILE=%ROOT%\backend\.env"

rem --- decide whether whisper-engine should be started ---
set "START_WHISPER=0"
if defined FORCE_WHISPER (
    set "START_WHISPER=1"
) else (
    if not defined FORCE_NOWHISPER (
        if exist "%ENV_FILE%" (
            set "TRANSCRIPTION_PROVIDER="
            for /f "usebackq tokens=1,* delims==" %%A in (`findstr /b "AI_TRANSCRIPTION_PROVIDER=" "%ENV_FILE%"`) do set "TRANSCRIPTION_PROVIDER=%%B"
            if /i "!TRANSCRIPTION_PROVIDER!"=="whisper_engine" set "START_WHISPER=1"
        )
    )
)

rem --- decide whether face-tracker should be started ---
set "START_FACETRACKER=0"
if defined FORCE_FACETRACKER (
    set "START_FACETRACKER=1"
) else (
    if not defined FORCE_NOFACETRACKER (
        if exist "%ENV_FILE%" (
            set "REFRAMING_PROVIDER="
            for /f "usebackq tokens=1,* delims==" %%A in (`findstr /b "AI_REFRAMING_PROVIDER=" "%ENV_FILE%"`) do set "REFRAMING_PROVIDER=%%B"
            if /i "!REFRAMING_PROVIDER!"=="face_tracker" set "START_FACETRACKER=1"
        )
    )
)

rem --- decide whether instagram-automation should be started ---
rem No env-based "mode" toggle for this one (unlike the AI providers) -
rem Instagram connect/publish always calls it, so default to on whenever
rem it looks installed.
set "START_INSTAGRAM=0"
if defined FORCE_INSTAGRAM (
    set "START_INSTAGRAM=1"
) else (
    if not defined FORCE_NOINSTAGRAM (
        if exist "%ROOT%\tools\instagram-automation\node_modules" set "START_INSTAGRAM=1"
    )
)

echo Starting clipper-tools...

rem 0. 9router
start "clipper: 9router" cmd /k "9router"

rem 1. Redis
start "clipper: redis" cmd /k "cd /d "%ROOT%\tools\redis" && redis-server.exe redis.conf"

rem 2. Laravel API
start "clipper: laravel api" cmd /k "cd /d "%ROOT%\backend" && php artisan serve --host=0.0.0.0 --port=8000"

rem 3. Queue worker
start "clipper: queue worker" cmd /k "cd /d "%ROOT%\backend" && php artisan queue:work redis --queue=default"

rem 3a. Batch downloads worker - dedicated queue for ProcessVideoBatchJob (the batch
rem autobot's downloader). Kept separate from the 'default' worker above so a batch's
rem downloads can run at the same time as that batch's own items being processed
rem (transcribe/analyze/render/publish, still on 'default') instead of blocking
rem behind them - see ProcessVideoBatchJob's docblock. Required, not optional: without
rem it, a created batch just sits there since nothing ever downloads its videos.
start "clipper: batch downloads" cmd /k "cd /d "%ROOT%\backend" && php artisan queue:work redis --queue=batch-downloads"

rem 3b. Scheduler - runs App\Console\Commands\ReapStalledProcessingJobs every 5min
rem (see bootstrap/app.php withSchedule) to auto-fail ProcessingJob rows stuck at
rem "running" because their worker died (crash, PC restart) without ever getting to
rem mark them failed itself.
start "clipper: scheduler" cmd /k "cd /d "%ROOT%\backend" && php artisan schedule:work"

rem 4. Next.js frontend
start "clipper: frontend" cmd /k "cd /d "%ROOT%\frontend" && npm run dev"

rem 5. Whisper engine (optional)
if "%START_WHISPER%"=="1" (
    if exist "%ROOT%\tools\whisper-engine\venv\Scripts\python.exe" (
        start "clipper: whisper engine" cmd /k "cd /d "%ROOT%\tools\whisper-engine" && venv\Scripts\python.exe main.py"
    ) else (
        echo Skipping whisper-engine: venv not found at %ROOT%\tools\whisper-engine\venv\Scripts\python.exe ^(see tools\whisper-engine\README.md^)
    )
) else (
    echo Skipping whisper-engine ^(AI_TRANSCRIPTION_PROVIDER isn't whisper_engine; pass -WhisperEngine to force it^)
)

rem 6. Face tracker (optional)
if "%START_FACETRACKER%"=="1" (
    if exist "%ROOT%\tools\face-tracker\venv\Scripts\python.exe" (
        start "clipper: face tracker" cmd /k "cd /d "%ROOT%\tools\face-tracker" && venv\Scripts\python.exe main.py"
    ) else (
        echo Skipping face-tracker: venv not found at %ROOT%\tools\face-tracker\venv\Scripts\python.exe ^(see tools\face-tracker\README.md^)
    )
) else (
    echo Skipping face-tracker ^(AI_REFRAMING_PROVIDER isn't face_tracker; pass -FaceTracker to force it^)
)

rem 7. Instagram automation (optional)
if "%START_INSTAGRAM%"=="1" (
    start "clipper: instagram automation" cmd /k "cd /d "%ROOT%\tools\instagram-automation" && npm start"
) else (
    echo Skipping instagram-automation: node_modules not found ^(run 'npm install' in tools\instagram-automation, or pass -InstagramAutomation to force^) - only needed to connect/publish Instagram accounts
)

echo.
echo Windows opened (optional services included only if enabled). Close a window to stop that process.
echo Frontend:  http://localhost:3000
echo API:       http://localhost:8000
if "%START_WHISPER%"=="1" echo Whisper:   http://127.0.0.1:8100/health
if "%START_FACETRACKER%"=="1" echo Face tracker: http://127.0.0.1:8200/health
if "%START_INSTAGRAM%"=="1" echo Instagram automation: http://127.0.0.1:8300/health

endlocal
