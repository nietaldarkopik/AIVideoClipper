@echo off
setlocal enabledelayedexpansion

rem Starts every clipper-tools process in its own cmd window: Redis, Laravel API,
rem queue worker, Next.js frontend, and (if enabled) the self-hosted whisper-engine
rem transcription service and face-tracker reframing service.
rem
rem This is the cmd.exe port of start-all.ps1 for people who don't want to run
rem PowerShell scripts. Behavior mirrors it 1:1, including the .env auto-detection.
rem
rem Usage:
rem   start-all.bat
rem   start-all.bat -WhisperEngine       (force-start whisper-engine)
rem   start-all.bat -NoWhisperEngine     (force-skip whisper-engine)
rem   start-all.bat -FaceTracker         (force-start face-tracker)
rem   start-all.bat -NoFaceTracker       (force-skip face-tracker)

set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"

set "FORCE_WHISPER="
set "FORCE_NOWHISPER="
set "FORCE_FACETRACKER="
set "FORCE_NOFACETRACKER="

:parse_args
if "%~1"=="" goto args_done
if /i "%~1"=="-WhisperEngine" set "FORCE_WHISPER=1"
if /i "%~1"=="-NoWhisperEngine" set "FORCE_NOWHISPER=1"
if /i "%~1"=="-FaceTracker" set "FORCE_FACETRACKER=1"
if /i "%~1"=="-NoFaceTracker" set "FORCE_NOFACETRACKER=1"
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

echo Starting clipper-tools...

rem 1. Redis
start "clipper: redis" cmd /k "cd /d "%ROOT%\tools\redis" && redis-server.exe redis.conf"

rem 2. Laravel API
start "clipper: laravel api" cmd /k "cd /d "%ROOT%\backend" && php artisan serve --host=127.0.0.1 --port=8000"

rem 3. Queue worker
start "clipper: queue worker" cmd /k "cd /d "%ROOT%\backend" && php artisan queue:work redis --queue=default"

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

echo.
echo Windows opened (whisper-engine/face-tracker included only if enabled). Close a window to stop that process.
echo Frontend:  http://localhost:3000
echo API:       http://localhost:8000
if "%START_WHISPER%"=="1" echo Whisper:   http://127.0.0.1:8100/health
if "%START_FACETRACKER%"=="1" echo Face tracker: http://127.0.0.1:8200/health

endlocal
