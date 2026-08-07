# Phase 1A — Enterprise Backend Foundation

## Objective

Deliver a production-ready Laravel API foundation that serves the completed TanStack Start frontend, without implementing authentication or business modules.

## Scope delivered

### Application scaffold

- Laravel 12 (`marketplace-ministers/api`) with PHP 8.2+ compatibility (8.3+ on production VPS)
- Laravel Sanctum installed and configured (token migration deferred to Phase 1B)
- REST API under `/api/v1`

### Enterprise architecture

| Component     | Status                                                           |
| ------------- | ---------------------------------------------------------------- |
| Actions       | `GetHealthStatusAction`                                          |
| Services      | `HealthCheckService`                                             |
| DTOs          | `ApiResponseData`, `HealthStatusData`                            |
| Contracts     | `ApiResponderContract`, `ServiceContract`                        |
| Enums         | `ApiErrorCode`                                                   |
| Exceptions    | `ApiException`, `BusinessException`, `ResourceNotFoundException` |
| Traits        | `ApiResponses`                                                   |
| Helpers       | `ApiHelper`                                                      |
| Support       | `ApiResponse`, `ApiExceptionHandler`                             |
| Jobs / Events | `BaseJob`, `BaseEvent`                                           |
| Modules       | 22 bounded contexts scaffolded                                   |

### Configuration

| Area               | Implementation                                                |
| ------------------ | ------------------------------------------------------------- |
| Environment        | `.env.example` with PostgreSQL, Redis, API, mail placeholders |
| Logging            | Stack + dedicated `api` daily channel                         |
| Cache              | Database default; Redis documented                            |
| Queues             | Database default; Redis + Supervisor documented               |
| Scheduler          | `app:heartbeat` daily via `routes/console.php`                |
| Storage            | Local disk; S3 placeholders in env                            |
| Mail               | `log` driver placeholder                                      |
| API versioning     | `config/api.php`, `/api/v1` routes                            |
| Global API format  | `ApiResponse` envelope                                        |
| Exception handling | `ApiExceptionHandler` in `bootstrap/app.php`                  |

### Database migrations (framework only)

- `users`
- `password_reset_tokens`
- `sessions`
- `cache` / `cache_locks`
- `jobs` / `job_batches` / `failed_jobs`

**Excluded:** Sanctum `personal_access_tokens` (Phase 1B), all business tables.

### API endpoints

| Method | Path             | Description                               |
| ------ | ---------------- | ----------------------------------------- |
| GET    | `/api/v1/health` | Application health with DB/cache checks   |
| GET    | `/up`            | Laravel framework health (load balancers) |

### Tests

- `tests/Feature/Api/HealthEndpointTest.php` — validates response structure and success state

### Documentation

- `docs/Architecture.md`
- `docs/Installation.md`
- `docs/Deployment.md`
- `docs/FolderStructure.md`
- `docs/CodingStandards.md`
- `docs/Phase1A.md` (this file)

## Explicitly out of scope

- User registration / login / Sanctum token issuance
- CMS, ministries, donations, or any business module APIs
- Frontend changes (React/TanStack Start remains source of truth)
- Nginx/SSL provisioning on live server (documented only)

## Verification commands

```bash
cd backend
composer install
php artisan migrate
php artisan test
php artisan serve
curl http://127.0.0.1:8000/api/v1/health
```

## Next phase (1B) preview

1. Publish and run Sanctum `personal_access_tokens` migration
2. Add `HasApiTokens` to User model
3. Implement register/login/logout/me endpoints
4. Wire frontend `apiRequest()` to authenticated flows
5. Begin CMS module as first business domain

## Assumptions

- Backend lives in `backend/` within the monorepo
- Nginx proxies `/api/*` from the frontend domain to Laravel
- PostgreSQL is the production database; SQLite used for local zero-config dev
- PHP 8.3+ on AlmaLinux 8 production; local XAMPP may run PHP 8.2
