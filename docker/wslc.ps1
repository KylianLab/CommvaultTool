<#
.SYNOPSIS
    Runs TapeTrack with WSL Containers (wslc), which has no compose support yet.

.EXAMPLE
    .\docker\wslc.ps1 build                 # builds tapetrack:dev and tapetrack:prod
    .\docker\wslc.ps1 test                  # test suite (no network)
    .\docker\wslc.ps1 discover --list       # endpoints that would be called
    .\docker\wslc.ps1 discover -vv          # discovery -> var\discovery\
#>
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('build', 'test', 'discover')]
    [string] $Action,

    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $Arguments = @()
)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
Push-Location $root
try {
    switch ($Action) {
        'build' {
            wslc build -t tapetrack:dev --target dev .
            if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
            wslc build -t tapetrack:prod --target prod .
        }
        'test' {
            wslc run --rm -e COMMVAULT_CLIENT=fake tapetrack:dev @Arguments
        }
        'discover' {
            foreach ($required in 'docker\tapetrack.env', 'docker\secrets\commvault_password') {
                if (-not (Test-Path $required)) { throw "Fichier manquant : $required (voir README)." }
            }
            New-Item -ItemType Directory -Force var\discovery, var\log, var\commvault | Out-Null
            $p = $root -replace '\\', '/'
            # Same mounts as compose.yaml; docker/secrets replaces compose secrets.
            wslc run --rm --env-file docker/tapetrack.env `
                -v "$p/docker/secrets:/run/secrets" `
                -v "$p/docker/certs:/certs" `
                -v "$p/var/discovery:/app/var/discovery" `
                -v "$p/var/log:/app/var/log" `
                -v "$p/var/commvault:/app/var/commvault" `
                tapetrack:prod app:commvault:discover @Arguments
        }
    }
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
