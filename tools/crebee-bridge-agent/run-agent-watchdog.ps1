$ErrorActionPreference = 'Continue'

$agentDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$logPath = Join-Path $agentDir 'bridge-agent.log'
$errPath = Join-Path $agentDir 'bridge-agent.err.log'
$pidPath = Join-Path $agentDir 'bridge-agent-watchdog.pid'
$watchdogLogPath = Join-Path $agentDir 'bridge-agent-watchdog.log'
$watchdogErrPath = Join-Path $agentDir 'bridge-agent-watchdog.err.log'
$mutexName = 'GEOFlowCrebeeBridgeAgentWatchdog'
$mutex = New-Object System.Threading.Mutex($false, $mutexName)
$hasMutex = $false

function Write-AgentLog {
    param(
        [string] $Path,
        [string] $Message
    )

    $line = "[$((Get-Date).ToString('o'))] $Message$([Environment]::NewLine)"
    [System.IO.File]::AppendAllText($Path, $line, [System.Text.UTF8Encoding]::new($false))
}

try {
    $hasMutex = $mutex.WaitOne(0, $false)
    if (-not $hasMutex) {
        Write-AgentLog $watchdogLogPath 'watchdog already running'
        exit 0
    }

    Set-Location $agentDir
    Set-Content -Path $pidPath -Value $PID -Encoding ascii
    Write-AgentLog $watchdogLogPath "watchdog started pid=$PID"
    Write-AgentLog $logPath "watchdog started pid=$PID"

    while ($true) {
        Write-AgentLog $watchdogLogPath 'starting node agent'
        Write-AgentLog $logPath 'starting node agent'

        try {
            $command = "node src/index.mjs >> `"$logPath`" 2>> `"$errPath`""
            & cmd.exe /d /c $command
            $exitCode = if ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }
            Write-AgentLog $watchdogErrPath "node agent exited with code $exitCode"
            Write-AgentLog $errPath "node agent exited with code $exitCode"
        } catch {
            Write-AgentLog $watchdogErrPath "watchdog failed to run node agent: $($_.Exception.Message)"
            Write-AgentLog $errPath "watchdog failed to run node agent: $($_.Exception.Message)"
        }

        Start-Sleep -Seconds 5
    }
} finally {
    if (Test-Path $pidPath) {
        Remove-Item $pidPath -Force -ErrorAction SilentlyContinue
    }

    if ($hasMutex) {
        $mutex.ReleaseMutex() | Out-Null
    }

    $mutex.Dispose()
}
