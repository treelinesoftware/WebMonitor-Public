<#
.SYNOPSIS
    WebMonitor agent for Windows.

.DESCRIPTION
    Reports to the WebMonitor server over HTTPS (outbound only, nothing
    listens on this host), using the same report format as the Linux agent:
      - metrics every minute: CPU, memory, page file, disks, network, uptime
      - inventory every hour: OS, hardware, installed programs, pending
        Windows Updates, reboot-pending, failed services, antivirus state

    Written for the built-in Windows PowerShell 5.1. Installed and enrolled
    by install.ps1 and run by the "WebMonitor Agent" scheduled task as SYSTEM.

    webmonitor-agent.ps1 -Enroll -Server URL -Token TOKEN -CertHash HASH   (installer)
    webmonitor-agent.ps1                                                  (scheduled task)
    webmonitor-agent.ps1 -Print metrics|inventory                         (show a report)

    It also runs jobs from the dashboard (check for / install Windows Updates,
    reboot, upgrade the agent). Only those job types exist and each is
    implemented here, so the server can never make the agent run arbitrary
    commands. Each job runs in a child process (-RunJob) so the main loop
    keeps reporting while Windows Update works.
#>
param(
    [switch]$Enroll,
    [string]$Server,
    [string]$Token,
    [string]$CertHash,
    [ValidateSet('', 'metrics', 'inventory')]
    [string]$Print = '',
    # Internal: run one job (started by the main loop in a child process).
    [string]$RunJob = ''
)

$ErrorActionPreference = 'Stop'
$Version = '0.5.0'
$DataDir = Join-Path $env:ProgramData 'WebMonitorAgent'
$ConfFile = Join-Path $DataDir 'agent.json'
$LogFile = Join-Path $DataDir 'agent.log'
$DefaultIntervals = @{ metrics = 60; inventory = 3600 }
# Recycle the process daily; the scheduled task restarts it within a minute.
$MaxRuntimeHours = 24
$JobDir = Join-Path $DataDir 'jobs'
$JobPollSec = 20
$JobFlushSec = 10

# ------------------------------------------------------------- transport

# HTTPS client that trusts exactly one certificate (by SHA-256 of its DER
# bytes) for each request, instead of changing process-wide TLS settings.
if (-not ('WebMonHttp' -as [type])) {
    Add-Type -IgnoreWarnings -TypeDefinition @'
using System;
using System.IO;
using System.IO.Compression;
using System.Net;
using System.Net.Security;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text;

public class WebMonResult {
    public int Status;
    public string Body;
}

public static class WebMonHttp {
    public static string Sha256Hex(byte[] data) {
        using (SHA256 sha = SHA256.Create()) {
            return BitConverter.ToString(sha.ComputeHash(data)).Replace("-", "");
        }
    }

    public static WebMonResult Send(string method, string url, string json, string headerName,
                                    string headerValue, string certHash) {
        HttpWebRequest req = (HttpWebRequest)WebRequest.Create(url);
        req.Method = method;
        req.Timeout = 60000;
        req.ReadWriteTimeout = 60000;
        req.UserAgent = "webmonitor-agent-windows";
        req.ServerCertificateValidationCallback = delegate(object sender, X509Certificate cert,
                X509Chain chain, SslPolicyErrors errors) {
            return cert != null && string.Equals(Sha256Hex(cert.GetRawCertData()), certHash,
                                                 StringComparison.OrdinalIgnoreCase);
        };
        if (!string.IsNullOrEmpty(headerName)) req.Headers[headerName] = headerValue;

        if (!string.IsNullOrEmpty(json)) {  // PowerShell passes $null as ""
            byte[] body = Encoding.UTF8.GetBytes(json);
            if (body.Length > 2048) {
                MemoryStream ms = new MemoryStream();
                using (GZipStream gz = new GZipStream(ms, CompressionMode.Compress)) {
                    gz.Write(body, 0, body.Length);
                }
                body = ms.ToArray();
                req.Headers["Content-Encoding"] = "gzip";
            }
            req.ContentType = "application/json";
            req.ContentLength = body.Length;
            using (Stream st = req.GetRequestStream()) {
                st.Write(body, 0, body.Length);
            }
        }

        HttpWebResponse resp;
        try {
            resp = (HttpWebResponse)req.GetResponse();
        } catch (WebException ex) {
            resp = ex.Response as HttpWebResponse;
            if (resp == null) throw;
        }
        using (resp) {
            using (StreamReader reader = new StreamReader(resp.GetResponseStream(), Encoding.UTF8)) {
                WebMonResult result = new WebMonResult();
                result.Status = (int)resp.StatusCode;
                result.Body = reader.ReadToEnd();
                return result;
            }
        }
    }
}
'@
}

# TLS 1.2 isn't enabled by default for .NET Framework on older systems.
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor 3072

function Write-Log([string]$Level, [string]$Message) {
    $line = '{0} {1} {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $Message
    try {
        if ((Test-Path $LogFile) -and (Get-Item $LogFile).Length -gt 1MB) {
            Move-Item -Force $LogFile "$LogFile.1"
        }
        Add-Content -Path $LogFile -Value $line -Encoding UTF8
    } catch { }
    if ($Print -or $Enroll) { Write-Host $line }
}

