#requires -Version 5.1
[CmdletBinding()]
param([switch]$KeepRunning)

$ErrorActionPreference = 'Stop'
$testCompose = @('-p', 'dawtfg-auth-tests', '-f', 'compose.testing.yaml')
$testsCompleted = $false

function Invoke-TestDocker {
    param([string[]]$Arguments)
    & docker @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Isolated test command failed with exit code $LASTEXITCODE."
    }
}

Push-Location (Split-Path -Parent $PSScriptRoot)
try {
    # The explicit project and distinct service names prevent development collisions.
    Invoke-TestDocker (@('compose') + $testCompose + @('config', '--quiet'))
    Invoke-TestDocker (@('compose') + $testCompose + @('up', '-d', '--wait', '--wait-timeout', '180', 'test-backend'))
    Invoke-TestDocker (@('compose') + $testCompose + @('exec', '-T', 'test-backend', 'composer', 'test'))
    $testsCompleted = $true
} finally {
    try {
        if (-not $KeepRunning) {
            & docker compose @testCompose stop
            if ($LASTEXITCODE -ne 0) {
                if ($testsCompleted) { throw 'Tests passed, but the isolated project could not be stopped.' }
                Write-Warning 'Could not stop the isolated test project; inspect Docker before continuing.'
            }
        }
    } finally {
        Pop-Location
    }
}
