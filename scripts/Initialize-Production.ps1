#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][uri]$AppUrl,
    [string]$EnvironmentFile = '.env.production',
    [string]$TlsDirectory = './.secrets/production/tls',
    [ValidatePattern('^[a-z0-9][a-z0-9-]{2,45}$')][string]$ProductionName = 'dawtfg-production'
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
if (-not $AppUrl.IsAbsoluteUri -or $AppUrl.Scheme -ne 'https' -or $AppUrl.UserInfo -or $AppUrl.Query -or $AppUrl.Fragment -or $AppUrl.AbsolutePath -ne '/' -or $AppUrl.Host -notmatch '^[a-zA-Z0-9.-]+$') {
    throw 'AppUrl must be a canonical HTTPS origin without credentials, path, query or fragment.'
}
if ($TlsDirectory -match '[\r\n]') { throw 'Invalid TLS directory.' }
$target = [IO.Path]::GetFullPath((Join-Path $projectRoot $EnvironmentFile))
if (Test-Path -LiteralPath $target) {
    Write-Output 'Environment already exists; no settings or secrets were changed.'
    return
}
function New-ProductionSecret {
    $bytes = New-Object byte[] 32
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes); return [Convert]::ToBase64String($bytes) }
    finally { $generator.Dispose() }
}
$values = @{
    PRODUCTION_APP_HOST = $AppUrl.Host
    PRODUCTION_APP_URL = $AppUrl.GetLeftPart([UriPartial]::Authority)
    PRODUCTION_APP_KEY = 'base64:' + (New-ProductionSecret)
    PRODUCTION_DB_PASSWORD = New-ProductionSecret
    PRODUCTION_DB_ADMIN_PASSWORD = New-ProductionSecret
    PRODUCTION_TLS_DIR = $TlsDirectory
    PRODUCTION_DB_VOLUME = $ProductionName + '-postgres'
    PRODUCTION_STORAGE_VOLUME = $ProductionName + '-storage'
}
$content = [IO.File]::ReadAllText((Join-Path $projectRoot '.env.production.example'))
foreach ($name in $values.Keys) {
    $pattern = '(?m)^' + [regex]::Escape($name) + '=.*$'
    $line = $name + '=' + $values[$name]
    $content = [regex]::Replace($content, $pattern, [System.Text.RegularExpressions.MatchEvaluator]{ param($match) $line })
}
$suffix = if ($AppUrl.IsDefaultPort) { '' } else { ':' + $AppUrl.Port }
$content += "`nPRODUCTION_STATEFUL_PORT_SUFFIX=$suffix`n"
[IO.Directory]::CreateDirectory((Split-Path -Parent $target)) | Out-Null
[IO.File]::WriteAllText($target, $content, [Text.UTF8Encoding]::new($false))
Write-Output 'Separate production environment generated. Secrets were not printed. Keep the file private and backed up.'
