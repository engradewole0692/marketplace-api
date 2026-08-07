# Platform Architecture

## Purpose

This document defines the current end-to-end architecture of the Marketplace Ministers platform across frontend, backend, identity, and the first business domain (Membership).

## High-Level System

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ TanStack Start + React Admin + Public Site (repo root: src/)               │
│ - Public website routes                                                      │
│ - Admin SPA routes under /admin/*                                            │
└───────────────────────────────┬──────────────────────────────────────────────┘
                                │ HTTPS /api/v1/*
                                ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│ Laravel 12 API (backend/)                                                    │
│ - Versioned REST API                                                         │
│ - Sanctum session auth                                                       │
│ - Centralized IAM (users/roles/permissions/policies)                        │
│ - Membership business module                                                 │
└───────────────┬───────────────────────────────┬──────────────────────────────┘
                │                               │
                ▼                               ▼
        PostgreSQL/MySQL                  Filesystem / Object Storage
        (core relational data)            (documents, avatars, media)
```

## Backend Architecture (Laravel)

### API and Cross-Cutting Design

- API prefix: `/api/v1/*`
- Response envelope: `success`, `data`, `message`, `code`, `meta`, `errors`
- Global API error handling: `App\Support\Api\ApiExceptionHandler`
- JSON-first behavior via API middleware stack
- Pagination contract unified for backend/frontend interoperability

### Layering

- **Routes:** `backend/routes/api.php`, `backend/routes/api/v1.php`, feature route includes
- **Controllers:** thin adapters under `App\Http\Controllers\Api\V1\...`
- **Requests:** validation and authorization rules via form requests
- **Resources:** response shaping via API resources
- **Services:** core business orchestration (IAM + Membership)
- **Policies/Gates:** centralized authorization, no hardcoded role checks in controllers

## Identity and Access Management (Phase 3)

IAM is the platform authorization backbone and is required by all modules.

- Core entities: `users`, `roles`, `permissions`
- Authorization helpers resolve effective permissions per authenticated user
- Enforcement points:
  - Policies
  - Permission middleware alias
  - Route-level and controller-level authorization checks
  - Frontend permission wrappers and nav filtering
- Audit logging records IAM operations and actor context

## Membership Domain (Phase 4)

Membership is the first business module and canonical business identity.

### Core Responsibilities

- Rich member profile independent from IAM user table
- Membership lifecycle and approval workflow
- Status transition history
- Notes, documents, tags, timeline, and audit logging
- Bulk enterprise operations and searchable listings

### Key Data Model

- `members`
- `member_status_transitions`
- `member_contacts`
- `member_addresses`
- `member_notes`
- `member_tags` and `member_tag_member`
- `member_documents`
- `member_timelines`
- `member_audit_logs`
- `membership_number_sequences`

### Workflow

`application_submitted -> under_review -> approved -> active -> suspended -> inactive -> archived`

All transitions are validated and recorded in both transition history and timeline/audit records.

### Membership Number Strategy

- Config-driven generator (`config/membership.php`)
- Service-based sequence generation for enterprise-safe uniqueness
- Format example: `MM-2026-000001`

## Frontend Architecture (TanStack Start + React)

### Public + Admin in One Frontend

- Public site and admin routes are served from the same React codebase
- Admin entry: `/admin/login`
- Authenticated admin area: `/admin/*` via route guard

### Admin Design Principles

- Shared enterprise UI primitives (`EnterpriseDataTable`, reusable forms, dialogs)
- Permission-aware rendering (`PermissionGate`, permission hooks)
- Permission-filtered navigation in sidebar
- Route protection through auth + `admin.access`

## Authentication and Session Flow

- Sanctum CSRF endpoint: `/sanctum/csrf-cookie`
- Session login/logout endpoints:
  - `POST /api/v1/auth/login`
  - `POST /api/v1/auth/logout`
- Session identity endpoint: `GET /api/v1/auth/me`
- React admin consumes the same API session and permissions payload

## API Surface Summary

- **Auth:** session login/logout/profile/password/verification/avatar
- **IAM:** users, roles, permissions, IAM audit logs
- **Membership:** members CRUD + workflow + notes + documents + timeline + bulk actions

All APIs are versioned and permission-enforced.

## Security Model

- Centralized permission checks and policy mapping
- Admin access controlled by `admin.access` permission
- API validation via form requests
- Structured API errors and bounded exception rendering
- Audit trail for privileged operations (IAM + Membership)

## Deployment and Runtime

- Backend runs as Laravel application (`php artisan serve` in dev)
- Frontend runs with Vite/TanStack Start (`bun run dev` in dev)
- Production build pipeline includes frontend build artifact generation and backend route stability checks
- Storage abstraction supports local disk now and cloud providers later

## Quality and Verification Standards

- Backend feature tests for Auth, IAM, and Membership
- TypeScript checks, ESLint, and production build for frontend
- Route verification ensures API and auth endpoints remain intact
- Documentation-first phase delivery with explicit architecture records

## Architectural Constraints

- Do not reintroduce Laravel Blade admin routes/pages
- Keep admin interface React-only under `/admin/*`
- Keep authorization centralized (policies, middleware, permission services)
- Keep business logic out of controllers
- Keep module boundaries explicit and scalable for future bounded contexts

## Next Architecture Evolution

- Complete Membership migration into full domain-first module boundary under `App\Modules\Membership`
- Add module service providers as bounded contexts become active
- Integrate upcoming domains (Leadership, LMS, Events, Donations, Prayer, Certificates) against `members` identity
- Expand observability and background processing for cross-module workflows
