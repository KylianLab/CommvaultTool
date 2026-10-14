<#
.SYNOPSIS
    Exports the CA chain of the CommServe certificate to docker/certs/ca.pem.

.DESCRIPTION
    Connects to the Command Center over TLS, takes the server certificate and
    lets Windows build its chain from the local trust store (where a
    domain-joined PC already has the internal AD CS root). Every certificate
    of the chain except the server's own is then written in PEM format, which
    the Linux container can use as COMMVAULT_CA_BUNDLE.

    Only public certificates are exported: no private key, nothing secret.

.EXAMPLE
    .\docker\export-ca.ps1 -CommServe commserve.domaine.local
#>
param(
    [Parameter(Mandatory = $true)][string] $CommServe,
    [int] $Port = 443,
    [string] $OutFile = (Join-Path $PSScriptRoot 'certs\ca.pem')
)

$ErrorActionPreference = 'Stop'

# Accept the certificate during this handshake only to READ it; trust is
# evaluated below by X509Chain against the Windows store.
$tcp = [Net.Sockets.TcpClient]::new($CommServe, $Port)
try {
    $callback = [Net.Security.RemoteCertificateValidationCallback] { param($s, $c, $ch, $e) $true }
    $ssl = [Net.Security.SslStream]::new($tcp.GetStream(), $false, $callback)
    $ssl.AuthenticateAsClient($CommServe)
    $server = [Security.Cryptography.X509Certificates.X509Certificate2]::new($ssl.RemoteCertificate)
} finally {
    $tcp.Dispose()
}

$chain = [Security.Cryptography.X509Certificates.X509Chain]::new()
$chain.ChainPolicy.RevocationMode = 'NoCheck'
$trusted = $chain.Build($server)

Write-Host "Certificat serveur : $($server.Subject)"
Write-Host "  emis par         : $($server.Issuer)"
Write-Host "  expire le        : $($server.NotAfter)"

if (-not $trusted) {
    Write-Warning ("Windows ne fait pas confiance a ce certificat : " + (($chain.ChainStatus | ForEach-Object { $_.StatusInformation.Trim() }) -join ' / '))
    Write-Warning "Le fichier est quand meme ecrit, mais verifiez la chaine avec l'equipe PKI."
}

$elements = @($chain.ChainElements | Select-Object -Skip 1)
if ($elements.Count -eq 0) {
    throw "Aucun certificat de CA trouve (certificat auto-signe ?)."
}

$pem = foreach ($element in $elements) {
    $cert = $element.Certificate
    Write-Host "CA exportee        : $($cert.Subject) (expire le $($cert.NotAfter))"
    "# $($cert.Subject)`n-----BEGIN CERTIFICATE-----`n" +
        [Convert]::ToBase64String($cert.RawData, 'InsertLineBreaks').Replace("`r`n", "`n") +
        "`n-----END CERTIFICATE-----`n"
}

New-Item -ItemType Directory -Force (Split-Path $OutFile) | Out-Null
# UTF-8 without BOM, LF line endings: what OpenSSL in the container expects.
[IO.File]::WriteAllText($OutFile, ($pem -join ''), [Text.UTF8Encoding]::new($false))
Write-Host "Ecrit : $OutFile"
