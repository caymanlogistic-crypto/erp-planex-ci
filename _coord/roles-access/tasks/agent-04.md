# AGENT 04 TASK — Membership / Roles / Users Backend

## Цель
Реализовать backend модель `global user -> company membership -> one role`, dynamic company roles, protected Руководитель, last-active-leader safety, multi-company и user/role administration contracts.

## Контекст
Source of truth: `../00_IMPLEMENTATION_CONTRACT.md`.
Ключевые разделы: 3–21, 33–34, 38, 64–66, 75–79, 96–99.
Следовать D-001, D-002, D-003, D-013.

## START_HEAD / baseline
`8b1b4a027eadbe41626f28fcf3c12db338a31b0a`

## Разрешённый scope
- central identity/membership/role services/repositories;
- role/user administration controllers/routes/API contracts;
- existing canonical user creation/linking integration;
- last-leader protection;
- multi-company membership selection/update logic;
- backend tests for roles/users/membership.

## Запрещённый scope
- EffectiveAccessResolver internals — Agent 02;
- PageRegistry/layout/menu — Agent 03;
- data entity filters/assignments — Agent 05;
- final admin views/modal styling — Agent 06;
- numbered migrations — Agent 07;
- production and `/erp`.

## Обязательный результат
1. One global account supports multiple company memberships; uniqueness `(user_id, company_id)`.
2. Role/status/overrides are membership-scoped, not global identity fields.
3. Adding user supports existing global account vs new global account through canonical auth/user mechanism; no duplicate account for another company.
4. Current company owner cannot inspect other-company membership internals.
5. Dynamic roles: create fail-closed, unique normalized name per company, stable role_id, rename ordinary role, copy role settings only, delete only unused role.
6. Reserved names `Руководитель` and `superadmin` cannot be ordinary roles.
7. System `Руководитель` identified by stable key/flag, cannot rename/delete/restrict.
8. Multiple leaders allowed.
9. Backend atomically prevents any operation that would leave zero active leaders (disable, role change, membership removal/exclusion).
10. Personal page/data override persistence supports tri-state / role fallback and survives role changes/status disable-enable.
11. Membership disable immediately prevents company access through Agent 02 resolver contract while preserving settings.

## Schema requirements handoff
Agent 04 MUST NOT create numbered migrations. Report exact central schema/table/index/constraint requirements to Agent 07, including uniqueness and leader/system role fields.

## Обязательные тесты
- existing account added to second company without duplicate identity;
- duplicate membership rejected;
- roles independent across companies;
- ordinary role create default closed;
- normalized duplicate role name rejected;
- reserved names rejected;
- rename keeps role identity/members;
- copy does not copy users/overrides/assignments/system flag;
- delete used role blocked incl disabled members;
- leader cannot be renamed/deleted/restricted;
- last active leader disable/change/remove blocked;
- second leader permits changing first;
- personal overrides survive role change;
- disable/enable preserves role/overrides;
- current owner cannot read other-company membership internals.

## Критерии готовности
Backend contracts stable for Agent 02/06/07; tests PASS; migration needs explicitly documented.

## Формат отчёта
`../reports/agent-04.md` по REPORT contract.

## Что агент НЕ должен делать
Не менять authentication philosophy; не создавать second login/account system; не писать migrations; не расширять permissions до CRUD/button/field level; не менять production; не трогать `/erp`; не делать UI redesign.