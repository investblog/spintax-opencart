# Run the Spintax engine PHPUnit harness inside the web container (PowerShell).
# This stand is PowerShell-primary; scripts/test.sh is the Bash equivalent.
# Any args are forwarded to phpunit, e.g.:
#   scripts/test.ps1 --filter Parser
#   scripts/test.ps1 --testdox
#
# Note: under PowerShell the in-container /opt/... paths are passed verbatim
# (unlike Git Bash, which MSYS-mangles them — hence the separate wrappers).

$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot

Push-Location $repo
try {
    if (-not (Test-Path (Join-Path $repo 'extension\vendor'))) {
        Write-Host 'vendor/ missing - installing dev dependencies...'
        docker run --rm -v "$repo\extension:/app" -w /app composer:2 install --no-interaction --no-progress
        if ($LASTEXITCODE -ne 0) { throw "composer install failed ($LASTEXITCODE)" }
    }

    docker compose exec -T web php /opt/spintax/vendor/bin/phpunit -c /opt/spintax/phpunit.xml.dist @args
    exit $LASTEXITCODE
}
finally {
    Pop-Location
}
