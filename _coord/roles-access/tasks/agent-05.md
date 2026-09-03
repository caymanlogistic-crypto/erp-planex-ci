# AGENT 05 TASK — Data Scope / Assignments

## Цель
Реализовать narrow regulated data-scope и many-to-many assignments для Client/Contractor/Vehicle/Driver, creator-based scope для Linear/Waste Trip, включая все list/search/API/direct-ID enforcement и auto-assignment.

## Контекст
Source of truth: `../00_IMPLEMENTATION_CONTRACT.md`.
Ключевые разделы: 35–59, 61–67, 80–85, 94, 96–99.
Следовать D-007, D-008, D-009, D-010, D-011.

## START_HEAD / baseline
`8b1b4a027eadbe41626f28fcf3c12db338a31b0a`

## Разрешённый scope
- canonical data-scope helper/service;
- assignment service/repository contracts;
- targeted integrations into Client/Contractor/Vehicle/Driver services/routes/controllers;
- Linear Trip/Waste Trip creator filters;
- list/search/autocomplete/counter/JSON/API/direct-ID enforcement;
- auto-assignment after successful object creation by concrete company user;
- data-scope/assignment tests.

## Запрещённый scope
- membership/role CRUD — Agent 04;
- page registry/menu — Agent 03;
- resolver ownership — Agent 02 (consume its data-scope decision contract);
- admin UI — Agent 06;
- numbered migrations — Agent 07;
- production and `/erp`.

## Обязательный результат
1. Regulated types only: Client, Contractor, `vehicle_unit`, Driver, Linear Trip, Waste Trip.
2. FEO page-only; no FEO ownership.
3. Client/Contractor/Vehicle/Driver `ONLY_OWN` = current explicit assignments, not immutable creator.
4. Assignments many-to-many.
5. Only leader can mutate assignments server-side.
6. Auto assignment on successful creation to exact concrete company-user creator, including leader.
7. No assignment for superadmin/system/import/integration without trustworthy concrete company-user creator.
8. No propagation Contractor↔Vehicle↔Driver.
9. Vehicle mapping strictly `vehicle_units.id`, `entity_type=vehicle_unit`; no parallel Vehicle model.
10. Linear/Waste `ONLY_OWN` use canonical creator; no manual trip assignments.
11. `NONE` blocks list/card/create/update/delete/search/autocomplete/select/API/direct ID/export/counters/alternative outputs.
12. `ALL` gives tenant-normal data only; never cross-company.
13. Minimal linked context may show name needed by an allowed object but cannot open/search/list forbidden related entity.
14. Scope/page/status/role changes never delete assignments.

## Schema requirements handoff
No numbered migration files. Provide Agent 07 exact tenant/central storage requirements, indexes, entity_type constraints and any trustworthy legacy mapping evidence.

## Обязательные тесты
- many-to-many contractor;
- transfer from creator;
- independent vehicles under shared contractor;
- exact vehicle_unit mapping;
- auto assignment ordinary user;
- auto assignment leader;
- no synthetic assignment for system/import/superadmin;
- `ALL→ONLY_OWN` and `NONE→ONLY_OWN` preserve assignments;
- disabled membership assignments remain persisted;
- Linear/Waste creator-only and no trip assignments;
- `NONE` enforcement across list/search/autocomplete/counter/API/direct ID;
- `ONLY_OWN` enforcement across same surfaces;
- cross-company direct ID denied;
- minimal linked context does not grant related entity access.

## Критерии готовности
One data-scope/assignment mechanism covers all regulated surfaces without leakage; tests PASS; migration needs documented.

## Формат отчёта
`../reports/agent-05.md` по REPORT contract.

## Что агент НЕ должен делать
Не создавать universal resource ACL; не add VehicleSet/FEO/finance scope; не write migrations; не create a second Vehicle entity; не redesign UI; не touch production or `/erp`; не fix unrelated queries.