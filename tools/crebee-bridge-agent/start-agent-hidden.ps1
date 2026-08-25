$ErrorActionPreference = 'Stop'
$agentDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$logPath = Join-Path $agentDir 'bridge-agent.log'
$errPath = Join-Path $agentDir 'bridge-agent.err.log'
$taskRunner = Join-Path $agentDir 'run-agent-task.cmd'

Start-Process -FilePath cmd.exe `
    -ArgumentList @('/c', $taskRunner) `
    -WorkingDirectory $agentDir `
    -WindowStyle Hidden

Write-Host "CreBee Bridge Agent watchdog started."
Write-Host "Log: $logPath"
Write-Host "Error log: $errPath"
