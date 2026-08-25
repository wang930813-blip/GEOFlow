@echo off
setlocal
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -Command "$base = Get-Location; foreach ($name in @('bridge-agent.pid', 'bridge-agent-watchdog.pid')) { $pidFile = Join-Path $base $name; if (Test-Path $pidFile) { $targetPid = [int](Get-Content -Raw $pidFile); Stop-Process -Id $targetPid -Force -ErrorAction SilentlyContinue; Remove-Item $pidFile -Force -ErrorAction SilentlyContinue; Write-Host ('Stopped pid: ' + $targetPid) } }; Write-Host 'CreBee Bridge Agent stop command finished.'"