function Send-Report([string]$Action, $Payload, [string]$HeaderName, [string]$HeaderValue, $Conf) {
    $json = $Payload | ConvertTo-Json -Depth 8 -Compress
    $url = '{0}/api/agent.php?action={1}' -f $Conf.server, $Action
    $r = [WebMonHttp]::Send('POST', $url, $json, $HeaderName, $HeaderValue, $Conf.cert_hash)
    $body = $null
    if ($r.Body) {
        try { $body = $r.Body | ConvertFrom-Json } catch { $body = $null }
    }
    return @{ Status = $r.Status; Body = $body }
}

# --------------------------------------------------------------- metrics

$script:PrevCpu = $null
$script:PrevNet = $null
$script:PrevTime = $null

function Get-CpuRaw {
    $p = Get-CimInstance -ClassName Win32_PerfRawData_PerfOS_Processor -Filter "Name='_Total'"
    # PercentProcessorTime here is the inverse (idle) 100ns timer.
    return @([double]$p.PercentProcessorTime, [double]$p.Timestamp_Sys100NS)
}

function Get-NetRaw {
    $h = @{}
    foreach ($s in @(Get-NetAdapterStatistics -ErrorAction SilentlyContinue)) {
        $h[$s.Name] = @([double]$s.ReceivedBytes, [double]$s.SentBytes)
    }
    return $h
}

function Get-Metrics {
    $now = Get-Date
    $cpu = Get-CpuRaw
    $net = Get-NetRaw
    if ($null -eq $script:PrevCpu) {
        # First sample: take a short baseline so CPU% and rates mean something.
        $script:PrevCpu = $cpu; $script:PrevNet = $net; $script:PrevTime = $now
        Start-Sleep -Seconds 1
        $now = Get-Date; $cpu = Get-CpuRaw; $net = Get-NetRaw
    }

    $cpuPct = $null
    $dTs = $cpu[1] - $script:PrevCpu[1]
    if ($dTs -gt 0) {
        $pct = 100.0 * (1.0 - ($cpu[0] - $script:PrevCpu[0]) / $dTs)
        $cpuPct = [math]::Round([math]::Max(0.0, [math]::Min(100.0, $pct)), 1)
    }
    $elapsed = [math]::Max(($now - $script:PrevTime).TotalSeconds, 0.001)

    $os = Get-CimInstance -ClassName Win32_OperatingSystem
    $memTotal = [double]$os.TotalVisibleMemorySize * 1024
    $memFree = [double]$os.FreePhysicalMemory * 1024
    $swapTotal = 0.0; $swapUsed = 0.0
    foreach ($pf in @(Get-CimInstance -ClassName Win32_PageFileUsage -ErrorAction SilentlyContinue)) {
        $swapTotal += [double]$pf.AllocatedBaseSize * 1MB
        $swapUsed += [double]$pf.CurrentUsage * 1MB
    }

    $disks = @()
    foreach ($d in @(Get-CimInstance -ClassName Win32_LogicalDisk -Filter 'DriveType=3')) {
        if (-not $d.Size) { continue }
        $size = [double]$d.Size; $free = [double]$d.FreeSpace
        $disks += [ordered]@{
            mount = $d.DeviceID
            device = $(if ($d.VolumeName) { $d.VolumeName } else { $d.DeviceID })
            fstype = $d.FileSystem
            size = $size
            used = $size - $free
            available = $free
            used_pct = [math]::Round(100.0 * ($size - $free) / $size, 1)
        }
    }

    $addrs = @{}
    foreach ($ip in @(Get-NetIPAddress -ErrorAction SilentlyContinue)) {
        if ($ip.IPAddress -like 'fe80*' -or $ip.IPAddress -like '169.254.*' -or
            $ip.IPAddress -eq '127.0.0.1' -or $ip.IPAddress -eq '::1') { continue }
        if (-not $addrs.ContainsKey($ip.InterfaceAlias)) { $addrs[$ip.InterfaceAlias] = @() }
        $addrs[$ip.InterfaceAlias] += ('{0}/{1}' -f $ip.IPAddress, $ip.PrefixLength)
    }
    $network = @()
    foreach ($a in @(Get-NetAdapter -ErrorAction SilentlyContinue | Sort-Object Name)) {
        $ifAddrs = @(); if ($addrs.ContainsKey($a.Name)) { $ifAddrs = @($addrs[$a.Name]) }
        if ($a.Status -ne 'Up' -and $ifAddrs.Count -eq 0) { continue }
        $rx = $null; $tx = $null; $rxRate = $null; $txRate = $null
        if ($net.ContainsKey($a.Name)) {
            $rx = $net[$a.Name][0]; $tx = $net[$a.Name][1]
            if ($script:PrevNet.ContainsKey($a.Name)) {
                $rxRate = [math]::Round([math]::Max(0, $rx - $script:PrevNet[$a.Name][0]) / $elapsed)
                $txRate = [math]::Round([math]::Max(0, $tx - $script:PrevNet[$a.Name][1]) / $elapsed)
            }
        }
        $speed = $null
        if ($a.ReceiveLinkSpeed -gt 0) { $speed = [int64]($a.ReceiveLinkSpeed / 1000000) }
        $network += [ordered]@{
            name = $a.Name
            addrs = $ifAddrs
            mac = $(if ($a.MacAddress) { $a.MacAddress.Replace('-', ':').ToLower() } else { $null })
            up = ($a.Status -eq 'Up')
            speed_mbps = $speed
            rx_bytes = $rx; tx_bytes = $tx
            rx_rate = $rxRate; tx_rate = $txRate
        }
    }

    $script:PrevCpu = $cpu; $script:PrevNet = $net; $script:PrevTime = $now

    return [ordered]@{
        cpu_pct = $cpuPct
        cpu_count = [Environment]::ProcessorCount
        load = $null   # Windows has no load average
        memory = [ordered]@{
            total = $memTotal
            used = $memTotal - $memFree
            available = $memFree
            swap_total = $swapTotal
            swap_used = $swapUsed
        }
        uptime_sec = [int64]($now - $os.LastBootUpTime).TotalSeconds
        processes = @(Get-Process).Count
        disks = $disks
        network = $network
    }
}

