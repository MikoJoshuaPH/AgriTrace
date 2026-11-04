param(
    [string]$PhpPath,
    [string]$TaskName = 'AgriTrace daily cloud database backup'
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runner = Join-Path $PSScriptRoot 'run-cloud-backup.ps1'
$powershell = Join-Path $PSHOME 'powershell.exe'
$arguments = "-NoProfile -ExecutionPolicy Bypass -File `"$runner`""

if (-not (Test-Path -LiteralPath $runner -PathType Leaf)) {
    throw "Backup runner was not found: $runner"
}

if ($PhpPath) {
    if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
        throw "PHP executable was not found: $PhpPath"
    }
    $arguments += " -PhpPath `"$PhpPath`""
}

$action = New-ScheduledTaskAction -Execute $powershell -Argument $arguments -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger -Daily -At '12:00PM'
$principal = New-ScheduledTaskPrincipal `
    -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) `
    -LogonType Interactive `
    -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1)

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Description 'Creates a compressed AgriTrace database backup in Upstash Blob every day at noon.' `
    -Force | Out-Null

Write-Output "Scheduled task '$TaskName' is registered to run daily at 12:00 PM local time."
Write-Output 'The task runs only while this Windows user is logged on.'
