# Run PHPUnit with project-local php.ini (Windows XAMPP without system php.ini).
# Usage: .\scripts\run-phpunit-local.ps1 [test path]

param(
  [string]$TestPath = "tests"
)

$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$Ini = Join-Path $Root "scripts\php-test.ini"
$env:PHPRC = $Ini

Push-Location $Root
try {
  php artisan test $TestPath
  exit $LASTEXITCODE
} finally {
  Pop-Location
}
