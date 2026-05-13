# src Structure (Scaffold)

This folder is a clean source structure for refactoring the current flat PHP files.
It does not replace existing production files yet.

## Target Scope (Medium)
- Keep the architecture practical for a 4-person team.
- Prioritize maintainability and incremental migration over large-platform complexity.
- Treat advanced enterprise concerns as future phases, not current deliverables.

## Modules
- Config: app and database settings
- Core: shared base classes/utilities
- Domain: entity contracts (Faculty, Program, Semester, Proposal, Committee, Milestone)
- Routes: route definitions
- Controllers: HTTP flow by role
- Services: business logic
- Repositories: data access
- Views: UI templates

## Medium-Phase Priorities
1. Stabilize auth and role dashboards with shared helpers and JS core utilities.
2. Keep Proposal, Milestone, and Committee modules consistent with legacy permissions.
3. Improve testability and deployment safety (lint, health checks, rollback-ready scripts).
4. Avoid over-engineering in this phase (no microservice split, no distributed workflows).

## Next Step
1. Wire `public/index.php` to load `src/bootstrap.php`
2. Migrate auth pages first
3. Migrate role dashboards in phases
