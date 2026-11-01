# E14-E15 Final Architecture Review Report

> Архивный отчёт от 28.06.2026. Описывает состояние на дату проверки и не служит действующей инструкцией или источником учётных данных.


## Status: E14_E15_FINAL_ARCHITECTURE_ACCEPTED

**Date**: 2026-06-28
**Branch**: `refactor/e14-e15-final-architecture-review`
**Base commit**: `8d4c0e03` (fix contractors restore Cyrillic)

---

## E14 — Action Bridge Assessment

### What was checked

All action directories:
- `app/Http/Controllers/Company/ClientActions/` (11 files)
- `app/Http/Controllers/Company/ContractorActions/` (22 files)
- `app/Http/Controllers/Company/DriverActions/` (17 files)
- `app/Http/Controllers/Company/VehicleSetActions/` (8 files)
- `app/Http/Controllers/Company/DocumentActions/` (13 files)
- `app/Http/Controllers/Company/RouteExecutorActions/` (7 files)
- `app/Http/Controllers/Company/ResponsibleAssignmentActions/` (2 files)
- `app/Http/Controllers/Superadmin/CompanyActions/` (12 files)
- `app/Http/Controllers/Superadmin/ManagementActions/` (11 files)

### Finding: Action bridge is ACCEPTED TRANSITIONAL PATTERN

**Verdict: Keep as-is.** All controllers use a consistent pattern:
1. Route file instantiates controller → registers callbacks
2. Controller method extracts `$config`, `$db`, `$service` from `$this`
3. Controller `require`s the action file
4. Action file runs business logic + renders HTML directly

**Risk of removal**: HIGH. Action files contain thousands of lines of mixed business logic, SQL, and HTML. Moving them into controller methods or view files without test coverage risks breaking the entire application.

**Recommended approach**: If refactored in future, each action file should be:
1. Split into Service method (business logic) + Controller method (HTTP handling) + View (HTML)
2. Tested module by module, not in bulk
3. Prioritize by user impact, not architectural purity

---

## E15 — Final Architecture Review

### What was checked

| Check | Result |
|-------|--------|
| Route files thin | 10/19 PASS, 3 inline-closure files, 6 legacy bulky files |
| Controller wiring | 10/10 route files verified |
| Mojibake | PASS — all files valid UTF-8 |
| Dynamic table names | PASS — all via whitelist maps |
| No `route_executors` table | PASS — not created anywhere |
| `driver_vehicle_blocks` no `vehicle_id` | PASS — migration files verified |
| Action files no `vehicle_id` | PASS — DriverActions checked |
| Sidebar old menu items | PASS — no `/company/crews` etc. in sidebar |
| Entry points thin | PASS — index.php 81, bootstrap.php 39 lines |
| Legacy redirects order | PASS |

### Architecture Guard Extension

`tools/architecture_guard.php` updated to E14-E15 with:

- Size checks for ALL route files (30 route files total)
- Controller wiring validation for all 10 controller-based route files
- Dynamic table name whitelist validation (entity_list.php)
- `route_executors` table creation prohibition
- `driver_vehicle_blocks` `vehicle_id` field prohibition
- `vehicle_id` in DriverActions prohibition
- Mojibake / UTF-8 validity check
- Old menu item check in sidebar layout
- Bootstrap/app.php thinness check

### Inline Closure Route Files (refactoring candidates)

These 3 route files use inline closures instead of controllers:

| File | Lines | Contains |
|------|-------|----------|
| `company_dashboard.php` | 240 | Dashboard + access grants CRUD |
| `company_logists.php` | 960 | Full logist CRUD (create, edit, view, reset password, archive) |
| `superadmin_company_delete.php` | 445 | Permanent company delete route; the former "with backup" description was inaccurate before the guarded backup/journal change |

These are NOT errors — they are working code. Future refactoring should:
1. Extract DashboardController, LogistController, CompanyDeleteController
2. Move business logic to services
3. Keep route files thin

### Business Model Validation

- **No `route_executors` table**: confirmed. The business model uses `driver_vehicle_blocks` + `crews`.
- **`driver_vehicle_blocks` has no `vehicle_id`**: confirmed. Uses `vehicle_set_id` only.
- **`crews.vehicle_id`**: legacy field, filled from `vehicle_sets.primary_vehicle_unit_id`.

---

## Runtime Smoke Results

### Server: `http://127.0.0.1:8017` (PHP built-in)

### Owner (owner_test_runtime / [удалено])
| Check | Result |
|-------|--------|
| Dashboard | 200 OK |
| Clients list | 200 OK |
| Contractors list | 200 OK |
| Drivers list | 200 OK |
| Vehicle Sets | 200 OK |
| Route Executors | 200 OK |
| Responsible Assignments | 200 OK |
| Logists | 200 OK |
| Documents (clients) | 200 OK |

### Senior Logist (senior_runtime_1 / senior1234)
| Check | Result |
|-------|--------|
| Dashboard | 200 OK |
| Clients list | 200 OK |
| Contractors list | 200 OK |
| Drivers list | 200 OK |
| Vehicle Sets | 200 OK |
| Route Executors | 200 OK |

### Logist (logist_runtime_1 / [удалено])
| Check | Result |
|-------|--------|
| Dashboard | 200 OK |
| Route Executors | 200 OK |

### Superadmin (admin@planex.local / [удалено])
| Check | Result |
|-------|--------|
| Companies list | 200 OK |
| Companies create | 200 OK |

---

## Files Changed

### Modified
- `tools/architecture_guard.php` — extended to E14-E15 checks
- `docs/ai/PROJECT_STATE.md` — updated to E14-E15 status
- `docs/ai/CURRENT_TASK.md` — updated to E14-E15 status
- `docs/ai/HANDOFF_FOR_NEW_CHAT.md` — updated with E14-E15 context
- `docs/ai/MODULAR_DEVELOPMENT_RULES.md` — added inline closure rule
- `docs/ai/ROUTE_MAP_AFTER_E7.md` — updated with new route files

### Created
- `docs/ai/E14_E15_FINAL_ARCHITECTURE_REPORT.md` — this file
- `tools/check_mojibake.php` — mojibake detection helper (to be cleaned up)

---

## Static Analysis

| Check | Result |
|-------|--------|
| `php -l public/index.php` | PASS |
| `php -l bootstrap/app.php` | PASS |
| `php tools/architecture_guard.php` | PASS (0 errors, 3 warnings) |
| Mojibake scan | PASS |
| `git diff --check` | PASS |

### Guard Warnings (3)
1. `ContractorActions/add_crew_submit.php` — dynamic table name `$table` from whitelist (safe)
2. `ContractorActions/create_full_submit.php` — dynamic table name `$table` from whitelist (safe)
3. `DocumentActions/index.php` — `FROM $entityTable` from whitelist map (safe)

---

## Residual Risks

1. **3 inline-closure route files** (dashboard, logists, company_delete) — contain full business logic outside controllers. Modifying any of these requires understanding the full closure, not just a controller method.
2. **6 legacy bulky route files** — `company_clients.php`, `company_contractors.php`, `company_drivers.php`, `company_vehicle_sets.php`, `company_documents.php`, `superadmin_management.php` — still contain procedural business logic.
3. **Action bridge dependency** — any new developer must understand the `require`-based action file pattern before making changes.
4. **No test coverage** — all runtime verification is manual via smoke checks.

---

## Final Commits

```text
(created during review — commit message: refactor(core): finalize modular architecture review)
```
