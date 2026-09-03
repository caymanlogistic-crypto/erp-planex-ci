# Agent 01 — Discovery / Architecture Report

Status: **PASS (read-only discovery)**

Baseline: `8b1b4a027eadbe41626f28fcf3c12db338a31b0a`

No production code, DB, migration or runtime state was changed by this stream.

## 1. Current authorization shape

Current access decisions are fragmented across multiple mechanisms:

1. session role codes (`company_owner`, `senior_logist`, `logist`, `superadmin`);
2. hard-coded `requireRole([...])` route/controller guards;
3. hard-coded layout/sidebar branches in `app/View/layouts/main.php`;
4. older entity ownership/grant structures;
5. `app/Service/AccessControlService.php` exists but is explicitly a foundation/not the canonical connected runtime for all protected routes.

**Conclusion:** adding the new role UI on top of the existing checks would create a second/third ACL. The implementation must centralize effective access and route/page ownership.

## 2. Authentication / multi-company gap

`app/Http/Controllers/AuthController.php` currently has materially different paths for owner/company user vs tenant users. For regular logist-like users it searches tenant/company contexts and treats ambiguous same login across companies as an error.

This cannot represent the target contract:

`one global account -> many independent company memberships`.

**Required change:** preserve existing authentication/password mechanisms but separate global identity from company membership. Company role/status/overrides must be resolved after identity using the selected company membership.

## 3. Current company user storage

Central migration history includes:

- `database/migrations/006_create_company_users.sql`;
- `database/migrations/007_add_position_to_company_users.sql`.

Existing runtime/user sync code (`app/Service/UserSyncService.php`) must be treated as an integration dependency rather than bypassed with a new login/account subsystem.

## 4. Current menu/access mismatch

`app/View/layouts/main.php` contains role-code-specific menu branches. This means present menu visibility is not derived from a stable page registry/effective permission set.

**Required change:** PageRegistry + EffectiveAccessResolver must become the source for menu visibility and direct-route protection. Menu hiding itself is not security.

## 5. Finance conflict with new OWNER contract

Current code/current memory contains historical finance gates based on `company_owner`.

The new OWNER contract explicitly requires that an ordinary role such as `Бухгалтер` can receive an explicitly allowed finance page and use its normal company functionality.

**Conclusion:** old role-name/role-code finance gates are migration targets. They cannot remain as a hidden second deny after page access passes.

## 6. Canonical regulated entities

### Client

Use existing Client domain and `company_clients*` family. Do not introduce a parallel client catalog.

### Contractor / Перевозчик

Use existing Contractor domain and `company_contractors*` family.

`RouteExecutor / Исполнитель рейса` remains a separate entity/domain and is page-level only in v1.

### Driver

Use existing Driver domain and `company_drivers*` family.

### Linear Trip / Waste Trip

Use canonical trip domains and their creator semantics. Trips receive data-scope but no manual assignments.

## 7. Mandatory Vehicle discovery result

This was completed before any schema/access mapping changes.

- canonical tenant table: `vehicle_units`;
- primary key: `id`;
- route family: `app/Http/Routes/company_vehicles.php` / `company_vehicles*` behavior;
- existing technical entity identifier in grants: `vehicle_unit`;
- migration history: early local migration creates legacy `vehicles`; later runtime/current schema normalizes/renames to `vehicle_units`;
- VehicleSet and other aggregate transport structures are separate and are not the `ТС` scope.

**Mandatory implementation mapping:** `Vehicle/ТС -> vehicle_units.id -> entity_type vehicle_unit`.

Creating `entity_type=vehicle`, a new Vehicle table, or a parallel transport identity is prohibited.

## 8. Existing ownership/grant structures

`database/migrations-local/008_add_ownership_columns.sql` and `009_create_entity_access_grants.sql` prove that older ownership/grant concepts exist.

They may be valuable for:

- BEFORE snapshot reconstruction;
- trustworthy initial assignment migration;
- compatibility evidence.

They must not remain as a competing authorization engine after cutover.

## 9. Migration chains

Current central chain ends at:

`database/migrations/012_add_company_bank_requisites.sql`

Reserved next central number: **013**.

Current tenant/local chain ends at:

`database/migrations-local/129_document_generator_auto_number_and_bank_inn.sql`

Reserved next local number: **130**.

Only Agent 07 owns migration files.

## 10. Required target seams

The implementation should expose stable seams instead of agent-specific shortcuts:

- `PageRegistry` — page key, menu metadata, endpoint mapping;
- `EffectiveAccessResolver` — membership/role/override/page/data decision;
- `Membership/Role service` — central company access model + leader safety;
- `AssignmentService` — many-to-many Client/Contractor/Vehicle/Driver assignments;
- entity query/action scope helpers — ALL / ONLY_OWN / NONE;
- migration snapshot/comparator — BEFORE / AFTER.

Exact class names may follow project naming conventions, but there must be one canonical owner per seam.

## 11. Fail-closed requirements discovered as integration-critical

- unknown protected page key -> deny;
- missing endpoint mapping -> deny;
- inactive/missing membership -> deny;
- stale login-time role must not keep access after changes;
- shared endpoint page allow must be server-owned and followed by data-scope;
- direct URL, POST, AJAX, JSON, modal loaders, file endpoints and exports must not bypass page access.

## 12. Partition recommendation

Independent streams are safe only after this discovery:

- 02 Access Core;
- 03 Registry/Nav/Endpoint Mapping;
- 04 Membership/Roles/Users;
- 05 Data Scope/Assignments;
- 06 UI after backend contracts stabilize;
- 07 migrations/snapshots/regression as sole migration owner.

Shared-file ownership is defined in `OWNERSHIP.md`.

## 13. Blockers

1. Current chat runtime does not expose a genuine coding-subagent spawn tool; implementation streams cannot truthfully be reported as independently executed here.
2. The File Library business contract is readable/searchable but direct full-file extraction is truncated by the adapter. Exact repository mirroring of the contract must be completed before dispatch.
3. No production migration/deploy is authorized by this discovery pass.

## Final discovery verdict

**DISCOVERY PASS**.

The target architecture is implementable without inventing a universal ACL platform, but only if the old session-role/menu/finance/legacy-grant decisions are converged into the new canonical membership + page registry + effective access + narrow data-scope model.
