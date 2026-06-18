@echo off
setlocal
cd /d "%~dp0"

set "TASK_NAME=GEOFlow CreBee Bridge Agent"
set "TASK_CMD=%~dp0run-agent-task.cmd"

schtasks /Create /TN "%TASK_NAME%" /SC ONLOGON /TR "%TASK_CMD%" /RL LIMITED /F
if errorlevel 1 (
    echo Failed to install scheduled task.
    exit /b 1
)

schtasks /Run /TN "%TASK_NAME%"
echo Installed and started scheduled task: %TASK_NAME%
