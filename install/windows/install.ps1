<#
  Sofrexa — till PC installer (Windows 10/11).
  Run in an elevated PowerShell from the repository folder:
      powershell -ExecutionPolicy Bypass -File install\windows\install.ps1 [-InstallDir C:\Sofrexa] [-Port 80] [-PhpZip C:\Downloads\php-8.3.x-nts-Win32-vs16-x64.zip]

  What it does (safe to run again for updates — data and config.php are kept):
    1. copies the app to <InstallDir>\app (storage and config stay untouched)
    2. unpacks portable PHP from -PhpZip into <InstallDir>\php (first install) and writes php.ini
    3. creates <InstallDir>\app\config.php on first install (role pc, storage in <InstallDir>\storage)
    4. applies database migrations
    5. opens the port in Windows Firewall for the private (restaurant) network only
    6. registers two tasks that start with Windows and restart if they stop:
         Sofrexa Web    — the local server phones, the kitchen TV and this PC connect to
         Sofrexa Worker — printing, sync with the web copy, nightly backup
    7. puts a "Sofrexa Kasa" shortcut on the desktop (Edge in app mode)
#>
[CmdletBinding()]
param(
    [string]$InstallDir = 'C:\Sofrexa',
    [int]$Port = 80,
    [string]$PhpZip = ''
)
$ErrorActionPreference = 'Stop'

function Say($m) { Write-Host "  $m" }
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw 'Run this script in PowerShell opened with "Run as administrator".' }

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$appSrc = Join-Path $repo 'app'
$app = Join-Path $InstallDir 'app'
$php = Join-Path $InstallDir 'php'
$storage = Join-Path $InstallDir 'storage'
Write-Host "Sofrexa install -> $InstallDir (port $Port)"

# 1. app files (config.php is never overwritten)
New-Item -ItemType Directory -Force -Path $app, $storage | Out-Null
robocopy $appSrc $app /MIR /XF config.php /XD storage /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw "Copy failed (robocopy $LASTEXITCODE)" }
Say 'app files copied'

# 2. PHP
if (-not (Test-Path (Join-Path $php 'php.exe'))) {
    if ($PhpZip -eq '' -or -not (Test-Path $PhpZip)) {
        throw "PHP is not installed yet. Download the 'VS16 x64 Non Thread Safe' zip of PHP 8.3 from https://windows.php.net/download/ and run again with -PhpZip <path to the zip>."
    }
    Expand-Archive -Path $PhpZip -DestinationPath $php -Force
    Say 'PHP unpacked'
}
$ini = @"
; written by the Sofrexa installer
extension_dir = "ext"
extension=curl
extension=fileinfo
extension=gd
extension=intl
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sodium
extension=sqlite3
extension=zip
zend_extension=opcache
opcache.enable=1
opcache.enable_cli=0
memory_limit = 512M
max_execution_time = 120
upload_max_filesize = 64M
post_max_size = 64M
date.timezone = Asia/Famagusta
display_errors = Off
log_errors = On
error_log = "$storage\logs\php-errors.log"
session.use_strict_mode = 1
"@
Set-Content -Path (Join-Path $php 'php.ini') -Value $ini -Encoding ASCII
$phpExe = Join-Path $php 'php.exe'
& $phpExe -r "exit(extension_loaded('pdo_sqlite') && extension_loaded('sodium') && class_exists('ZipArchive') ? 0 : 1);"
if ($LASTEXITCODE -ne 0) { throw 'PHP is missing required extensions (pdo_sqlite, sodium, zip).' }
Say ('PHP ' + (& $phpExe -r 'echo PHP_VERSION;'))

# 3. config.php on first install
$config = Join-Path $app 'config.php'
if (-not (Test-Path $config)) {
    $net = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.PrefixOrigin -in 'Dhcp', 'Manual' -and $_.IPAddress -notlike '169.*' } | Select-Object -First 1)
    $cidr = if ($net) { ($net.IPAddress -replace '\.\d+$', '.0') + '/' + $net.PrefixLength } else { '192.168.1.0/24' }
    $storagePhp = $storage -replace '\\', '/'
    @"
<?php
// Sofrexa till PC. See config.defaults.php for every option.
return [
    'role' => 'pc',
    'device_name' => 'Kasa PC',
    'storage' => '$storagePhp',
    'db' => '$storagePhp/db/sofrexa.sqlite',
    'pin_networks' => ['$cidr', '127.0.0.1'],
    // After setting up the web copy, fill in its address and the shared key (the same on both sides):
    'sync' => ['remote_url' => '', 'key' => ''],
];
"@ | Set-Content -Path $config -Encoding UTF8
    Say "config.php created (PIN sign-in allowed from $cidr)"
}

# 4. database
& $phpExe (Join-Path $app 'bin\sofrexa') migrate
& $phpExe (Join-Path $app 'bin\sofrexa') seed | Out-Null
Say 'database ready'

# 5. firewall: private network only
Get-NetFirewallRule -DisplayName 'Sofrexa' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
New-NetFirewallRule -DisplayName 'Sofrexa' -Direction Inbound -Protocol TCP -LocalPort $Port -Action Allow -Profile Private | Out-Null
Say "firewall: port $Port open on the private network"

# 6. background tasks
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero)
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$trigger = New-ScheduledTaskTrigger -AtStartup
$tasks = @{
    'Sofrexa Web'    = "-S 0.0.0.0:$Port -t `"$app\public`" `"$app\public\index.php`""
    'Sofrexa Worker' = "`"$app\bin\sofrexa`" worker"
}
foreach ($name in $tasks.Keys) {
    Unregister-ScheduledTask -TaskName $name -Confirm:$false -ErrorAction SilentlyContinue
    $action = New-ScheduledTaskAction -Execute $phpExe -Argument $tasks[$name] -WorkingDirectory $app
    Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Settings $settings -Principal $principal | Out-Null
    Start-ScheduledTask -TaskName $name
    Say "task '$name' registered and started"
}

# 7. desktop shortcut
$url = if ($Port -eq 80) { 'http://localhost' } else { "http://localhost:$Port" }
$edge = "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
$lnk = Join-Path ([Environment]::GetFolderPath('CommonDesktopDirectory')) 'Sofrexa Kasa.lnk'
$sh = (New-Object -ComObject WScript.Shell).CreateShortcut($lnk)
if (Test-Path $edge) { $sh.TargetPath = $edge; $sh.Arguments = "--app=$url --start-fullscreen" } else { $sh.TargetPath = $url }
$sh.IconLocation = (Join-Path $app 'public\assets\img\app-icon.ico')
$sh.Save()

$ips = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.*' }).IPAddress
Write-Host ''
Write-Host 'Done. Open on this PC:' $url
foreach ($ip in $ips) { Write-Host "Waiter phones and the kitchen TV: http://$ip$(if ($Port -ne 80) { ":$Port" })" }
Write-Host 'Create the first manager:  php app\bin\sofrexa user:add "Name" manager 1234'
