# src Structure (Scaffold)

This folder is a clean source structure for refactoring the current flat PHP files.
It does not replace existing production files yet.

## Modules
- Config: app and database settings
- Core: shared base classes/utilities
- Routes: route definitions
- Controllers: HTTP flow by role
- Services: business logic
- Repositories: data access
- Views: UI templates

## Next Step
1. Wire `public/index.php` to load `src/bootstrap.php`
2. Migrate auth pages first
3. Migrate role dashboards in phases