# ------------------------------------------------------------- inventory

function Get-InstalledPrograms {
    $seen = @{}
    $list = New-Object System.Collections.Generic.List[object]
    $keys = @('HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
              'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*')
    foreach ($p in @(Get-ItemProperty -Path $keys -ErrorAction SilentlyContinue)) {
        $name = $p.PSObject.Properties['DisplayName']
        if (-not $name -or -not $name.Value) { continue }
        if ($p.PSObject.Properties['SystemComponent'] -and $p.SystemComponent -eq 1) { continue }
        $ver = ''
        if ($p.PSObject.Properties['DisplayVersion'] -and $p.DisplayVersion) { $ver = [string]$p.DisplayVersion }
        $key = '{0}|{1}' -f $name.Value, $ver
        if ($seen.ContainsKey($key)) { continue }
        $seen[$key] = $true
        $list.Add(@([string]$name.Value, $ver))
    }
    return , $list.ToArray()
}

# Windows Update search runs in a child job so a stuck search can't hang
# the agent. It reads the results of Windows' own last scan (Online =
# false) rather than contacting Windows Update every hour.
function Get-PendingUpdates {
    $job = Start-Job -ScriptBlock {
        $session = New-Object -ComObject Microsoft.Update.Session
        $searcher = $session.CreateUpdateSearcher()
        $criteria = "IsInstalled=0 and IsHidden=0 and Type='Software'"
        try {
            $searcher.Online = $false
            $result = $searcher.Search($criteria)
        } catch {
            $searcher.Online = $true
            $result = $searcher.Search($criteria)
        }
        foreach ($u in $result.Updates) {
            $cats = @($u.Categories | ForEach-Object { $_.Name })
            [pscustomobject]@{
                name = [string]$u.Title
                kb = (@($u.KBArticleIDs) | ForEach-Object { "KB$_" }) -join ', '
                security = (($cats -contains 'Security Updates') -or [bool]$u.MsrcSeverity)
            }
        }
    }
    try {
        if (-not (Wait-Job $job -Timeout 600)) {
            Stop-Job $job
            return [ordered]@{ manager = 'windows-update'; error = 'Windows Update search timed out'; packages = $null }
        }
        $items = @(Receive-Job $job -ErrorAction Stop)
        $pkgs = @()
        foreach ($i in $items) {
            $pkgs += [ordered]@{
                name = $i.name; current = $null
                new = $(if ($i.kb) { $i.kb } else { $null })
                security = [bool]$i.security
            }
        }
        return [ordered]@{ manager = 'windows-update'; packages = $pkgs }
    } catch {
        return [ordered]@{ manager = 'windows-update'; error = "Windows Update search failed: $($_.Exception.Message)"; packages = $null }
    } finally {
        Remove-Job $job -Force -ErrorAction SilentlyContinue
    }
}

