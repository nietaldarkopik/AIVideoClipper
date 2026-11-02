@echo off
rem Double-click launcher for refresh-cookies.ps1 - triggers one UAC prompt
rem to run it elevated (esentutl's /vss snapshot, which is how the script
rem reads Chrome's cookie database without closing Chrome, is admin-only),
rem then keeps the window open so you can see the result.
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process powershell -Verb RunAs -ArgumentList '-NoProfile -ExecutionPolicy Bypass -NoExit -File \"%~dp0refresh-cookies.ps1\"'"
