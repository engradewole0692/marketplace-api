# Phase C — Communications Module — Production Deployment

## Forge deployment commands

```bash
cd /home/forge/YOUR_SITE
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=CommunicationSeeder --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart   # if QUEUE_CONNECTION != sync
```

## Scheduler (required for event reminders)

In Forge → Server → Scheduler, ensure this runs **every minute**:

```
* * * * * cd /home/forge/YOUR_SITE && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled commands include:
- `events:send-reminders` — hourly (24h window)
- `lms:publish-scheduled` — every 5 minutes
- `membership:notify-awaiting-interview-review` — every 15 minutes

Manual reminder test:
```bash
php artisan events:send-reminders --hours=24
```

## Backend environment variables (actual usage)

| Variable | Purpose |
|----------|---------|
| `APP_URL` | API base URL (emails, webhooks) |
| `APP_KEY` | Application encryption |
| `FRONTEND_URL` | Primary SPA origin (`config/app-frontend.php`, donation checkout URLs) |
| `FRONTEND_ORIGINS` | Optional comma-separated CORS/Sanctum origins |
| `DB_CONNECTION=mysql` | Production database |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | MySQL credentials |
| `MAIL_MAILER` | `smtp`, `log`, or `array` |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` | SMTP delivery |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Default sender (Communications settings override branding) |
| `QUEUE_CONNECTION` | `sync` (default) or `database`/`redis` if queue worker enabled |
| `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE` | Cross-origin SPA auth |
| `SANCTUM_STATEFUL_DOMAINS` | Derived from `FRONTEND_ORIGINS` / `FRONTEND_URL` |

Communications-specific config is stored in DB (`communication_settings`, routes, templates) — not `.env`.

## Frontend (Vercel) environment variables

| Variable | Purpose |
|----------|---------|
| `VITE_API_BASE_URL` | API prefix (e.g. `https://marketplaceapi.on-forge.com/api`) |
| `VITE_APP_URL` | Public site URL |
| `VITE_SITE_NAME` | Branding |

## Fresh install verification checklist

1. `php artisan migrate:fresh --seed` (staging only)
2. Confirm tables: `communication_settings`, `communication_routes`, `communication_templates`, `communication_email_logs`, `communication_idempotency_keys`
3. Login as super admin → `/admin/communications/settings`
4. Verify `communications.manage` permission on administrator role
5. Test-send from Templates page
6. Submit public contact form → check Email Logs

## Local PHP testing (Windows XAMPP without system php.ini)

XAMPP may ship without a loaded `php.ini`. Extension DLLs exist in `C:\xampp\php\ext\`.

Use the project-local config (does not modify XAMPP globally):

```powershell
$env:PHPRC = "C:\Users\princ\marketplace-api\scripts\php-test.ini"
php -m   # verify mbstring, pdo_sqlite, gd, zip
php artisan test tests/Feature/Communications
```

Or:

```powershell
.\scripts\run-phpunit-local.ps1 tests/Feature/Communications
```

**Option A — Docker (when Docker Desktop is running):**
```powershell
# Start Docker Desktop first, then:
cd C:\Users\princ\marketplace-api
.\scripts\run-phpunit-docker.ps1 tests/Feature/Communications
.\scripts\run-phpunit-docker.ps1 tests/Feature/Lms
.\scripts\run-phpunit-docker.ps1
```

**Option B — Repair XAMPP:**
1. Reinstall XAMPP PHP or copy `php.ini-development` → `php.ini`
2. Enable in php.ini: `extension=mbstring`, `extension=pdo_sqlite`, `extension=pdo_mysql`, `extension=openssl`, `extension=fileinfo`, `extension=curl`

**Option C — Standalone PHP via winget:**
```powershell
winget install PHP.PHP.8.2
# Add to PATH, enable extensions in its php.ini
```

## SMTP verification

Use Admin → Communications → Templates → **Send test** with real SMTP credentials configured.

If SMTP credentials unavailable: code path verified via `Mail::fake()` in PHPUnit; live delivery is **ENVIRONMENT VERIFICATION PENDING**.
