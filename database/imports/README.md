# LMS content imports

Place production import files here (not committed if they contain proprietary URLs):

| File | Purpose |
|------|---------|
| `Prayer Training.xlsx` | Official Prayer Training timetable (primary filename) |
| `prayer-training.xlsx` | Alternate filename (same content) |

## Prayer Training

```bash
php artisan lms:import-prayer-training --dry-run
php artisan lms:import-prayer-training database/imports/"Prayer Training.xlsx"
php artisan lms:import-prayer-training database/imports/"Prayer Training.xlsx" --dry-run
```

Or upload via **Admin → Learning → Import** in the Kingdom Collective admin portal.

The importer:

- Creates/updates course slug `prayer-training` (draft, ministry unassigned)
- Maps timetable groupings to generic **Module 1…N** (not mandatory weekly scheduling)
- Preserves lesson order, titles, and YouTube URLs from the spreadsheet
- Creates a draft **Exams** assessment placeholder (no fabricated questions)
- Is idempotent — safe to re-run

Supported spreadsheet layouts:

1. **Timetable layout** (official file): column A = lesson title, column B = YouTube URL, blank rows = module breaks
2. **Tabular layout**: optional header row with Week/Module, Lesson #, Title, YouTube URL columns

Verify after import:

```bash
php scripts/verify-prayer-training-import.php database/imports/"Prayer Training.xlsx"
```
