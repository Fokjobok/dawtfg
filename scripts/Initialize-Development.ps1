#requires -Version 5.1
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$environmentPath = Join-Path $projectRoot '.env'
$examplePath = Join-Path $projectRoot '.env.example'

function New-DevelopmentSecret {
    $bytes = New-Object byte[] 32
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($bytes)
        return [Convert]::ToBase64String($bytes)
    }
    finally {
        $generator.Dispose()
    }
}

if (Test-Path -LiteralPath $environmentPath) {
    $content = [System.IO.File]::ReadAllText($environmentPath)
}
else {
    $content = [System.IO.File]::ReadAllText($examplePath)
}

$changed = -not (Test-Path -LiteralPath $environmentPath)
foreach ($name in @('APP_KEY', 'DB_PASSWORD')) {
    $pattern = '(?m)^' + [regex]::Escape($name) + '=(.*)\r?$'
    $match = [regex]::Match($content, $pattern)
    if ($match.Success -and $match.Groups[1].Value.Trim().Trim('"').Trim("'") -ne '') {
        continue
    }

    $value = New-DevelopmentSecret
    if ($name -eq 'APP_KEY') {
        $value = 'base64:' + $value
    }

    $line = $name + '=' + $value
    if ($match.Success) {
        $content = [regex]::Replace($content, $pattern, $line)
    }
    else {
        $content = $content.TrimEnd() + "`n" + $line + "`n"
    }
    $changed = $true
}

if ($changed) {
    [System.IO.File]::WriteAllText($environmentPath, $content, [System.Text.UTF8Encoding]::new($false))
    Write-Output 'Development .env prepared. Existing nonempty secrets were preserved.'
}
else {
    Write-Output 'Development .env already configured; no changes made.'
}

Write-Output 'Secrets were not printed. Keep .env outside version control.'
