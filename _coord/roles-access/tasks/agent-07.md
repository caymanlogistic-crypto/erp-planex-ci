# AGENT 07 TASK — Migration / BEFORE↔AFTER / Regression

## Цель
Быть единственным владельцем schema/migration chain, построить deterministic BEFORE→migration→AFTER verifier и независимую cross-stream security/regression matrix. Production migration запрещена без отдельного OWNER разрешения.

## Контекст
Source of truth: `../00_IMPLEMENTATION_CONTRACT.md`.
Ключевые разделы: 10, 17–21, 54–60, 87–99.
Следовать D-008, D-011, D-013, D-015, D-016.

## START_HEAD / baseline
`8b1b4a027eadbe41626f28fcf3c12db338a31b0a`
Reserved chains:
- central next `013`;
- tenant/local next `130`.

## Разрешённый scope
- `database/migrations/*` starting 013+;
- `database/migrations-local/*` starting 130+;
- migration/snapshot/comparator scripts;
- migration fixtures;
- cross-stream regression/security tests;
- deterministic schema/access evidence tooling.

## Запрещённый scope
- production migration/deploy;
- UI/product redesign;
- competing resolver/registry/assignment mechanisms;
- `/erp`;
- unrelated schema cleanup.

## Обязательный результат
1. Validate final schema placement against actual runtime/onboarding DB architecture before writing SQL.
2. Create numbered idempotent/controlled migrations for approved schema requirements from Agents 02–05 only.
3. Central structures must support global identity/membership/dynamic roles/page permissions/personal overrides as approved; tenant/native assignment storage must match canonical entity placement.
4. Preserve `superadmin` outside company role model.
5. Stable system leader marker/key, unique membership `(user_id,company_id)`, normalized role uniqueness within company and necessary FK/index constraints.
6. Vehicle assignment technical key uses existing `vehicle_unit` / `vehicle_units.id`.
7. BEFORE snapshot captures actual current effective access for each existing `(user,company)` across page keys + Client/Contractor/Vehicle/Driver/Linear/Waste.
8. Migration seeds Руководитель/Логист+/Логист based on proven current access, not role label assumption alone.
9. Legacy grants/ownership may seed assignments only where trustworthy evidence exists; never invent assignments.
10. AFTER captures identical dimensions; comparator reports concrete expansion/restriction diff.
11. Unexpected expansion OR restriction = failure.
12. Regression suite independently tests integrated system, not only migration SQL.

## Обязательные тесты
- clean install / migration ordering where harness permits;
- upgrade fixture from BEFORE legacy state;
- unique membership;
- cross-company isolation;
- leader system constraints + last-active-leader application contract;
- role/page/data override persistence;
- Vehicle technical identity;
- many-to-many assignments and no invented mappings;
- page allow/deny, direct URL, POST/AJAX/shared endpoint;
- personal allow/deny/data override;
- immediate revocation without relogin;
- Client/Contractor/Vehicle/Driver ALL/ONLY_OWN/NONE surfaces;
- Linear/Waste creator scope;
- finance explicit normal-role page access;
- multi-company one identity;
- BEFORE↔AFTER exact comparison and human-readable diff on deliberate mismatch.

## Критерии готовности
Migration chain conflict-free; rollback/restore strategy documented; BEFORE↔AFTER PASS on representative/approved fixture or authorized target; regression matrix PASS; no production mutation.

## Формат отчёта
`../reports/agent-07.md` with exact migrations, commands/results, snapshot artifacts/hashes, mismatch policy, risks and handoff.

## Что агент НЕ должен делать
Не применять production migrations; не change business rules to make parity green; не synthesize historical assignments; не renumber чужие historical migrations; не touch `/erp`; не redesign app layers.