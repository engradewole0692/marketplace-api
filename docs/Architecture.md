# Architecture

## Overview

The Marketplace Ministers API is an enterprise Laravel 12 backend that serves the completed TanStack Start React frontend. Phase 1A establishes the foundation: versioned REST API, standardized responses, modular domain layout, and production-oriented configuration—without business modules or authentication.

## System context

```
┌─────────────────────────────────────────────────────────────────┐
│  TanStack Start Frontend (kingdom-collective/)                  │
│  SSR marketing site · src/services/api.ts integration point     │
└────────────────────────────┬────────────────────────────────────┘
                             │ HTTPS /api/v1/*
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│  Nginx (reverse proxy)                                          │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│  Laravel API (backend/)                                         │
│  PHP-FPM 8.3+ · Sanctum (installed, auth in Phase 1B+)        │
└──────┬──────────────────┬──────────────────┬────────────────────┘
       │                  │                  │
       ▼                  ▼                  ▼
 PostgreSQL           Redis              Local/S3
 (primary DB)      (cache/queue)         (media)
```

## Layered application design

| Layer       | Location                              | Responsibility                   |
| ----------- | ------------------------------------- | -------------------------------- |
| Routes      | `routes/api.php`, `routes/api/v1.php` | HTTP entry, versioning           |
| Controllers | `app/Http/Controllers/Api/V1/`        | Thin HTTP adapters               |
| Actions     | `app/Actions/`                        | Single-purpose use cases         |
| Services    | `app/Services/`                       | Reusable domain logic            |
| DTOs        | `app/DTOs/`                           | Typed data transfer objects      |
| Modules     | `app/Modules/{Name}/`                 | Bounded contexts (future)        |
| Support     | `app/Support/Api/`                    | Cross-cutting API infrastructure |

## Request lifecycle

1. Request hits Nginx → PHP-FPM → Laravel `api` middleware group.
2. `ForceJsonResponse` sets `Accept: application/json`.
3. Versioned route under `/api/v1/*` resolves to a controller.
4. Controller delegates to an Action or Service.
5. `ApiResponderContract` formats the JSON envelope.
6. Exceptions on API routes are rendered by `ApiExceptionHandler`.

## API response contract

All `/api/*` responses use a consistent envelope:

```json
{
    "success": true,
    "data": {},
    "message": "Optional human-readable message",
    "code": "ERROR_CODE",
    "meta": {
        "timestamp": "2026-06-29T12:00:00+00:00",
        "request_id": "uuid"
    },
    "errors": {}
}
```

- `success` — boolean outcome
- `data` — payload on success
- `message` — optional description
- `code` — machine-readable error code (`ApiErrorCode` enum)
- `meta` — request metadata (configurable)
- `errors` — validation or field errors

## Module strategy

Twenty-two bounded contexts are scaffolded under `app/Modules/` (CMS, Users, Membership, Ministries, etc.). Each module will eventually own:

- Actions, Services, DTOs, Contracts
- HTTP layer (Controllers, Requests, Resources)
- Policies, Events, Listeners, Jobs, Notifications

Module service providers extend `AbstractModuleServiceProvider` and are registered as features are implemented.

## Technology choices

| Concern         | Choice                        | Notes                                            |
| --------------- | ----------------------------- | ------------------------------------------------ |
| Framework       | Laravel 12                    | Latest stable compatible with PHP 8.2+           |
| Auth (future)   | Laravel Sanctum               | Installed; tokens migration deferred to Phase 1B |
| Database        | PostgreSQL (default config)   | MySQL/SQLite supported via `DB_CONNECTION`       |
| Cache / Queue   | Database (dev) → Redis (prod) | Supervisor manages workers                       |
| Coding standard | PSR-12                        | Enforced via Laravel Pint                        |

## Phase boundaries

| Phase            | Scope                                             |
| ---------------- | ------------------------------------------------- |
| **1A (current)** | Foundation, health endpoint, migrations, docs     |
| **1B+**          | Sanctum auth, personal_access_tokens, module APIs |
| **2+**           | CMS, forms, membership, per-module features       |

## Frontend integration

The React frontend calls `/api/v1/*` via `src/services/api.ts`. Nginx proxies `/api` to this Laravel application. No frontend routes or UI are modified by the backend.
