# PRODUCTION_READINESS_REPORT.md

Marketplace Ministers — consolidated production readiness audit  
Repos audited: `kingdom-collective` (SPA) · `marketplace-api` (Laravel Forge API)  
Date: 2026-08-07

---

## Verdict

**PARTIAL — deployable after env + seed + deploy of the fixes in this report.**  
Cross-origin Sanctum, counsellor/public auth crashes, fake event fallbacks, unsafe production seeders, and unconfigured online payment stubs were the main blockers. Code fixes are applied in both repos; Forge still requires correct `.env` and selective seeding.

---

## Frontend (`kingdom-collective`) — findings & fixes

| Severity | Issue | Fix |
|----------|--------|-----|
| BLOCKER | `/counsellor` had no `AuthProvider` → crash on login/shell | Wrapped like admin/portal/learn |
| BLOCKER | `/counseling` called `useAuth()` without provider | `AuthProvider` on `/_site` |
| BLOCKER | Default `VITE_API_BASE_URL=/api` wrong for Forge API host | Document absolute URL (ops); prior CSRF client already supports absolute API |
| HIGH | CMS form export hardcoded `/api...` | Uses `buildApiUrl()` |
| HIGH | Gallery/resources/vlog loaders threw on API failure | Catch → empty catalog |
| HIGH | Production showed canned `FEATURED_EVENTS` when API empty | Disabled fake events in `PROD` |
| HIGH | `/storage/...` media resolved on SPA origin | Prefix with API origin via `getApiOrigin()` |
| MEDIUM | Learner dashboard assumed `continue_learning` always present | Optional chaining |
| OK | Logout already clears SPA session + `queryClient` on all shells | No change needed |

### Frontend files changed
- `src/routes/counsellor.tsx`
- `src/routes/_site.tsx`
- `src/services/cms/cms-admin.service.ts`
- `src/lib/cms/assets.ts`
- `src/routes/_site.gallery.tsx`
- `src/routes/_site.resources.tsx`
- `src/routes/_site.vlog.tsx`
- `src/components/home/EventsSection.tsx`
- `src/routes/_site.events.index.tsx`
- `src/routes/_site.events.$slug.tsx`
- `src/lib/events/mapEventRecord.ts`
- `src/features/lms/LearnerExperienceDashboard.tsx`

### Required SPA build env
```env
VITE_API_BASE_URL=https://marketplaceapi.on-forge.com/api
VITE_APP_URL=https://YOUR_FRONTEND_ORIGIN
API_INTERNAL_URL=https://marketplaceapi.on-forge.com/api
```

---

## Backend (`marketplace-api`) — findings & fixes

| Severity | Issue | Fix |
|----------|--------|-----|
| BLOCKER | `DatabaseSeeder` always ran demo seeders (`password`) | Demo seeders skipped when `APP_ENV=production` |
| BLOCKER | Online gateways invented fake checkout redirects | Require `payment_provider_configs` or `DONATIONS_{PROVIDER}_SECRET` |
| HIGH | `administrator` lacked CMS permissions | Added `cms.*` / media / blog / gallery / resources |
| HIGH | No LMS / Region reference seeders | Added `LmsReferenceSeeder`, `RegionSeeder` |
| HIGH | No counsellor role | Added role + permissions |
| HIGH | Conflicting SESSION guidance in `.env.example` | Clarified cross-origin `SameSite=none` |
| HIGH | `SuperAdminSeeder` ≠ `app:create-super-admin` | Aligned identity; skip seeder in production |
| MEDIUM | `MemberMinistryAssignment` missing fillable/relations | Extended model |
| MEDIUM | `CmsCountry` missing `regions()` | Added relation |

### Backend files changed
- `app/Modules/Donations/Gateways/AbstractOnlineGateway.php`
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/SuperAdminSeeder.php`
- `database/seeders/RoleSeeder.php`
- `database/seeders/RolePermissionSeeder.php`
- `database/seeders/RegionSeeder.php` *(new)*
- `database/seeders/LmsReferenceSeeder.php` *(new)*
- `app/Models/MemberMinistryAssignment.php`
- `app/Modules/Cms/Models/CmsCountry.php`
- `.env.example`

### Forge seed order (fresh MySQL)
```bash
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\RoleSeeder --force
php artisan db:seed --class=Database\\Seeders\\PermissionSeeder --force
php artisan db:seed --class=Database\\Seeders\\RolePermissionSeeder --force
php artisan db:seed --class=Database\\Seeders\\CmsSeeder --force
php artisan db:seed --class=Database\\Seeders\\RegionSeeder --force
php artisan db:seed --class=Database\\Seeders\\LmsReferenceSeeder --force
php artisan db:seed --class=Database\\Seeders\\DonationsSeeder --force
php artisan db:seed --class=Database\\Seeders\\EventsSeeder --force
php artisan db:seed --class=Database\\Seeders\\CounsellingSeeder --force
php artisan db:seed --class=Database\\Seeders\\ApplicationSettingSeeder --force
php artisan app:create-super-admin
```
Or: `php artisan db:seed --force` with `APP_ENV=production` (skips demo users) then `app:create-super-admin`.

### Required API `.env`
```env
APP_URL=https://marketplaceapi.on-forge.com
FRONTEND_URL=https://YOUR_FRONTEND_ORIGIN
FRONTEND_ORIGINS=https://YOUR_FRONTEND_ORIGIN
SESSION_DOMAIN=
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=none
TRUSTED_PROXIES=*
SANCTUM_STATEFUL_DOMAINS_EXTRA=marketplaceapi.on-forge.com
```

---

## Remaining / intentional gaps (not removed)

| Item | Status |
|------|--------|
| Live Stripe/Paystack/Flutterwave SDK integration | Architecture kept; checkout blocked until provider credentials configured |
| Region REST admin API | Table + seeder present; dedicated CRUD routes still future work |
| LMS course content | Categories/levels seeded; courses created via admin |
| Counsellor user accounts | Role exists; assign via IAM to real users |
| Full latency/perf measurement | Not part of this audit |

---

## Module checklist after deploy + seed

| Module | Expected |
|--------|----------|
| IAM / Admin login | Works with `app:create-super-admin` |
| CMS public + admin | CmsSeeder pages/countries/ministries |
| Members | Empty list OK; create via admin |
| Events | Sample from EventsSeeder or empty |
| Donations (offline) | Funds/methods from DonationsSeeder |
| Donations (online) | 422 until provider configured |
| Counselling | Categories/services seeded |
| LMS | Categories/levels; empty course catalogue until authored |
| Portals | Permissions from RolePermissionSeeder |
| Counsellor portal | Login works; needs counsellor role on user |
