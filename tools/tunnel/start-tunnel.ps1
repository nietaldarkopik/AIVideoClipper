<#
.SYNOPSIS
    Opens a temporary public HTTPS URL (Cloudflare quick tunnel) to the local API on port 8000 and
    prints it. Fallback for when the main domain (existcode.excoder.site) is unreachable.
.DESCRIPTION
    The URL looks like https://random-words.trycloudflare.com and CHANGES every time this runs.
    It exposes the API to the whole internet for as long as the tunnel is up — stop it with
    stop-tunnel.ps1 when you no longer need it. Deliberately NOT started by start-all.ps1.
    Needs cloudflared: winget install --id Cloudflare.cloudflared -e
    The API must already be running (php artisan serve --host=0.0.0.0 --port=8000).
.PARAMETER Port
    Local API port (default 8000).
#>
param([int]$Port = 8000)
$ErrorActionPreference = 'Stop'

$cf = (Get-Command cloudflared -ErrorAction SilentlyContinue).Source
if (-not $cf) { $cf = 'C:\Program Files (x86)\cloudflared\cloudflared.exe' }
if (-not (Test-Path $cf)) { throw "cloudflared not found. Install it: winget install --id Cloudflare.cloudflared -e" }

$log = Join-Path $PSScriptRoot 'cloudflared.log'
$urlFile = Join-Path $PSScriptRoot 'current-url.txt'
$pidFile = Join-Path $PSScriptRoot 'tunnel.pid'

if (Test-Path $pidFile) {
    $old = Get-Process -Id ([int](Get-Content $pidFile)) -ErrorAction SilentlyContinue
    if ($old -and $old.ProcessName -eq 'cloudflared') {
        Write-Host "Tunnel already running (PID $($old.Id)). Current URL:" -ForegroundColor Yellow
        if (Test-Path $urlFile) { Get-Content $urlFile }
        return
    }
}

# Fail early with a clear message instead of handing out a URL that returns 502.
try { Invoke-WebRequest "http://127.0.0.1:$Port/api/auth/me" -UseBasicParsing -TimeoutSec 5 -ErrorAction Stop | Out-Null }
catch { if ($_.Exception.Response -eq $null) { throw "Local API is not answering on port $Port. Start it first (start-all.ps1)." } }

Remove-Item $log, $urlFile -ErrorAction SilentlyContinue
$p = Start-Process -FilePath $cf -ArgumentList 'tunnel', '--no-autoupdate', '--url', "http://localhost:$Port" `
    -RedirectStandardError $log -WindowStyle Hidden -PassThru
$p.Id | Set-Content $pidFile

$url = $null
for ($i = 0; $i -lt 45 -and -not $url; $i++) {
    Start-Sleep -Seconds 1
    if ($p.HasExited) { throw "cloudflared exited early. See $log" }
    if (Test-Path $log) {
        $m = Select-String -Path $log -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -List -ErrorAction SilentlyContinue
        if ($m) { $url = $m.Matches[0].Value }
    }
}
if (-not $url) { throw "No tunnel URL after 45s. See $log" }

$url | Set-Content $urlFile
Write-Host ""
Write-Host "Tunnel is up (PID $($p.Id))." -ForegroundColor Green
Write-Host "API base URL : $url/api" -ForegroundColor Green
Write-Host "Allow this domain in Claude Desktop's network settings:  *.trycloudflare.com"
Write-Host "Stop it with : .\stop-tunnel.ps1"
