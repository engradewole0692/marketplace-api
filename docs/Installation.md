# Installation

## Requirements

| Component  | Version                              |
| ---------- | ------------------------------------ |
| PHP        | 8.3+ (production) · 8.2+ (local dev) |
| Composer   | 2.x                                  |
| PostgreSQL | 14+ (recommended)                    |
| Redis      | 6+ (production cache/queue)          |
| Node.js    | Not required for API-only operation  |

PHP extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo`, `pdo_pgsql` (or `pdo_mysql`), `tokenizer`, `xml`.

## Quick start (local)

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

### SQLite (fastest local setup)

`.env.example` defaults to SQLite. Ensure the database file exists:

```bash
touch database/database.sqlite   # Linux/macOS
# Windows: New-Item database/database.sqlite -ItemType File
php artisan migrate
php artisan serve
```

Verify:

```bash
curl http://127.0.0.1:8000/api/v1/health
```

### PostgreSQL (recommended)

Create the database and user, then configure `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=marketplace_ministers
DB_USERNAME=marketplace
DB_PASSWORD=your_secure_password
```

Run migrations:

```bash
php artisan migrate
```

### MySQL / MariaDB

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=marketplace_ministers
DB_USERNAME=marketplace
DB_PASSWORD=your_secure_password
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

## Redis (optional, recommended for staging/production)

```env
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

Start a queue worker:

```bash
php artisan queue:work --tries=3
```

## Scheduler

Add to crontab (production):

```cron
* * * * * cd /var/www/marketplace-ministers/backend && php artisan schedule:run >> /dev/null 2>&1
```

Verify locally:

```bash
php artisan schedule:list
php artisan app:heartbeat
```

## Running tests

```bash
php artisan test
# or
composer test
```

## Code style

```bash
./vendor/bin/pint
```

## Environment variables reference

| Variable           | Default                                   | Description                               |
| ------------------ | ----------------------------------------- | ----------------------------------------- |
| `APP_NAME`         | Marketplace Ministers API                 | Application name                          |
| `APP_VERSION`      | 1.0.0                                     | Reported in health endpoint               |
| `API_PREFIX`       | api                                       | URL prefix for all API routes             |
| `API_VERSION`      | v1                                        | API version segment                       |
| `DB_CONNECTION`    | sqlite (example) / pgsql (config default) | Database driver                           |
| `CACHE_STORE`      | database                                  | Cache driver                              |
| `QUEUE_CONNECTION` | database                                  | Queue driver                              |
| `MAIL_MAILER`      | log                                       | Mail driver (`log` until SMTP configured) |

## Troubleshooting

| Issue                         | Resolution                                                         |
| ----------------------------- | ------------------------------------------------------------------ |
| `could not find driver`       | Install `pdo_pgsql` or switch to `DB_CONNECTION=sqlite`            |
| Migration fails on cache/jobs | Ensure `DB_CONNECTION` database exists and credentials are correct |
| 500 on health check           | Run `php artisan migrate`; check `storage/logs/laravel.log`        |
| Permission errors             | `chmod -R 775 storage bootstrap/cache` and correct ownership       |
