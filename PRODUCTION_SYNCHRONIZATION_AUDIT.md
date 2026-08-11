# PRODUCTION_SYNCHRONIZATION_AUDIT.md

Canonical consolidated report lives in the SPA repo:

`../kingdom-collective/PRODUCTION_SYNCHRONIZATION_AUDIT.md`

(or the copy committed alongside that frontend release)

## Backend fixes in this sync pass

| Fix | File |
|-----|------|
| Seed events as Published | `database/seeders/EventsSeeder.php` |
| Webhook fail-closed (secret + explicit status) | `app/Modules/Donations/Gateways/AbstractOnlineGateway.php` |
| Webhook no default-succeeded | `app/Modules/Donations/Services/DonationCheckoutService.php` |
| Persist counselling settings | `app/Modules/Counselling/Http/Controllers/Api/V1/Admin/CounsellingAdminController.php` |
| Seed counselling setting keys | `database/seeders/ApplicationSettingSeeder.php` |

See the SPA report for full FE↔API matrix, empty-table classification, auth audit, and deploy checklist.
