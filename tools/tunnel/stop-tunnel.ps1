<#
.SYNOPSIS  Closes the quick tunnel started by start-tunnel.ps1 (stops only that cloudflared process).
#>
$pidFile = Join-Path $PSScriptRoot 'tunnel.pid'
if (-not (Test-Path $pidFile)) { Write-Host "No tunnel.pid - nothing to stop."; return }
$p = Get-Process -Id ([int](Get-Content $pidFile)) -ErrorAction SilentlyContinue
if ($p -and $p.ProcessName -eq 'cloudflared') { Stop-Process -Id $p.Id -Force; Write-Host "Tunnel stopped (PID $($p.Id))." -ForegroundColor Green }
else { Write-Host "Recorded process is not running." }
Remove-Item $pidFile, (Join-Path $PSScriptRoot 'current-url.txt') -ErrorAction SilentlyContinue
