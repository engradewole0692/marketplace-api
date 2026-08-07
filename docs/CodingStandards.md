# Coding Standards

## PSR-12 compliance

All PHP code follows [PSR-12](https://www.php-fig.org/psr/psr-12/). Format code with Laravel Pint before committing:

```bash
./vendor/bin/pint
```

## Strict typing

Every new PHP file must declare strict types:

```php
<?php

declare(strict_types=1);
```

Use scalar type hints and return types on all methods. Prefer `list<T>`, `array<string, mixed>`, and `@param`/`@return` PHPDoc where arrays carry structured data.

## Naming conventions

| Element                | Convention                   | Example                  |
| ---------------------- | ---------------------------- | ------------------------ |
| Classes                | PascalCase                   | `HealthCheckService`     |
| Methods                | camelCase                    | `getStatus()`            |
| Constants / Enum cases | PascalCase / SCREAMING_SNAKE | `ApiErrorCode::NotFound` |
| Config keys            | snake_case                   | `api.prefix`             |
| Routes                 | kebab-case                   | `/api/v1/health`         |
| Database tables        | snake_case plural            | `failed_jobs`            |

## Architecture rules

### Controllers stay thin

Controllers validate HTTP input, delegate to Actions or Services, and return formatted responses. No business logic in controllers.

```php
// Good
public function __invoke(GetHealthStatusAction $action, ApiResponderContract $responder): JsonResponse
{
    return $responder->success($action->execute()->toArray());
}
```

### Actions orchestrate one use case

One public `execute()` method per Action. Inject Services via constructor.

### Services hold reusable logic

Services implement `ServiceContract` when they represent application services. Keep them stateless and testable.

### DTOs are immutable

Use `readonly` classes with constructor property promotion. Implement `Arrayable` when serialized to JSON.

### Exceptions are typed

Throw `ApiException` subclasses with appropriate `ApiErrorCode` values. Never return error payloads manually from controllers—let the exception handler format them.

## API responses

Always use `ApiResponderContract` or the `ApiResponses` trait. Never call `response()->json()` directly in feature code unless extending the responder.

Success:

```php
return $this->responder->success($data, 'Created successfully.', 201);
```

Errors are thrown, not returned:

```php
throw new ResourceNotFoundException('Ministry not found.');
```

## Dependency injection

- Bind interfaces in service providers (`ApiServiceProvider`, module providers).
- Constructor injection preferred over facades in domain code.
- Facades acceptable in infrastructure (migrations, console commands).

## Database

- Migrations use descriptive timestamps and clear `up()`/`down()` methods.
- Eloquent models live in `app/Models/` (shared) or `app/Modules/{Name}/Models/` (module-specific).
- Use PostgreSQL-compatible column types; avoid MySQL-only features without guards.

## Testing

- Feature tests for HTTP endpoints under `tests/Feature/`.
- Unit tests for Services and Actions under `tests/Unit/`.
- Use `RefreshDatabase` for tests touching the database.
- Name tests descriptively: `test_health_endpoint_returns_successful_api_response`.

## Git and commits

- One logical change per commit.
- Do not force-push to Lovable-connected branches.
- Never commit `.env`, credentials, or `vendor/`.

## Module development (future phases)

When implementing a module:

1. Create `{Name}ServiceProvider` extending `AbstractModuleServiceProvider`.
2. Register routes in the module or `routes/api/v1.php`.
3. Keep all module code under `app/Modules/{Name}/`.
4. Add Feature tests before merging.

## Code review checklist

- [ ] `declare(strict_types=1)` present
- [ ] Pint passes
- [ ] No business logic in controllers
- [ ] API responses use standard envelope
- [ ] Exceptions use `ApiErrorCode`
- [ ] Tests cover new endpoints
- [ ] PostgreSQL-compatible migrations
