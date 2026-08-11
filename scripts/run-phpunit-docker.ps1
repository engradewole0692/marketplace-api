# Run PHPUnit inside Docker with required PHP extensions (mbstring, pdo_sqlite).
# Requires Docker Desktop running.
# Usage: .\scripts\run-phpunit-docker.ps1 [test path]

param(
  [string]$TestPath = "tests"
)

$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)

docker run --rm `
  -v "${Root}:/app" `
  -w /app `
  php:8.2-cli `
  bash -lc @"
set -e
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq git unzip libzip-dev libsqlite3-dev > /dev/null
docker-php-ext-install pdo_sqlite zip > /dev/null
if [ ! -f vendor/bin/phpunit ]; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
  composer install --no-interaction --prefer-dist
fi
php artisan test $TestPath
"@
