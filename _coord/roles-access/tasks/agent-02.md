# AGENT 02 TASK — Access Core

## Цель
Реализовать единый fail-closed Effective Access Resolver и server-side authorization contract для company requests без UI закреплений и без создания второго ACL.

## Контекст
Source of truth: `../00_IMPLEMENTATION_CONTRACT.md`.
Ключевые разделы: 17–21, 22–40, 60, 65–67, 96–99.
Следовать `../DECISIONS.md`: D-002, D-003, D-004, D-006, D-007, D-011, D-012.

## START_HEAD / baseline
`8b1b4a027eadbe41626f28fcf3c12db338a31b0a`
Рабочая ветка должна быть создана от START_HEAD либо от явно выданного Tech Lead integration baseline.

## Разрешённый scope
- `app/Service/*Access*` и новые access-core services/value objects;
- точечные authorization helpers/middleware/guards;
- targeted controller/route glue, если это необходимо для вызова единого resolver;
- access-core tests/fixtures.

## Запрещённый scope
- canonical PageRegistry и main layout/menu — Agent 03;
- membership/role CRUD persistence — Agent 04;
- entity assignments/query scope — Agent 05;
- admin UI — Agent 06;
- numbered migrations — Agent 07;
- production deploy/DB; `/erp`.

## Обязательный результат
1. Один canonical `EffectiveAccessResolver`/эквивалент проекта.
2. На каждом protected request актуально разрешаются user + company + membership + status + role/overrides; login-time permission snapshot не является authority.
3. `superadmin` остаётся существующим техническим исключением.
4. System leader получает полный company access и не ограничивается overrides.
5. Page decision поддерживает role permission + personal allow/deny.
6. Data decision поддерживает `ALL / ONLY_OWN / NONE` и personal override contract, но конкретные entity filters принадлежат Agent 05.
7. Unknown/missing protected state = deny.
8. Server action не принимает произвольный client-supplied `page_key` как authority.
9. Shared endpoint contract = server-owned OR-set страниц + последующий независимый data-scope.
10. Старые role-code checks не должны оставаться скрытым вторым deny после canonical resolver там, где поток владеет guard integration.

## Обязательные тесты
Минимум:
- missing membership deny;
- inactive membership deny;
- ordinary unknown role/config deny;
- leader full allow;
- leader override cannot restrict;
- role page allow/deny;
- personal allow/deny;
- personal data override;
- immediate revocation on next request;
- unknown endpoint/page mapping deny;
- shared endpoint OR-set cannot be expanded by client input;
- data-scope still required after shared page allow;
- cross-company membership isolation.

## Критерии готовности
Нет второго ACL; resolver contract потребляется другими потоками; no-relogin revocation доказан; tests PASS.

## Формат отчёта
Создать `../reports/agent-02.md` со статусом, branch/worktree, commit SHA, files, tests, interfaces, risks, dependencies, handoff.

## Что агент НЕ должен делать
Не расширять ТЗ; не рефакторить unrelated auth; не менять production; не трогать `/erp`; не писать миграции; не создавать page registry/menu; не реализовывать assignments/UI; не принимать продуктовые решения вне ТЗ.