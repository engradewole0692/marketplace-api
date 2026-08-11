# Run PHPUnit against disposable MySQL 8 (requires Docker Desktop running).
# Does NOT modify the local SQLite dev database.

param(
  [string]$TestPath = "tests/Feature/Lms"
)

$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$env:PHPRC = Join-Path $Root "scripts\php-test.ini"

$container = "marketplace-mysql-test"
$port = 3307

Write-Host "Starting disposable MySQL 8 container on port $port..."
docker run --rm -d --name $container `
  -e MYSQL_ROOT_PASSWORD=secret `
  -e MYSQL_DATABASE=marketplace_test `
  -p "${port}:3306" `
  mysql:8.0 | Out-Null

if ($LASTEXITCODE -ne 0) {
  Write-Error "Docker is not running or MySQL container failed to start."
  exit 1
}

Start-Sleep -Seconds 15

$env:DB_CONNECTION = "mysql"
$env:DB_HOST = "127.0.0.1"
$env:DB_PORT = "$port"
$env:DB_DATABASE = "marketplace_test"
$env:DB_USERNAME = "root"
$env:DB_PASSWORD = "secret"

Push-Location $Root
try {
  php artisan migrate --force
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
  php artisan test $TestPath
  exit $LASTEXITCODE
} finally {
  Pop-Location
  docker stop $container 2>$null | Out-Null
}
