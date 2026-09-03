# AGENT 03 TASK — Page Registry / Navigation / Endpoint Mapping

## Цель
Создать один canonical PageRegistry, из которого одновременно выводятся admin page matrix, navigation visibility и server-owned endpoint→page mapping.

## Контекст
Source of truth: `../00_IMPLEMENTATION_CONTRACT.md`.
Ключевые разделы: 22–32, 60, 68–70, 96–99.
Следовать D-005, D-006, D-012, D-014.

## START_HEAD / baseline
`8b1b4a027eadbe41626f28fcf3c12db338a31b0a`

## Разрешённый scope
- canonical registry service/config/value definitions;
- endpoint→page mapping integration;
- targeted route metadata integration;
- `app/View/layouts/main.php` — единственный владелец layout/menu;
- registry/navigation/endpoint mapping tests.

## Запрещённый scope
- resolver semantics — Agent 02;
- role/membership persistence — Agent 04;
- assignments/data query scope — Agent 05;
- admin modal implementation — Agent 06;
- migrations — Agent 07;
- production and `/erp`.

## Обязательный результат
1. Стабильный `page_key`, независимый от URL/title/menu position.
2. Один registry содержит page title/section/group/order + protected endpoints/actions.
3. Новая зарегистрированная page автоматически видна в role matrix; leader allow; ordinary roles default deny.
4. Menu строится/фильтруется из effective page access и registry; group/section показываются только при доступных descendants.
5. Direct URL and actions map server-side to registry keys.
6. Shared endpoint имеет server-owned exact OR-set allowed page keys.
7. Missing protected mapping fail-closed via Agent 02 contract.
8. Finance pages/actions переходят с hidden owner-only menu/gate на registry-driven page access; data/tenant controls остаются.
9. Не создавать вторую вручную синхронизируемую page list.

## Обязательные тесты
- page_key stable under title/group changes;
- new page appears in registry/matrix and ordinary default deny;
- menu leaf/group/section derivation;
- direct URL mapping;
- POST/AJAX/modal/JSON/file action mapping where registered;
- unknown protected action fail-closed;
- shared endpoint OR-set;
- client page_key cannot authorize;
- explicit finance page visible/usable to allowed normal role at registry layer.

## Критерии готовности
Один registry является source of truth для UI matrix/menu/server mapping; no duplicate menu ACL; tests PASS.

## Формат отчёта
`../reports/agent-03.md` по REPORT contract.

## Что агент НЕ должен делать
Не расширять ТЗ; не создавать вторую permission engine; не менять membership/assignment schema; не писать migrations; не внедрять новый UI-kit; не менять production или `/erp`.