function Test-RebootPending {
    if (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired') { return $true }
    if (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending') { return $true }
    try {
        return [bool](New-Object -ComObject Microsoft.Update.SystemInfo).RebootRequired
    } catch {
        return $false
    }
}

# Antivirus: every product registered with Windows Security Center (desktop
# Windows), plus Microsoft Defender's own status (also on Windows Server,
# which has no Security Center). enabled/up_to_date/realtime are $null when
# unknown.
function Get-Antivirus {
    $products = New-Object System.Collections.Generic.List[object]
    $errors = @()
    try {
        foreach ($p in @(Get-CimInstance -Namespace 'root/SecurityCenter2' -ClassName AntiVirusProduct -ErrorAction Stop)) {
            # productState: byte 2 = scanner on (0x10/0x11), byte 3 = signatures (0x00 up to date, 0x10 out of date).
            $hex = '{0:x6}' -f [int]$p.productState
            $products.Add([ordered]@{
                    name = [string]$p.displayName
                    enabled = @('10', '11') -contains $hex.Substring(2, 2)
                    up_to_date = $hex.Substring(4, 2) -eq '00'
                    realtime = $null; signature_age_days = $null; last_scan = $null; source = 'security-center'
                })
        }
    } catch {
        # Not on Windows Server; Defender below still answers there.
    }
    try {
        $mp = Get-MpComputerStatus -ErrorAction Stop
        $scan = @($mp.QuickScanEndTime, $mp.FullScanEndTime | Where-Object { $_ }) | Sort-Object -Descending | Select-Object -First 1
        $passive = $mp.PSObject.Properties['AMRunningMode'] -and $mp.AMRunningMode -match 'Passive|EDR'
        $defender = [ordered]@{
            name = 'Microsoft Defender Antivirus'
            enabled = [bool]($mp.AMServiceEnabled -and $mp.AntivirusEnabled -and -not $passive)
            up_to_date = ([int]$mp.AntivirusSignatureAge -le 3)
            realtime = [bool]$mp.RealTimeProtectionEnabled
            signature_age_days = [int]$mp.AntivirusSignatureAge
            last_scan = $(if ($scan) { [int64](($scan.ToUniversalTime() - [datetime]'1970-01-01').TotalSeconds) } else { $null })
            tamper_protected = $(if ($mp.PSObject.Properties['IsTamperProtected']) { [bool]$mp.IsTamperProtected } else { $null })
            passive = [bool]$passive
            source = 'defender'
        }
        # Defender's own report is more detailed than Security Center's line for it.
        $existing = @($products | Where-Object { $_.name -match 'Defender' })
        foreach ($e in $existing) { [void]$products.Remove($e) }
        $products.Add($defender)
    } catch {
        if ($products.Count -eq 0) { $errors += "Defender status unavailable: $($_.Exception.Message)" }
    }
    $result = [ordered]@{ products = $products.ToArray() }
    if ($errors) { $result.error = $errors -join '; ' }
    return $result
}

# Services the server asked us to watch (from its report replies). Their state
# goes out with every metrics report; only these can be restarted by a job.
$script:Watched = @()
$ServiceNamePattern = '^[A-Za-z0-9@._:\-+ ]{1,120}$'

function ConvertTo-Epoch([datetime]$t) {
    return [int64](($t.ToUniversalTime() - [datetime]'1970-01-01').TotalSeconds)
}

# Every service, for the dashboard's "watch a service" picker.
function Get-AllServices {
    $list = @(Get-CimInstance -ClassName Win32_Service | Sort-Object DisplayName | ForEach-Object {
        [ordered]@{ name = $_.Name; display = $_.DisplayName; state = $_.State.ToLower(); start_type = $_.StartMode }
    })
    return , $list
}

# State of each watched service: running/stopped/..., start mode, PID and when its process started.
function Get-WatchedServices([string[]]$Names) {
    $out = New-Object System.Collections.ArrayList
    foreach ($n in $Names) {
        if ($n -notmatch $ServiceNamePattern) { continue }
        $svc = Get-CimInstance -ClassName Win32_Service -Filter ("Name='{0}'" -f $n.Replace("'", "''")) -ErrorAction SilentlyContinue
        if (-not $svc) { [void]$out.Add([ordered]@{ name = $n; missing = $true }); continue }
        $since = $null
        if ($svc.ProcessId -gt 0) {
            $p = Get-Process -Id $svc.ProcessId -ErrorAction SilentlyContinue
            if ($p -and $p.StartTime) { $since = ConvertTo-Epoch $p.StartTime }
        }
        $pid_ = $null
        if ($svc.ProcessId -gt 0) { $pid_ = [int]$svc.ProcessId }
        [void]$out.Add([ordered]@{
            name = $svc.Name; display = $svc.DisplayName; state = $svc.State.ToLower()
            start_type = $svc.StartMode; since = $since; pid = $pid_; restarts = $null
        })
    }
    return , $out.ToArray()
}

function Get-FailedServices {
    # Automatic services that stopped with an error. 1077 = never started
    # (trigger-start services that simply haven't been needed).
    $failed = @(Get-CimInstance -ClassName Win32_Service -Filter "StartMode='Auto' AND State='Stopped'" |
        Where-Object { $_.ExitCode -ne 0 -and $_.ExitCode -ne 1077 } |
        ForEach-Object { $_.Name })
    return , $failed
}

# Whether the previous boot ended cleanly: Windows logs Kernel-Power 41,
# EventLog 6008 (unexpected shutdown) or a BugCheck 1001 at the start of
# the boot that follows a crash, hang or power loss.
function Get-LastBootInfo([datetime]$BootTime) {
    $events = @()
    try {
        $events = @(Get-WinEvent -ErrorAction Stop -FilterHashtable @{
                LogName = 'System'; Id = 41, 6008, 1001; StartTime = $BootTime.AddMinutes(-5) } |
            Where-Object { $_.Id -ne 1001 -or $_.ProviderName -eq 'Microsoft-Windows-WER-SystemErrorReporting' })
    } catch {
        if ($_.FullyQualifiedErrorId -notlike 'NoMatchingEventsFound*') {
            return [ordered]@{ previous_shutdown_clean = $null; error = $_.Exception.Message }
        }
    }
    $list = @($events | ForEach-Object { '{0} {1} {2}' -f $_.Id, $_.ProviderName, $_.TimeCreated.ToString('s') })
    return [ordered]@{ previous_shutdown_clean = ($events.Count -eq 0); crash_events = $list }
}

function Get-Inventory {
    $os = Get-CimInstance -ClassName Win32_OperatingSystem
    $cs = Get-CimInstance -ClassName Win32_ComputerSystem
    $bios = Get-CimInstance -ClassName Win32_BIOS
    $cpu = @(Get-CimInstance -ClassName Win32_Processor)[0]
    $cv = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion' -ErrorAction SilentlyContinue
    $displayVersion = $null
    if ($cv -and $cv.PSObject.Properties['DisplayVersion']) { $displayVersion = $cv.DisplayVersion }

    $model = '{0} {1}' -f $cs.Manufacturer, $cs.Model
    $virt = 'none'
    if ($model -match 'Virtual Machine|Hyper-V') { $virt = 'microsoft' }
    elseif ($model -match 'VMware') { $virt = 'vmware' }
    elseif ($model -match 'QEMU|KVM|Standard PC') { $virt = 'kvm' }
    elseif ($model -match 'VirtualBox') { $virt = 'oracle' }
    elseif ($model -match 'Xen') { $virt = 'xen' }

    $hostname = $env:COMPUTERNAME
    if ($cs.PartOfDomain) { $hostname = '{0}.{1}' -f $env:COMPUTERNAME, $cs.Domain }

    $pretty = $os.Caption
    if ($displayVersion) { $pretty = '{0} {1}' -f $pretty, $displayVersion }
    $pretty = '{0} (build {1})' -f $pretty, $os.BuildNumber

    return [ordered]@{
        hostname = $hostname
        os = [ordered]@{
            pretty_name = $pretty
            id = 'windows'
            id_like = $null
            version_id = $os.Version
            version_codename = $displayVersion
        }
        kernel = $os.Version
        arch = $os.OSArchitecture
        boot_time = [int64](($os.LastBootUpTime.ToUniversalTime() - [datetime]'1970-01-01').TotalSeconds)
        hardware = [ordered]@{
            vendor = $cs.Manufacturer
            model = $cs.Model
            serial = $bios.SerialNumber
            bios = $bios.SMBIOSBIOSVersion
            cpu_model = $(if ($cpu) { $cpu.Name.Trim() } else { $null })
            cpu_count = [Environment]::ProcessorCount
            memory_total = [double]$cs.TotalPhysicalMemory
            virtualization = $virt
        }
        packages = Get-InstalledPrograms
        updates = Get-PendingUpdates
        reboot_required = Test-RebootPending
        failed_services = Get-FailedServices
        services = Get-AllServices
        antivirus = Get-Antivirus
        last_boot = Get-LastBootInfo $os.LastBootUpTime
        agent = [ordered]@{ version = $Version; powershell = $PSVersionTable.PSVersion.ToString() }
    }
}

# ------------------------------------------------------------------ main

function Invoke-Enroll {
    if (-not $Server -or -not $Token -or -not $CertHash) {
        throw '-Enroll needs -Server, -Token and -CertHash'
    }
    $conf = @{ server = $Server.TrimEnd('/'); cert_hash = $CertHash.ToUpper() }
    $payload = [ordered]@{ hostname = $env:COMPUTERNAME; version = $Version }
    $r = Send-Report 'enroll' $payload 'X-Enroll-Token' $Token $conf
    if ($r.Status -ne 200 -or -not $r.Body -or -not $r.Body.agent_token) {
        $msg = 'unexpected response'
        if ($r.Body -and $r.Body.error) { $msg = $r.Body.error }
        throw "Enrollment failed ($($r.Status)): $msg"
    }
    $conf.agent_token = $r.Body.agent_token
    $conf.host_id = $r.Body.host_id
    if (-not (Test-Path $DataDir)) { New-Item -ItemType Directory -Path $DataDir | Out-Null }
    ($conf | ConvertTo-Json -Compress) | Set-Content -Path $ConfFile -Encoding ASCII
    Write-Host "Enrolled with $($conf.server) as host #$($conf.host_id)."
}

# ------------------------------------------------------------------ jobs

function New-UpdateSession {
    $session = New-Object -ComObject Microsoft.Update.Session
    $session.ClientApplicationID = 'WebMonitor'
    return $session
}

function Test-SecurityUpdate($u) {
    $cats = @($u.Categories | ForEach-Object { $_.Name })
    return (($cats -contains 'Security Updates') -or [bool]$u.MsrcSeverity)
}

# Child-process side: runs the job described in $Path (JSON), logging to
# <id>.log and writing the outcome to <id>.result.json.
function Invoke-Job([string]$Path) {
    $job = Get-Content -Raw -Path $Path | ConvertFrom-Json
    $log = Join-Path $JobDir ('{0}.log' -f $job.id)
    $resultFile = Join-Path $JobDir ('{0}.result.json' -f $job.id)
    $result = [ordered]@{ status = 'succeeded'; exit_code = 0; summary = $null; reboot = $false; restart_agent = $false }
    function Out-JobLog([string]$m) { Add-Content -Path $log -Value $m -Encoding UTF8 }

    try {
        switch ($job.type) {
            'refresh' {
                Out-JobLog 'Asking Windows Update for the latest list of updates...'
                $searcher = (New-UpdateSession).CreateUpdateSearcher()
                $searcher.Online = $true
                $r = $searcher.Search("IsInstalled=0 and IsHidden=0 and Type='Software'")
                $sec = @($r.Updates | Where-Object { Test-SecurityUpdate $_ }).Count
                foreach ($u in $r.Updates) { Out-JobLog ('  available: ' + $u.Title) }
                $result.summary = '{0} updates available ({1} security)' -f $r.Updates.Count, $sec
            }
            'updates' {
                $securityOnly = [bool]$job.params.security_only
                $session = New-UpdateSession
                $searcher = $session.CreateUpdateSearcher()
                $searcher.Online = $true
                Out-JobLog 'Searching for updates...'
                $found = $searcher.Search("IsInstalled=0 and IsHidden=0 and Type='Software'")
                $coll = New-Object -ComObject Microsoft.Update.UpdateColl
                foreach ($u in $found.Updates) {
                    if ($securityOnly -and -not (Test-SecurityUpdate $u)) { continue }
                    if ($u.InstallationBehavior.CanRequestUserInput) {
                        Out-JobLog ('  skipped (needs user input): ' + $u.Title)
                        continue
                    }
                    if (-not $u.EulaAccepted) { $u.AcceptEula() }
                    [void]$coll.Add($u)
                    Out-JobLog ('  selected: ' + $u.Title)
                }
                if ($coll.Count -eq 0) {
                    Out-JobLog 'Nothing to install.'
                    $result.summary = $(if ($securityOnly) { 'no security updates were pending' } else { 'no updates were pending' })
                } else {
                    Out-JobLog ('Downloading {0} update(s)...' -f $coll.Count)
                    $dl = $session.CreateUpdateDownloader()
                    $dl.Updates = $coll
                    [void]$dl.Download()
                    Out-JobLog 'Installing...'
                    $inst = $session.CreateUpdateInstaller()
                    $inst.Updates = $coll
                    $ir = $inst.Install()
                    $codes = @{ 0 = 'not started'; 1 = 'in progress'; 2 = 'succeeded'; 3 = 'succeeded with errors'; 4 = 'failed'; 5 = 'aborted' }
                    $failed = 0
                    for ($i = 0; $i -lt $coll.Count; $i++) {
                        $rc = $ir.GetUpdateResult($i).ResultCode
                        if ($rc -ge 4) { $failed++ }
                        Out-JobLog ('  {0}: {1}' -f $codes[[int]$rc], $coll.Item($i).Title)
                    }
                    $result.exit_code = [int]$ir.ResultCode
                    if ($failed -gt 0) {
                        $result.status = 'failed'
                        $result.summary = '{0} of {1} updates failed' -f $failed, $coll.Count
                    } else {
                        $result.summary = '{0} updates installed' -f $coll.Count
                    }
                }
                $needsReboot = Test-RebootPending
                $result.summary += $(if ($needsReboot) { '; reboot required' } else { '; reboot not needed' })
                if ($needsReboot -and [bool]$job.params.reboot) {
                    $result.reboot = $true
                    $result.summary += ' - rebooting now'
                }
            }
            'reboot' {
                Out-JobLog 'Rebooting in 1 minute.'
                $result.reboot = $true
                $result.summary = 'rebooting'
            }
            'agent_upgrade' {
                $conf = Get-Content -Raw -Path $ConfFile | ConvertFrom-Json
                $r = [WebMonHttp]::Send('GET', ($conf.server + '/agent/webmonitor-agent.ps1'), $null, $null, $null, $conf.cert_hash)
                if ($r.Status -ne 200) { throw "download failed (HTTP $($r.Status))" }
                if ($r.Body -notmatch "(?m)^\`$Version = '([^']+)'") { throw "downloaded file doesn't look like the agent" }
                $newVersion = $Matches[1]
                $errors = $null
                [void][System.Management.Automation.Language.Parser]::ParseInput($r.Body, [ref]$null, [ref]$errors)
                if ($errors -and $errors.Count) { throw "downloaded agent failed to parse: $($errors[0].Message)" }
                $me = $PSCommandPath
                Set-Content -Path "$me.new" -Value $r.Body -Encoding UTF8
                Move-Item -Force "$me.new" $me
                Out-JobLog "Installed agent $newVersion (was $Version); restarting."
                $result.summary = "upgraded $Version -> $newVersion"
                $result.restart_agent = $true
            }
            'service_restart' {
                $name = [string]$job.params.service
                if ($name -notmatch $ServiceNamePattern) { throw "not a valid service name: '$name'" }
                if (@($job.watched) -notcontains $name) { throw "$name is not on this host's watched list; only watched services can be restarted" }
                Out-JobLog "Restarting $name..."
                Restart-Service -Name $name -Force -ErrorAction Stop
                $svc = $null
                for ($i = 0; $i -lt 15; $i++) {
                    Start-Sleep -Seconds 2
                    $svc = Get-Service -Name $name -ErrorAction Stop
                    if ($svc.Status -eq 'Running') { break }
                }
                Out-JobLog ("{0}: {1}" -f $name, $svc.Status)
                if ($svc.Status -ne 'Running') { throw "$name restarted but is $($svc.Status)" }
                $result.summary = "restarted $name; it is running"
            }
            default { throw "this agent ($Version) doesn't know job type '$($job.type)'" }
        }
    } catch {
        Out-JobLog ('ERROR: ' + $_.Exception.Message)
        $result.status = 'failed'
        if (-not $result.exit_code) { $result.exit_code = 1 }
        $result.summary = $_.Exception.Message
    }
    ($result | ConvertTo-Json -Compress) | Set-Content -Path $resultFile -Encoding ASCII
}

# Main-loop side: reads what the child has logged since $Offset.
function Read-JobLog([string]$Path, [ref]$Offset) {
    if (-not (Test-Path $Path)) { return '' }
    $fs = [System.IO.File]::Open($Path, 'Open', 'Read', 'ReadWrite')
    try {
        if ($fs.Length -le $Offset.Value) { return '' }
        [void]$fs.Seek($Offset.Value, 'Begin')
        $buf = New-Object byte[] ($fs.Length - $Offset.Value)
        $n = $fs.Read($buf, 0, $buf.Length)
        $Offset.Value += $n
        return [System.Text.Encoding]::UTF8.GetString($buf, 0, $n).TrimStart([char]0xFEFF)
    } finally {
        $fs.Dispose()
    }
}

function Invoke-Loop {
    # One running copy at a time (the task's repeat trigger is a safety net).
    $mutex = New-Object System.Threading.Mutex($false, 'Global\WebMonitorAgent')
    try {
        $owned = $mutex.WaitOne(0)
    } catch {
        $owned = $true  # abandoned by a crashed copy; we own it now
    }
    if (-not $owned) { return }

    $conf = Get-Content -Raw -Path $ConfFile | ConvertFrom-Json
    $intervals = @{ metrics = $DefaultIntervals.metrics; inventory = $DefaultIntervals.inventory }
    $nextDue = @{ inventory = [datetime]::MinValue; metrics = [datetime]::MinValue; jobs = (Get-Date).AddSeconds(5) }
    $failures = 0
    $started = Get-Date
    $job = $null   # running job: @{ id; proc; log; offset; pending; lastFlush }
    if (-not (Test-Path $JobDir)) { New-Item -ItemType Directory -Path $JobDir | Out-Null }
    Write-Log 'INFO' "WebMonitor agent $Version (PowerShell $($PSVersionTable.PSVersion)) reporting to $($conf.server)"

    while ($job -or ((Get-Date) - $started).TotalHours -lt $MaxRuntimeHours) {
        # --- jobs: stream output of a running one, or ask for the next
        if ($job) {
            $offset = $job.offset
            $job.pending += Read-JobLog $job.log ([ref]$offset)
            $job.offset = $offset
            $finished = $job.proc.HasExited
            if ($finished -or ((Get-Date) - $job.lastFlush).TotalSeconds -ge $JobFlushSec) {
                $update = [ordered]@{ id = $job.id; status = 'running'; output = $job.pending }
                $res = $null
                if ($finished) {
                    $resultFile = Join-Path $JobDir ('{0}.result.json' -f $job.id)
                    if (Test-Path $resultFile) { $res = Get-Content -Raw $resultFile | ConvertFrom-Json }
                    if (-not $res) { $res = [pscustomobject]@{ status = 'failed'; exit_code = $job.proc.ExitCode; summary = 'job process ended without a result'; reboot = $false; restart_agent = $false } }
                    $update.status = $res.status; $update.exit_code = $res.exit_code
                    $update.summary = $res.summary; $update.rebooting = [bool]$res.reboot
                }
                try {
                    $r = Send-Report 'job_update' $update 'X-Agent-Token' $conf.agent_token $conf
                    if ($r.Status -eq 200 -or $r.Status -eq 409) { $job.pending = '' }
                } catch { Write-Log 'WARN' "job $($job.id): update failed: $($_.Exception.Message)" }
                $job.lastFlush = Get-Date
                if ($finished -and $job.pending -eq '') {
                    Remove-Item -Force -ErrorAction SilentlyContinue (Join-Path $JobDir ('{0}.*' -f $job.id))
                    Write-Log 'INFO' "job $($job.id) finished: $($res.status) - $($res.summary)"
                    $job = $null
                    $nextDue.inventory = Get-Date   # report the new state right away
                    $nextDue.metrics = Get-Date
                    if ($res.reboot) {
                        & shutdown.exe /r /t 60 /c ('WebMonitor: rebooting (job {0})' -f $update.id) | Out-Null
                    }
                    if ($res.restart_agent) {
                        Write-Log 'INFO' 'Restarting into the upgraded agent'
                        break   # the scheduled task starts the new version within a minute
                    }
                }
            }
        } elseif ((Get-Date) -ge $nextDue.jobs) {
            $nextDue.jobs = (Get-Date).AddSeconds($JobPollSec)
            try {
                $r = Send-Report 'jobs' ([ordered]@{ version = $Version }) 'X-Agent-Token' $conf.agent_token $conf
                if ($r.Status -eq 200 -and $r.Body -and $r.Body.job) {
                    $j = $r.Body.job
                    # The job runs in its own process: give it the watched list (service restarts check it).
                    $j | Add-Member -NotePropertyName watched -NotePropertyValue @($script:Watched) -Force
                    $jobFile = Join-Path $JobDir ('{0}.json' -f $j.id)
                    ($j | ConvertTo-Json -Depth 4 -Compress) | Set-Content -Path $jobFile -Encoding ASCII
                    $Ps = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
                    $proc = Start-Process -FilePath $Ps -PassThru -WindowStyle Hidden -ArgumentList @(
                        '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
                        '-File', ('"{0}"' -f $PSCommandPath), '-RunJob', ('"{0}"' -f $jobFile))
                    $job = @{ id = $j.id; proc = $proc; log = (Join-Path $JobDir ('{0}.log' -f $j.id));
                              offset = [int64]0; pending = ''; lastFlush = Get-Date }
                    Write-Log 'INFO' "Started job $($j.id) ($($j.type))"
                }
            } catch { Write-Log 'WARN' "job poll failed: $($_.Exception.Message)" }
        }

        foreach ($kind in @('inventory', 'metrics')) {
            if ((Get-Date) -lt $nextDue[$kind]) { continue }
            $status = $null; $body = $null; $err = $null
            try {
                if ($kind -eq 'inventory') { $data = Get-Inventory } else { $data = Get-Metrics }
                if ($kind -eq 'metrics' -and $script:Watched.Count -gt 0) { $data.services = Get-WatchedServices $script:Watched }
                $payload = [ordered]@{ kind = $kind; version = $Version; data = $data }
                $r = Send-Report 'report' $payload 'X-Agent-Token' $conf.agent_token $conf
                $status = $r.Status; $body = $r.Body
            } catch {
                $err = $_.Exception.Message
            }

            if ($status -eq 200) {
                $failures = 0
                if ($body -and $body.intervals) {
                    foreach ($k in @('metrics', 'inventory')) {
                        $v = $body.intervals.$k
                        if ($v -is [int] -and $v -ge 10) { $intervals[$k] = $v }
                    }
                }
                if ($body -and $body.PSObject.Properties['watch_services']) {
                    $new = @($body.watch_services | Where-Object { $_ -is [string] -and $_ -match $ServiceNamePattern } | Select-Object -First 200)
                    if (($new -join '|') -ne ($script:Watched -join '|')) {
                        $script:Watched = $new
                        $nextDue.metrics = Get-Date   # report the newly watched services right away
                    }
                }
                $nextDue[$kind] = (Get-Date).AddSeconds($intervals[$kind])
            } elseif ($status -eq 401) {
                $msg = $(if ($body) { $body.error } else { '' })
                Write-Log 'ERROR' "Server rejected this agent's token: $msg"
                $nextDue[$kind] = (Get-Date).AddMinutes(10)
            } else {
                $failures++
                $retry = [math]::Min(300, 15 * [math]::Pow(2, [math]::Min($failures, 5)))
                $msg = $err
                if (-not $msg -and $body) { $msg = $body.error }
                Write-Log 'WARN' "$kind report failed ($status): $msg - retrying in ${retry}s"
                $nextDue[$kind] = (Get-Date).AddSeconds($retry)
            }
        }
        $soonest = $nextDue.inventory
        if ($nextDue.metrics -lt $soonest) { $soonest = $nextDue.metrics }
        if (-not $job -and $nextDue.jobs -lt $soonest) { $soonest = $nextDue.jobs }
        $wait = ($soonest - (Get-Date)).TotalSeconds
        if ($job) { $wait = [math]::Min($wait, 5) }
        Start-Sleep -Seconds ([math]::Max(1, [math]::Ceiling($wait)))
    }
    Write-Log 'INFO' 'Restarting (daily recycle or agent upgrade)'
    $mutex.ReleaseMutex()
}

if ($Print) {
    if ($Print -eq 'inventory') { $data = Get-Inventory } else { $data = Get-Metrics }
    $data | ConvertTo-Json -Depth 8
} elseif ($Enroll) {
    Invoke-Enroll
} elseif ($RunJob) {
    Invoke-Job $RunJob
} else {
    try {
        Invoke-Loop
    } catch {
        Write-Log 'ERROR' "Agent stopped: $($_.Exception.Message)"
        exit 1
    }
}
