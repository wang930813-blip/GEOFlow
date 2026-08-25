@echo off
setlocal
set "TASK_NAME=GEOFlow CreBee Bridge Agent"

schtasks /End /TN "%TASK_NAME%" >nul 2>nul
schtasks /Delete /TN "%TASK_NAME%" /F
echo Removed scheduled task: %TASK_NAME%
