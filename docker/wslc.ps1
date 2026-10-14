<#
.SYNOPSIS
    Runs TapeTrack with WSL Containers (wslc), which has no compose support yet.

.EXAMPLE
    .\docker\wslc.ps1 build                 # builds tapetrack:dev and tapetrack:prod
    .\docker\wslc.ps1 test                  # test suite (no network)
    .\docker\wslc.ps1 discover --list       # endpoints that would be called
    .\docker\wslc.ps1 discover -vv          # discovery -> var\discovery\
    .\docker\wslc.ps1 summary               # structure of all dumps -> var\discovery\_schema.md
    .\docker\wslc.ps1 app                   # web preview on http://localhost:8080, demo data
    .\docker\wslc.ps1 app -Real             # web preview synchronised with the real CommServe
    .\docker\wslc.ps1 app -Port 18080       # another local port (if 8080 is reserved / used)
#>
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('build', 'test', 'discover', 'summary', 'app')]
    [string] $Action,

    # 'app' only: use the real CommServe (docker\tapetrack.env + secrets) instead of the demo data
    [switch] $Real,

    # 'app' only: port on this PC. Some ranges are reserved by Hyper-V / WSL
    # ("netsh interface ipv4 show excludedportrange protocol=tcp").
    [int] $Port = 8080,

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
                tapetrack:prod php bin/console app:commvault:discover @Arguments
        }
        'app' {
            $p = $root -replace '\\', '/'
            New-Item -ItemType Directory -Force var\data, var\log, var\commvault | Out-Null
            # SQLite preview database: demo and real data must not mix
            $db = if ($Real) { 'tapetrack-real.db' } else { 'tapetrack-demo.db' }
            $common = @(
                '-p', "${Port}:8000",
                '-e', "DATABASE_URL=sqlite:////app/var/data/$db",
                '-e', 'MESSENGER_TRANSPORT_DSN=sync://',
                '-v', "$p/var/data:/app/var/data",
                '-v', "$p/var/log:/app/var/log"
            )
            if ($Real) {
                foreach ($required in 'docker\tapetrack.env', 'docker\secrets\commvault_password') {
                    if (-not (Test-Path $required)) { throw "Fichier manquant : $required (voir README)." }
                }
                $common += @('--env-file', 'docker/tapetrack.env',
                    '-v', "$p/docker/secrets:/run/secrets", '-v', "$p/docker/certs:/certs",
                    '-v', "$p/var/commvault:/app/var/commvault")
            } else {
                $common += @('-e', 'COMMVAULT_CLIENT=fake')
            }
            Write-Host "TapeTrack : http://localhost:$Port  (Ctrl+C pour arreter)"
            wslc run --rm -it @common tapetrack:prod sh docker/preview.sh
        }
        'summary' {
            $p = $root -replace '\\', '/'
            wslc run --rm -v "$p/var/discovery:/app/var/discovery" `
                tapetrack:prod php bin/console app:commvault:discovery-summary @Arguments
        }
    }
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
