#requires -Version 5.1
[CmdletBinding()]
param(
    [string]$EnvironmentFile = '.env.production',
    [ValidatePattern('^[a-z0-9][a-z0-9-]{2,45}$')][string]$ProjectName = 'dawtfg-production',
    [string]$OutputDirectory = '.backups/production'
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$environmentPath = (Resolve-Path -LiteralPath (Join-Path $projectRoot $EnvironmentFile)).Path
$destination = [IO.Path]::GetFullPath((Join-Path $projectRoot $OutputDirectory))
[IO.Directory]::CreateDirectory($destination) | Out-Null
$composeArguments = @('compose', '--env-file', $environmentPath, '-p', $ProjectName, '-f', (Join-Path $projectRoot 'compose.production.yaml'))
function Invoke-BackupDocker {
    param([string[]]$Arguments)
    & docker @Arguments
    if ($LASTEXITCODE -ne 0) { throw 'Backup command failed; incomplete files must not be used for recovery.' }
}
$stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssfff') + '-' + [guid]::NewGuid().ToString('N').Substring(0,8)
$databaseName = "database-$stamp.dump"
$storageName = "storage-$stamp.tar.gz"
$databaseTemp = '/tmp/' + $databaseName
$storageTemp = '/var/www/html/storage/framework/backup/' + $storageName
Invoke-BackupDocker ($composeArguments + @('exec', '-T', 'prod-postgres', 'sh', '-c', 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -h 127.0.0.1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom --no-owner --no-privileges --file "$1"', 'sh', $databaseTemp))
Invoke-BackupDocker ($composeArguments + @('cp', ('prod-postgres:' + $databaseTemp), (Join-Path $destination $databaseName)))
Invoke-BackupDocker ($composeArguments + @('exec', '-T', 'prod-backend', 'mkdir', '-p', '/var/www/html/storage/framework/backup'))
Invoke-BackupDocker ($composeArguments + @('exec', '-T', 'prod-backend', 'tar', '-C', '/var/www/html/storage/app', '-czf', $storageTemp, '.'))
Invoke-BackupDocker ($composeArguments + @('cp', ('prod-backend:' + $storageTemp), (Join-Path $destination $storageName)))
$manifest = @{
    createdAtUtc = [DateTime]::UtcNow.ToString('o')
    project = $ProjectName
    database = @{ file = $databaseName; sha256 = (Get-FileHash -LiteralPath (Join-Path $destination $databaseName) -Algorithm SHA256).Hash }
    storage = @{ file = $storageName; sha256 = (Get-FileHash -LiteralPath (Join-Path $destination $storageName) -Algorithm SHA256).Hash }
    note = 'Keep the matching environment/APP_KEY separately. Quiesce writes for a coordinated database/files snapshot.'
}
$manifestPath = Join-Path $destination "manifest-$stamp.json"
[IO.File]::WriteAllText($manifestPath, ($manifest | ConvertTo-Json -Depth 4), [Text.UTF8Encoding]::new($false))
# These are only the temporary files created by this invocation, never user data.
Invoke-BackupDocker ($composeArguments + @('exec', '-T', 'prod-postgres', 'rm', '-f', '--', $databaseTemp))
Invoke-BackupDocker ($composeArguments + @('exec', '-T', 'prod-backend', 'rm', '-f', '--', $storageTemp))
Write-Output "Backup complete: $manifestPath"
