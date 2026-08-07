# Phase 5 — Enterprise Content Platform (ECP)

Phase 5 connects the TanStack Start public frontend to Laravel CMS APIs so site content and public forms are managed from the admin dashboard.

## Architecture

```
React Public Site ──TanStack Query──► /api/v1/public/*
React Admin CMS   ──Sanctum───────► /api/v1/cms/*
```

- **Module:** `app/Modules/Cms/`
- **Pattern:** Service layer, policies, API resources, audit logging, UUIDs, soft deletes
- **Auth:** Unchanged Sanctum SPA session flow; admin routes require `auth:sanctum` + permission policies

## Database Tables

| Table | Purpose |
|-------|---------|
| `cms_pages` | Page records with status, hero, blocks |
| `cms_page_versions` | Version history snapshots |
| `cms_page_sections` | Homepage/landing sections (hero, mission, statistics, etc.) |
| `cms_media` / `cms_media_folders` | Media library assets |
| `cms_menus` / `cms_menu_items` | Navigation menus |
| `cms_seo` | Per-path SEO metadata |
| `cms_countries` | Global presence countries |
| `cms_ministries` | Ministry catalog |
| `cms_leadership_profiles` | Leadership profiles |
| `cms_partners` | Partner logos/links |
| `cms_testimonials` | Public testimonials |
| `cms_settings` | Organization/contact/social settings |
| `cms_form_submissions` | All public form intake |
| `cms_audit_logs` | CMS mutation audit trail |

## Public API (`/api/v1/public`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/site` | Settings + menus bootstrap |
| GET | `/home` | Homepage sections + featured content |
| GET | `/pages/{slug}` | CMS page by slug |
| GET | `/countries`, `/countries/{slug}` | Country catalog |
| GET | `/ministries`, `/ministries/{slug}` | Ministry catalog |
| GET | `/leadership` | Leadership profiles |
| GET | `/testimonials`, `/partners` | Social proof content |
| POST | `/forms/contact` | Contact form |
| POST | `/forms/counseling` | Counseling request (stored, no counselor workflow yet) |
| POST | `/forms/membership` | Public membership application → Members module |
| POST | `/forms/newsletter` | Newsletter signup |
| POST | `/forms/partnership` | Partnership inquiry |
| POST | `/forms/donation-interest` | Donation interest |
| POST | `/forms/prayer` | Prayer request |

## Admin CMS API (`/api/v1/cms`)

Requires Sanctum session + permissions.

| Permission | Endpoints |
|------------|-----------|
| `countries.manage` | Countries CRUD |
| `settings.manage` | Settings list + bulk update |
| `cms.manage` | Page sections, form submissions |
| `leadership.manage` | Leadership profiles (planned admin CRUD) |
| `media.manage` | Media library (planned) |

## Frontend Integration

### Public site

- `PublicSiteProvider` loads bootstrap (settings, menus) for nav/footer
- TanStack Query hooks in `src/features/public/hooks/usePublicContent.ts`
- CMS mappers in `src/lib/cms/mappers.ts`
- Migrated pages: Home stats, Leadership, Ministries, Global Presence, Navigation, Footer settings
- Public forms use live Laravel endpoints via `src/services/index.ts`

### Admin CMS

- `/admin/cms` — CMS dashboard
- `/admin/countries` — Country list (API-backed)
- `/admin/settings` — Organization settings editor

## Membership & Forms

Public membership applications flow:

```
Visitor form → POST /api/v1/public/forms/membership
  → MemberManagementService::createFromPublicApplication()
  → cms_form_submissions audit record
  → Admin reviews in Members module
```

All other public forms persist to `cms_form_submissions` for admin inbox review.

## Seeding

Run `php artisan db:seed --class=CmsSeeder` (included in `DatabaseSeeder`) for:

- Organization settings
- Primary navigation menu
- Homepage sections (hero, mission, statistics)
- Countries, ministries, leadership, testimonials

## Remaining Work

- Page editor with version history UI
- Media library upload/crop/thumbnails
- Full section builder admin UI
- Blog/gallery/resources/vlog CMS migration
- SEO sitemap generation from CMS
- Form submission admin inbox UI
- Leadership/ministries admin CRUD screens
- Notification dispatch on new submissions

## Verification

```bash
# Backend
cd backend && php artisan test

# Frontend
bunx tsc --noEmit
bun run lint
bun run build
```
