# Folder Structure

Enterprise layout for the Marketplace Ministers Laravel API. Business modules are scaffolded but not yet implemented.

```
backend/
├── app/
│   ├── Actions/                    # Single-purpose use cases
│   │   └── Health/
│   │       └── GetHealthStatusAction.php
│   ├── Contracts/                  # Interfaces for DI
│   │   ├── ApiResponderContract.php
│   │   └── ServiceContract.php
│   ├── DTOs/                       # Immutable data transfer objects
│   │   ├── Api/
│   │   │   └── ApiResponseData.php
│   │   └── Health/
│   │       └── HealthStatusData.php
│   ├── Enums/
│   │   └── ApiErrorCode.php
│   ├── Events/
│   │   └── BaseEvent.php
│   ├── Exceptions/
│   │   ├── ApiException.php
│   │   ├── BusinessException.php
│   │   └── ResourceNotFoundException.php
│   ├── Helpers/
│   │   └── ApiHelper.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/V1/
│   │   │       ├── ApiController.php      # Base API controller
│   │   │       └── HealthController.php
│   │   ├── Middleware/
│   │   │   └── ForceJsonResponse.php
│   │   ├── Requests/               # Form requests (per feature)
│   │   └── Resources/              # API resources (per feature)
│   ├── Jobs/
│   │   └── BaseJob.php
│   ├── Listeners/                  # Event listeners
│   ├── Modules/                    # Bounded contexts (future features)
│   │   ├── AbstractModuleServiceProvider.php
│   │   ├── ModuleRegistry.php
│   │   ├── CMS/
│   │   ├── Users/
│   │   ├── Membership/
│   │   ├── Leadership/
│   │   ├── Ministries/
│   │   ├── Countries/
│   │   ├── Regions/
│   │   ├── Events/
│   │   ├── LMS/
│   │   ├── Courses/
│   │   ├── Assessments/
│   │   ├── Certificates/
│   │   ├── Resources/
│   │   ├── Gallery/
│   │   ├── Prayer/
│   │   ├── Donations/
│   │   ├── Newsletter/
│   │   ├── Reports/
│   │   ├── Analytics/
│   │   ├── Media/
│   │   ├── SEO/
│   │   └── Api/
│   ├── Notifications/
│   ├── Policies/
│   ├── Providers/
│   │   ├── ApiServiceProvider.php
│   │   └── AppServiceProvider.php
│   ├── Services/                   # Reusable business logic
│   │   └── Health/
│   │       └── HealthCheckService.php
│   ├── Support/
│   │   └── Api/
│   │       ├── ApiExceptionHandler.php
│   │       └── ApiResponse.php
│   └── Traits/
│       └── ApiResponses.php
├── bootstrap/
│   ├── app.php                     # Routing, middleware, exceptions
│   └── providers.php
├── config/
│   ├── api.php                     # API versioning & meta
│   ├── modules.php                 # Registered module list
│   └── sanctum.php                 # Auth config (Phase 1B+)
├── database/
│   └── migrations/                 # Framework tables only (Phase 1A)
├── docs/                           # Project documentation
├── routes/
│   ├── api.php                     # Version router
│   ├── api/v1.php                  # v1 endpoints
│   ├── console.php                 # Artisan + scheduler
│   └── web.php
├── storage/
├── tests/
│   └── Feature/Api/
│       └── HealthEndpointTest.php
└── .env.example
```

## Module internal structure

Each module under `app/Modules/{Name}/` contains:

```
{Name}/
├── Actions/
├── Contracts/
├── DTOs/
├── Events/
├── Exceptions/
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Resources/
├── Jobs/
├── Listeners/
├── Notifications/
├── Policies/
└── Services/
```

When a module is implemented, add `{Name}ServiceProvider.php` extending `AbstractModuleServiceProvider` and register it in `bootstrap/providers.php`.

## Namespace conventions

| Path                               | Namespace                         |
| ---------------------------------- | --------------------------------- |
| `app/Actions/Health/`              | `App\Actions\Health`              |
| `app/Modules/Ministries/Services/` | `App\Modules\Ministries\Services` |
| `app/Http/Controllers/Api/V1/`     | `App\Http\Controllers\Api\V1`     |

## What belongs where

| Artifact          | Location   | Example                 |
| ----------------- | ---------- | ----------------------- |
| HTTP adapter      | Controller | `HealthController`      |
| Use case          | Action     | `GetHealthStatusAction` |
| Business logic    | Service    | `HealthCheckService`    |
| Structured data   | DTO        | `HealthStatusData`      |
| Interface         | Contract   | `ApiResponderContract`  |
| Domain error      | Exception  | `BusinessException`     |
| Cross-cutting API | Support    | `ApiResponse`           |

## Files intentionally excluded (Phase 1A)

- Business migrations (CMS, ministries, etc.)
- Sanctum `personal_access_tokens` migration (Phase 1B)
- Module service providers (until modules are built)
- Authentication controllers and routes
