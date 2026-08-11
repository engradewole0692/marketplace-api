# Phase E — Production Readiness

This document covers production deployment verification for Marketplace Ministers API + Kingdom Collective frontend.

## Architecture (unchanged)

```
School / Free Learning Category
  └── Programme Module (lms_program_modules)
        └── Course (lms_courses)
              └── Curriculum Section (lms_modules)
                    └── Lesson (lms_lessons)
```

- **Homepage Learning section:** API-driven course grids only. CMS may control copy/CTAs, not course records.
- **Communications:** Centralized routing, templates, logs, idempotency.
- **Commerce:** Course + school orders via donation gateway abstraction.

---

## Required environment variables

### Backend (Laravel Forge)

| Variable | Purpose |
|----------|---------|
| `APP_KEY` | Encryption (required) |
| `APP_ENV=production` | Production mode |
| `APP_DEBUG=false` | Must be false in production |
| `APP_URL` | API base URL |
| `FRONTEND_URL` | Primary SPA origin |
| `FRONTEND_ORIGINS` | CORS + Sanctum stateful domains |
| `DB_CONNECTION=mysql` | Production database |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | MySQL credentials |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | SMTP |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Sender identity |
| `QUEUE_CONNECTION` | `sync`, `database`, or `redis` |
| `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE` | Cross-origin SPA auth |
| `SANCTUM_STATEFUL_DOMAINS_EXTRA` | Additional stateful hosts |

### Frontend (Vercel)

| Variable | Purpose |
|----------|---------|
| `VITE_API_BASE_URL` | API prefix (e.g. `https://api.example.com/api`) |
| `VITE_APP_URL` | Public site URL |

---

## Preflight command (read-only)

```powershell
$env:PHPRC = "C:\Users\princ\marketplace-api\scripts\php-test.ini"
php artisan production:preflight
```

JSON output:

```bash
php artisan production:preflight --json
```

Reports **PASS / WARN / FAIL** for application, database, auth, mail, queue, and scheduler checks. Does **not** modify data or print secrets.

---

## Migration & seed commands (staging/production)

