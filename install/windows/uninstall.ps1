<#
  Removes the Sofrexa tasks, firewall rule and desktop shortcut. Data (storage) and the app folder are kept.
      powershell -ExecutionPolicy Bypass -File install\windows\uninstall.ps1
#>
$ErrorActionPreference = 'Continue'
foreach ($name in 'Sofrexa Web', 'Sofrexa Worker') {
    Stop-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $name -Confirm:$false -ErrorAction SilentlyContinue
}
Get-NetFirewallRule -DisplayName 'Sofrexa' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
Remove-Item (Join-Path ([Environment]::GetFolderPath('CommonDesktopDirectory')) 'Sofrexa Kasa.lnk') -ErrorAction SilentlyContinue
Write-Host 'Sofrexa tasks removed. Data in the storage folder is untouched.'
