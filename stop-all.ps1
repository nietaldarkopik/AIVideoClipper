<#
.SYNOPSIS
    Stops every clipper-tools process — the reverse of start-all.ps1/.bat. Run this
    when start-all fails because a port/process from a previous session is still
    running (a window got closed without stopping its process, a crash left something
    behind, or a service was started ad hoc outside start-all), then run start-all
    again.

.DESCRIPTION
    Identifies processes by the network port they listen on (redis, Laravel API,
    Next.js frontend, whisper-engine, face-tracker, instagram-automation) or by
    exact command line (queue worker, scheduler — these don't listen on a port), and
    stops only those. Deliberately does NOT kill by bare process name (php.exe,
    node.exe, python.exe, redis-server.exe) — this machine also runs WAMP's own PHP
    and possibly other Node/Python projects, and killing every process with a
    matching name would take those down too.

    Also closes any leftover "clipper: *" console windows (the ones start-all opens)
    whose underlying process already exited but the window itself (-NoExit / cmd
    /k) is still sitting open.

.EXAMPLE
    .\stop-all.ps1
#>

$ErrorActionPreference = 'Continue'
$stopped = 0

function Stop-ProcessSafely {
    param([int]$TargetId, [string]$Label)
    try {
        $proc = Get-Process -Id $TargetId -ErrorAction Stop
        Write-Host ("  Stopping {0} (PID {1}, {2})" -f $Label, $TargetId, $proc.ProcessName) -ForegroundColor Yellow
        Stop-Process -Id $TargetId -Force -ErrorAction Stop
        return $true
    }
    catch {
        return $false
    }
}

function Stop-ByPort {
    param([int]$Port, [string]$Label)
    $any = $false
    $conns = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
    foreach ($ownerId in ($conns.OwningProcess | Sort-Object -Unique)) {
        if (Stop-ProcessSafely -TargetId $ownerId -Label "$Label (port $Port)") { $any = $true }
    }
    return $any
}

# Matches on exact command line via WMI, filtered to a specific process name first
# (e.g. only php.exe) so this can never touch a same-named process for an unrelated
# app (WAMP's php.exe, another project's python.exe, etc).
function Stop-ByCommandLine {
    param([string]$ProcessName, [string[]]$MustContainAll, [string]$Label)
    $any = $false
    $procs = Get-CimInstance Win32_Process -Filter "Name='$ProcessName'" -ErrorAction SilentlyContinue
    foreach ($p in $procs) {
        if (-not $p.CommandLine) { continue }
        $isMatch = $true
        foreach ($needle in $MustContainAll) {
            if ($p.CommandLine -notlike "*$needle*") { $isMatch = $false; break }
        }
        if ($isMatch -and (Stop-ProcessSafely -TargetId $p.ProcessId -Label $Label)) { $any = $true }
    }
    return $any
}

Write-Host "Stopping clipper-tools processes..." -ForegroundColor Cyan
Write-Host ""

# --- required services ---
if (Stop-ByPort -Port 20128 -Label '9router') { $stopped++ }
if (Stop-ByPort -Port 6379 -Label 'Redis') { $stopped++ }
if (Stop-ByPort -Port 8000 -Label 'Laravel API') { $stopped++ }
if (Stop-ByPort -Port 3000 -Label 'Frontend (Next.js)') { $stopped++ }
if (Stop-ByCommandLine -ProcessName 'php.exe' -MustContainAll @('artisan', 'queue:work', '--queue=default') -Label 'Queue worker') { $stopped++ }
if (Stop-ByCommandLine -ProcessName 'php.exe' -MustContainAll @('artisan', 'queue:work', '--queue=batch-downloads') -Label 'Batch downloads worker') { $stopped++ }
if (Stop-ByCommandLine -ProcessName 'php.exe' -MustContainAll @('artisan', 'schedule:work') -Label 'Scheduler') { $stopped++ }

# --- optional AI services (port kill + a command-line sweep, since these run with
# an auto-reload supervisor that spawns a worker child with an identical command
# line — killing only the port-owning child can leave the supervisor parent alive
# to immediately respawn a new worker) ---
if (Stop-ByPort -Port 8100 -Label 'Whisper engine') { $stopped++ }
if (Stop-ByCommandLine -ProcessName 'python.exe' -MustContainAll @('whisper-engine') -Label 'Whisper engine (worker/supervisor)') { $stopped++ }

if (Stop-ByPort -Port 8200 -Label 'Face tracker') { $stopped++ }
if (Stop-ByCommandLine -ProcessName 'python.exe' -MustContainAll @('face-tracker') -Label 'Face tracker (worker/supervisor)') { $stopped++ }

# --- optional Instagram automation ---
if (Stop-ByPort -Port 8300 -Label 'Instagram automation') { $stopped++ }

# --- fallback: close leftover "clipper: *" windows whose process already exited above ---
Get-Process | Where-Object { $_.MainWindowTitle -like 'clipper:*' } | ForEach-Object {
    Write-Host ("  Closing leftover window '{0}' (PID {1})" -f $_.MainWindowTitle, $_.Id) -ForegroundColor Yellow
    Stop-Process -Id $_.Id -Force -ErrorAction SilentlyContinue
    $stopped++
}

Write-Host ""
if ($stopped -gt 0) {
    Write-Host "Done - stopped $stopped process(es)/window(s). You can run start-all again." -ForegroundColor Green
}
else {
    Write-Host "No clipper-tools processes were found running." -ForegroundColor Green
}