```bash
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan db:seed --class=CommunicationSeeder --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

Full fresh install (staging only — **never on production with live data**):

```bash
php artisan migrate:fresh --seed --force
```

---

## Local PHPUnit (SQLite via php-test.ini)

```powershell
$env:PHPRC = "C:\Users\princ\marketplace-api\scripts\php-test.ini"
php artisan test
php artisan test tests/Feature/Lms
php artisan test tests/Feature/Communications
php artisan test tests/Feature/Production
```

---

## MySQL verification procedure

**Status: ⏳ REQUIRES EXTERNAL VERIFICATION** unless tests are run against MySQL.

### Option A — Docker script (when Docker Desktop is running)

```powershell
.\scripts\run-phpunit-mysql.ps1 tests/Feature/Lms
.\scripts\run-phpunit-mysql.ps1 tests/Feature/Communications
```

### Option B — Forge staging

Run PHPUnit against staging MySQL credentials after `php artisan migrate --force`.

**Do not claim MySQL verification passed unless tests actually execute against MySQL.**

---

## SMTP verification

**Status: ⏳ REQUIRES EXTERNAL VERIFICATION**

1. Configure SMTP on staging.
2. Admin → Communications → Templates → Test Send.
3. Confirm email delivery + `communication_email_logs` entry.

PHPUnit uses `Mail::fake()` — live SMTP is not verified by automated tests.

---

## Scheduler (Forge)

Every minute:

```
* * * * * cd /home/forge/YOUR_SITE && php artisan schedule:run >> /dev/null 2>&1
```

Registered commands:

- `events:send-reminders` — hourly
- `lms:publish-scheduled` — every 5 minutes
- `membership:notify-awaiting-interview-review` — every 15 minutes

Manual test (staging):

```bash
php artisan events:send-reminders --hours=24
```

---

## Verification status legend

| Status | Meaning |
|--------|---------|
| ✅ VERIFIED | Confirmed in this environment |
| ⚠️ PARTIAL | Some checks pass; gaps documented |
| ❌ FAILED | Blocking issue found |
| ⏳ REQUIRES EXTERNAL VERIFICATION | Needs staging/production/MySQL/SMTP |

---

## Phase E verification snapshot (local)

| Area | Status | Notes |
|------|--------|-------|
| PHPUnit (SQLite) | ✅ VERIFIED | 268 passed, 1756 assertions (includes Production + Event reminder tests) |
| LMS tests | ✅ VERIFIED | 75 passed (includes school offline reject + thumbnail tests) |
| Communications tests | ✅ VERIFIED | 16 passed |
| Production preflight command | ✅ VERIFIED | `php artisan production:preflight` read-only |
| Fresh DB seed simulation | ✅ VERIFIED | `FreshDatabaseSeederTest` via RefreshDatabase |
| MySQL migration compatibility | ⚠️ PARTIAL | Schema audit passes; live MySQL tests not executed |
| MySQL feature tests | ⏳ REQUIRES EXTERNAL VERIFICATION | Use `scripts/run-phpunit-mysql.ps1` when Docker is running |
| SMTP live delivery | ⏳ REQUIRES EXTERNAL VERIFICATION | PHPUnit uses `Mail::fake()` |
| Forge scheduler cron | ⏳ REQUIRES EXTERNAL VERIFICATION | Command registered; remote cron not verified |
| Staging deployment | ⏳ REQUIRES EXTERNAL VERIFICATION | — |
| Frontend typecheck/build | ✅ VERIFIED | `npm run typecheck` + `npm run build` |

---

## Production deployment checklist

### Forge Backend

- [ ] `git pull origin main`
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `php artisan migrate --force`
- [ ] `php artisan db:seed --class=RolePermissionSeeder --force`
- [ ] `php artisan db:seed --class=CommunicationSeeder --force`
- [ ] `php artisan production:preflight` (expect 0 FAIL)
- [ ] `php artisan config:cache`
- [ ] `php artisan route:cache`
- [ ] `php artisan view:cache`
- [ ] `php artisan storage:link`
- [ ] Enable scheduler: `* * * * * php artisan schedule:run`
- [ ] Start queue worker if `QUEUE_CONNECTION` ≠ `sync`
- [ ] Verify file permissions on `storage/` and `bootstrap/cache/`

### Vercel Frontend

- [ ] Set `VITE_API_BASE_URL` to production API (e.g. `https://marketplaceapi.on-forge.com/api`)
- [ ] Set `VITE_APP_URL` to public site URL
- [ ] Confirm production build succeeds
- [ ] Deploy and smoke-test `/courses`, `/schools/{slug}`, `/free-categories/{slug}`

### Sanctum / Session

- [ ] `APP_URL` matches Forge API host
- [ ] `FRONTEND_URL` + `FRONTEND_ORIGINS` match Vercel origin(s)
- [ ] Cross-origin: `SESSION_SAME_SITE=none`, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN` null on API host
- [ ] `SANCTUM_STATEFUL_DOMAINS_EXTRA` includes API host if needed
- [ ] Test learner login → dashboard → logout (local state clears even if API fails)

### Mail

- [ ] Configure SMTP (`MAIL_MAILER=smtp`, host, port, credentials)
- [ ] Set `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`
- [ ] Admin → Communications → Templates → Test Send
- [ ] Confirm row in `communication_email_logs`

### Payments

- [ ] Configure `payment_provider_configs` or `DONATIONS_{PROVIDER}_SECRET` env vars
- [ ] Set webhook secrets (`DONATIONS_{PROVIDER}_WEBHOOK_SECRET`)
- [ ] Test online checkout on staging
- [ ] Test offline payment confirm + reject (course and school)
- [ ] Verify communications fire on confirm/reject/refund

