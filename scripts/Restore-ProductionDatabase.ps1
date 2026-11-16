#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Manifest,
    [Parameter(Mandatory = $true)][ValidatePattern('^[a-z][a-z0-9_]{0,54}_restore$')][string]$TargetDatabase,
    [string]$EnvironmentFile = '.env.production',
    [ValidatePattern('^[a-z0-9][a-z0-9-]{2,45}$')][string]$ProjectName = 'dawtfg-production'
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$manifestPath = (Resolve-Path -LiteralPath $Manifest).Path
$details = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
if ($details.database.file -notmatch '^database-[a-z0-9-]+\.dump$') { throw 'Invalid backup file name.' }
$backupPath = Join-Path (Split-Path -Parent $manifestPath) $details.database.file
if ((Get-FileHash -LiteralPath $backupPath -Algorithm SHA256).Hash -ne $details.database.sha256) { throw 'Backup checksum mismatch.' }
$environmentPath = (Resolve-Path -LiteralPath (Join-Path $projectRoot $EnvironmentFile)).Path
$composeArguments = @('compose', '--env-file', $environmentPath, '-p', $ProjectName, '-f', (Join-Path $projectRoot 'compose.production.yaml'))
function Invoke-RestoreDocker {
    param([string[]]$Arguments)
    & docker @Arguments
    if ($LASTEXITCODE -ne 0) { throw 'Recovery failed. Existing databases were not dropped; inspect the new target before retrying.' }
}
$temporaryPath = '/tmp/restore-' + [guid]::NewGuid().ToString('N') + '.dump'
Invoke-RestoreDocker ($composeArguments + @('cp', $backupPath, ('prod-postgres:' + $temporaryPath)))
# createdb refuses an existing name. No --clean, DROP or overwrite is allowed.
Invoke-RestoreDocker ($composeArguments + @('exec', '-T', 'prod-postgres', 'sh', '-c', 'PGPASSWORD="$POSTGRES_PASSWORD" createdb -h 127.0.0.1 -U "$POSTGRES_USER" --owner "$APP_DB_USER" "$1"', 'sh', $TargetDatabase))
Invoke-RestoreDocker ($composeArguments + @('exec', '-T', 'prod-postgres', 'sh', '-c', 'PGPASSWORD="$POSTGRES_PASSWORD" pg_restore -h 127.0.0.1 -U "$POSTGRES_USER" --role "$APP_DB_USER" --dbname "$1" --no-owner --no-privileges --single-transaction --exit-on-error "$2"', 'sh', $TargetDatabase, $temporaryPath))
Invoke-RestoreDocker ($composeArguments + @('exec', '-T', 'prod-postgres', 'rm', '-f', '--', $temporaryPath))
Write-Output "Database restored to $TargetDatabase. The running application's database was not changed."
