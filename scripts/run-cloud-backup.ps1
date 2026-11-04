param(
    [string]$PhpPath
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$entryPoint = Join-Path $projectRoot 'public\index.php'
$logDirectory = Join-Path $projectRoot 'runtime\logs'
$logPath = Join-Path $logDirectory 'backup-scheduler.log'

if (-not $PhpPath) {
    $phpCommand = Get-Command php.exe -ErrorAction SilentlyContinue
    if (-not $phpCommand) {
        throw 'php.exe was not found. Pass its path with -PhpPath.'
    }
    $PhpPath = $phpCommand.Source
}

if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw "PHP executable was not found: $PhpPath"
}

if (-not (Test-Path -LiteralPath $entryPoint -PathType Leaf)) {
    throw "Application entry point was not found: $entryPoint"
}

if (-not (Test-Path -LiteralPath $logDirectory -PathType Container)) {
    New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
}

Add-Content -LiteralPath $logPath -Value "[$(Get-Date -Format o)] Starting scheduled cloud backup."
& $PhpPath $entryPoint 'api/admin/backups/scheduled' 2>&1 | Out-File -LiteralPath $logPath -Append -Encoding utf8
$exitCode = $LASTEXITCODE

if ($exitCode -ne 0) {
    Add-Content -LiteralPath $logPath -Value "[$(Get-Date -Format o)] Scheduled cloud backup failed with exit code $exitCode."
} else {
    Add-Content -LiteralPath $logPath -Value "[$(Get-Date -Format o)] Scheduled cloud backup completed."
}

exit $exitCode
