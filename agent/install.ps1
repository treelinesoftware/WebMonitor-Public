<#
.SYNOPSIS
    Installs (or removes) the WebMonitor agent on Windows.

.DESCRIPTION
    Normally run via the one-liner shown in the dashboard (Details -> Agent
    -> Install agent -> Windows), pasted into an elevated Windows PowerShell.
    That one-liner only trusts the WebMonitor server's certificate (by its
    SHA-256 fingerprint) before downloading this script.

    Installs to "C:\Program Files\WebMonitor Agent", keeps its token in
    C:\ProgramData\WebMonitorAgent (SYSTEM and Administrators only) and runs
    as the "WebMonitor Agent" scheduled task under SYSTEM.

    Uninstall: run the same one-liner with -Uninstall (shown in the
    dashboard when you remove the agent).
#>
param(
    [string]$Server,
    [string]$Token,
    [string]$CertHash,
    [switch]$RemoveZabbix,
    [switch]$RemoveCheckmk,
    [switch]$Uninstall
)

$ErrorActionPreference = 'Stop'
$InstallDir = Join-Path $env:ProgramFiles 'WebMonitor Agent'
$DataDir = Join-Path $env:ProgramData 'WebMonitorAgent'
$AgentFile = Join-Path $InstallDir 'webmonitor-agent.ps1'
$TaskName = 'WebMonitor Agent'
$Ps = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'

function Step([string]$m) { Write-Host "==> $m" -ForegroundColor Cyan }

function Stop-Agent {
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
        Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    }
    # The agent loop may outlive its task instance; stop it explicitly.
    Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" |
        Where-Object { $_.CommandLine -like '*webmonitor-agent.ps1*' } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}

# Stops/disables the services and uninstalls matching MSI products.
function Remove-MonitoringAgent([string]$Label, [string[]]$ServiceNames, [string[]]$DisplayNames) {
    Step "Removing the $Label agent"
    $found = $false
    foreach ($svc in @(Get-Service -Name $ServiceNames -ErrorAction SilentlyContinue)) {
        Stop-Service -Name $svc.Name -Force -ErrorAction SilentlyContinue
        Set-Service -Name $svc.Name -StartupType Disabled
        Write-Host "    stopped and disabled service '$($svc.Name)'"
        $found = $true
    }
    $keys = @('HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
              'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*')
    $apps = @(Get-ItemProperty -Path $keys -ErrorAction SilentlyContinue | Where-Object {
        $app = $_
        $app.PSObject.Properties['DisplayName'] -and @($DisplayNames | Where-Object { $app.DisplayName -like $_ }).Count
    })
    foreach ($app in $apps) {
        $found = $true
        if ($app.PSChildName -match '^\{[0-9A-Fa-f-]+\}$') {
            $p = Start-Process -FilePath msiexec.exe -ArgumentList "/x $($app.PSChildName) /qn /norestart" -Wait -PassThru
            if ($p.ExitCode -eq 0 -or $p.ExitCode -eq 3010) {
                Write-Host "    uninstalled '$($app.DisplayName)'"
            } else {
                Write-Warning "    msiexec returned $($p.ExitCode) uninstalling '$($app.DisplayName)'; its service stays disabled"
            }
        } else {
            Write-Warning "    '$($app.DisplayName)' wasn't installed with an MSI; its service is disabled but the files remain"
        }
    }
    if (-not $found) { Write-Host "    no $Label agent found" }
}

function Install-Agent {
    if (-not $Server -or -not $Token -or -not $CertHash) {
        throw 'Usage: install.ps1 -Server URL -Token TOKEN -CertHash SHA256 [-RemoveZabbix] [-RemoveCheckmk]'
    }
    $Server = $Server.TrimEnd('/')

    Step 'Checking PowerShell'
    if ($PSVersionTable.PSVersion.Major -lt 5) {
        throw "Windows PowerShell 5.1 is required (found $($PSVersionTable.PSVersion))."
    }
    Write-Host "    PowerShell $($PSVersionTable.PSVersion)"

    Stop-Agent

    Step "Downloading the agent from $Server"
    New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
    New-Item -ItemType Directory -Force -Path $DataDir | Out-Null
    # Only SYSTEM and Administrators may read the agent's token.
    & icacls.exe $DataDir /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "icacls failed on $DataDir" }

    # The one-liner already restricted TLS to this server's certificate.
    $code = (New-Object Net.WebClient).DownloadString("$Server/agent/webmonitor-agent.ps1")
    $errors = $null
    [void][System.Management.Automation.Language.Parser]::ParseInput($code, [ref]$null, [ref]$errors)
    if ($errors -and $errors.Count) { throw "Downloaded agent failed to parse: $($errors[0].Message)" }
    Set-Content -Path $AgentFile -Value $code -Encoding UTF8

    # Remove other agents before ours starts, so its first inventory is accurate.
    if ($RemoveZabbix) { Remove-MonitoringAgent 'Zabbix' @('Zabbix Agent', 'Zabbix Agent 2') @('Zabbix Agent*') }
    if ($RemoveCheckmk) { Remove-MonitoringAgent 'Checkmk' @('CheckMkService') @('Check*MK Agent*', 'Checkmk Agent*') }

    Step 'Enrolling with the server'
    & $Ps -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $AgentFile -Enroll -Server $Server -Token $Token -CertHash $CertHash
    if ($LASTEXITCODE -ne 0) { throw 'Enrollment failed (see above).' }

    Step 'Creating the scheduled task'
    $action = New-ScheduledTaskAction -Execute $Ps -Argument ('-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "{0}"' -f $AgentFile)
    # Start at boot, and re-check every minute: if the agent isn't running
    # (crashed, or its daily restart), this starts it; otherwise it's ignored.
    $triggers = @(
        (New-ScheduledTaskTrigger -AtStartup),
        (New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1))
    )
    $principal = New-ScheduledTaskPrincipal -UserId 'NT AUTHORITY\SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Seconds 0) `
        -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -Hidden
    Register-ScheduledTask -TaskName $TaskName -Description "Reports to WebMonitor at $Server" `
        -Action $action -Trigger $triggers -Principal $principal -Settings $settings | Out-Null
    Start-ScheduledTask -TaskName $TaskName

    Start-Sleep -Seconds 8
    $log = Join-Path $DataDir 'agent.log'
    $running = @(Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" |
        Where-Object { $_.CommandLine -like '*webmonitor-agent.ps1*' -and $_.CommandLine -notlike '*-Enroll*' }).Count -gt 0
    if ($running) {
        Step 'Done. The agent is running; data appears in the dashboard within a minute.'
        Write-Host "    Log: $log"
    } else {
        Write-Warning 'The agent does not appear to be running. Last log lines:'
        if (Test-Path $log) { Get-Content $log -Tail 15 }
        throw 'Agent failed to start.'
    }
}

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
    [Security.Principal.WindowsBuiltInRole]::Administrator)
try {
    if (-not $isAdmin) { throw 'Run this in an elevated PowerShell (Run as Administrator).' }
    if ($Uninstall) {
        Step 'Removing the WebMonitor agent'
        Stop-Agent
        Remove-Item -Recurse -Force -Path $InstallDir, $DataDir -ErrorAction SilentlyContinue
        Write-Host '    Removed. (Remove the host''s agent in the dashboard too.)'
    } else {
        Install-Agent
    }
} catch {
    Write-Host "!! $($_.Exception.Message)" -ForegroundColor Red
} finally {
    # Don't leave this session trusting only the WebMonitor certificate.
    [Net.ServicePointManager]::ServerCertificateValidationCallback = $null
}
