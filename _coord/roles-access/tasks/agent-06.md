# AGENT 06 TASK — UI / UX

## Цель
Собрать простой owner-facing раздел `Роли и доступы` в существующей стилистике ERP PLANEX после стабилизации backend contracts, без нового UI kit и без popup-over-popup.

## Контекст
Source of truth: `../00_IMPLEMENTATION_CONTRACT.md`.
Ключевые разделы: 68–85, 97–99.
Визуальный ориентир: существующая страница `Линейные рейсы`; использовать существующие ERP components.
Следовать D-014.

## START_HEAD / baseline
`8b1b4a027eadbe41626f28fcf3c12db338a31b0a`
UI stream начинает integration только после утверждения contracts Agent 02–05.

## Разрешённый scope
- views/assets для `Роли и доступы`;
- UI controller orchestration поверх стабильных backend services;
- role/user modal rendering and client behavior;
- assignments tab immediate checkbox behavior;
- embedded `Закреплено за` blocks в existing Client/Contractor/Vehicle/Driver object modals/cards;
- targeted UI tests.

## Запрещённый scope
- resolver semantics — Agent 02;
- PageRegistry/main menu/layout ownership — Agent 03 (UI требования передать через report);
- membership/data query semantics — Agent 04/05;
- schema/migrations — Agent 07;
- production and `/erp`.

## Обязательный результат
1. Один admin section `Роли и доступы` с tabs `Роли | Пользователи | Закрепления`.
2. Roles tab: compact table, `+ Создать роль`, system badge, row opens one wide role modal, secondary `⋯`.
3. Role modal: Name, page tree derived from PageRegistry, 6 data scopes; one final `Сохранить`; sticky header/footer; inner scroll; unsaved-close warning using canonical ERP behavior.
4. Users tab: compact table, `+ Добавить пользователя`, active/disabled, one role, personal settings count.
5. User modal: status, role, tri-state page override (`По роли / Разрешить / Запретить`) with effective hint, data override (`По роли / Все / Только свои / Нет доступа`), one Save; role-change warning if personal settings exist.
6. Add user supports new account vs existing account membership and prevents duplicate account UX.
7. Assignments tab: employee selector + Client/Contractor/ТС/Driver lists. No search/filter/analytics in v1.
8. Assignment checkbox persists immediately; no global Save; on error revert checkbox + standard ERP error; no toast spam.
9. Existing object modal for leader includes `Закреплено за` inline; ordinary users do not get admin block.
10. No modal opens another modal for these flows.
11. Required short help texts exactly convey `Все`, `Только свои`, `Нет доступа`, `По роли`, `Персонально`, `Закреплено за` without ACL jargon.
12. Dense ERP table/modal visual language, current accent/typography/spacing.

## Обязательные тесты
- role modal save/cancel/dirty-close;
- user modal tri-state page and data overrides;
- leader system controls non-editable as required;
- last leader backend error rendered normally;
- assignment immediate save + rollback on error;
- disabled assigned user remains visible with `Отключён`;
- embedded assignment block does not open nested modal;
- body underlying modal/page scroll locked correctly;
- sticky header/footer remain accessible;
- viewport regression at 1920×1080, 1536×864, 1366×768 where test harness supports it;
- no horizontal overflow, clipped controls, duplicate modal overlay.

## Критерии готовности
First-time usability clear to business leader; no new UI kit; no popup-over-popup; all backend authority remains server-side; UI tests PASS.

## Формат отчёта
`../reports/agent-06.md` по REPORT contract, including screenshots/browser evidence if available.

## Что агент НЕ должен делать
Не расширять scope поиском/analytics/audit UI; не autosave role/user settings; не create new component system; не modify schema/access semantics; не touch production or `/erp`; не alter main layout independently.