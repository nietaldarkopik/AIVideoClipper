@echo off
rem Stops every clipper-tools process — the reverse of start-all.bat/.ps1. Run this
rem when start-all fails because a port/process from a previous session is still
rem running, then run start-all again.
rem
rem Unlike start-all.bat (a pure cmd.exe port of start-all.ps1), this is a thin
rem wrapper around stop-all.ps1: reliably identifying "which process owns port 8000"
rem or "which php.exe is running artisan queue:work" via raw netstat/wmic text
rem parsing in batch is fragile, and getting it wrong here means either killing the
rem wrong process or leaving the real one running — so this reuses the tested
rem PowerShell logic instead of re-implementing it. PowerShell itself ships with
rem every supported version of Windows, so this doesn't add a real dependency.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0stop-all.ps1"
