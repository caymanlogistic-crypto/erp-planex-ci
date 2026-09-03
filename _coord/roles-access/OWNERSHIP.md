# ERP PLANEX — Roles & Access OWNERSHIP

Baseline for all streams: `8b1b4a027eadbe41626f28fcf3c12db338a31b0a` unless Tech Lead explicitly assigns a later integration baseline.

## Global rule

Two agents must not independently redesign the same architectural layer or primary file. If a shared file is unavoidable, one owner makes the final change; other streams submit requirements through REPORT/Handoff.

| Agent | Подсистема | Разрешённые зоны | Централизованно запрещённые зоны |
|---|---|---|---|
| 01 | Discovery / architecture | read-only whole `/erpv2` repo paths relevant to auth/access/entities/tests/migrations | all production mutations |
| 02 | Access Core | `app/Service/*Access*`, access context/resolver/policy support, targeted auth guard integration helpers, access-core tests | PageRegistry ownership, layout/menu rendering, membership CRUD UI, assignments UI, migrations |
| 03 | Page Registry / Navigation / Endpoint Mapping | canonical registry service/config, endpoint→page mapping, targeted route registry integration, `app/View/layouts/main.php`, registry/nav tests | role/membership persistence, assignments persistence, migrations, admin modal UI |
| 04 | Membership / Roles / Users | central identity/membership/role services/controllers/routes, last-leader protection, role/user backend tests | Access resolver internals, page registry internals, data entity queries, layout/menu, migrations |
| 05 | Data Scope / Assignments | regulated entity scope service, assignment service, targeted Client/Contractor/Vehicle/Driver/Linear/Waste query/action integrations, assignment tests | identity/membership CRUD, PageRegistry, layout/menu, migrations, role admin UI |
| 06 | UI/UX | `Роли и доступы` views/assets/controllers that only orchestrate stable backend contracts; existing object-card assignment blocks; UI tests | schema, resolver semantics, page registry semantics, business-entity query semantics, migrations |
| 07 | Migration / BEFORE↔AFTER / Regression | `database/migrations/*`, `database/migrations-local/*`, migration scripts/snapshot/comparison tooling, cross-stream regression tests | production deploy, product/UI redesign, parallel auth/data mechanisms |

## Centralized file ownership

### Access resolver
Owner: **Agent 02**.

All other agents consume its contract; they do not introduce alternative ACL engines.

### Page registry and endpoint mapping
Owner: **Agent 03**.

All other agents register requirements through Agent 03 / Tech Lead. No second hand-maintained admin menu tree.

### Main layout/menu
Owner: **Agent 03**.

Agent 06 may submit visual requirements but must not independently rewrite `app/View/layouts/main.php`.

### Membership / role persistence APIs
Owner: **Agent 04**.

### Data-scope / assignment runtime
Owner: **Agent 05**.

### Migration chain
Owner: **Agent 07 only**.

- central next number: `013`
- tenant/local next number: `130`

No other agent creates numbered migration files.

## Shared-file protocol

If an agent needs a change in another owner's primary file:

1. document the exact requirement in its REPORT;
2. include proposed interface/call shape or minimal patch recommendation;
3. do not modify the file independently;
4. Tech Lead routes the requirement to the owner or performs an explicit integration fix.

## Forbidden for every agent

- legacy `/erp`;
- production DB mutation or deploy;
- force-push/reset/rewrite of another stream;
- broad refactors outside assigned scope;
- new parallel ACL/RBAC/Vehicle/user identity mechanisms;
- changing business rules from `00_IMPLEMENTATION_CONTRACT.md` without a Tech Lead decision recorded in `DECISIONS.md`.
