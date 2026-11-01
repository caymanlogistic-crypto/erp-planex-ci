# FULL UI REVISION — ERP PLANEX

> Архивный снимок июня 2026 года. Схема ролей, доступы, команды и интерфейс могли измениться; для текущей работы используйте AGENTS.md, LOCAL-DEVELOPMENT.md и актуальный canonical код.


## Актуализация 2026-06-26 — Исполнители рейса / Транспорт

Статус: подготовлен пакет исправлений `ERP_ROUTE_EXECUTORS_VEHICLE_SETS_FIXED_STRUCTURE_v4_SCHEMA_REAL.zip`; перед финальной фиксацией владелец должен применить файлы, проверить runtime и затем закоммитить результат.

Что обязательно учитывать дальше:

- `/company/route-executors` должен быть доступен `company_owner`, `senior_logist`, `logist`. Для `logist` пустой список — это не «Нет доступа», а нормальное пустое состояние с действием `Создать исполнителя рейса`.
- `Исполнитель рейса` — пользовательская сущность `Подрядчик + водитель + ТС`; технически создаются/используются `driver_vehicle_blocks` + `crews`.
- Реальная локальная схема БД: таблицы сущностей находятся в `erp_company_{id}`, а не в центральной `erp_planex`.
- `driver_vehicle_blocks` НЕ имеет поля `vehicle_id`. Запрещено писать `vehicle_id` в `driver_vehicle_blocks`.
- `driver_vehicle_blocks` хранит: `driver_id`, `vehicle_set_id`, `status`, `comments`, `created_by_user_id`, `created_by_role`, `updated_by_user_id`, `updated_by_role`.
- `crews` всё ещё имеет legacy-поля `vehicle_id` и `driver_id`; при создании исполнителя рейса `crews.vehicle_id` нужно заполнять значением `vehicle_sets.primary_vehicle_unit_id`, а `crews.driver_id` — выбранным водителем.
- Для `logist` выбор contractor/driver/vehicle_set и видимость списков должны фильтроваться по `created_by_user_id` + активным grants. Активный grant: `revoked_at IS NULL` и `access_level IN ('view','edit')`.
- На `/company/vehicle-sets` модалка создания транспорта должна быть в DOM всегда, включая пустой список, иначе кнопка `Добавить новый транспорт` визуально есть, но не работает.
- Если возникает ошибка схемы БД, сначала запускать `db_schema_route_executor.php` и сверять реальные `DESCRIBE/SHOW CREATE TABLE`, не угадывать поля.

## Цель пакета

Полный материал для дизайн-аудитора: карта всех страниц, меню, функции, роли доступа, валидные FULL HD full-page screenshots всех страниц.

## Дата подготовки

2026-06-17

## Commit / Branch

- Commit: 1e85dc7 (style(reference): address company reference UX audit)
- Branch: master

## Путь к screenshots

```
docs/design-audit/full-ui-revision/screenshots/
```

79 PNG screenshots, все 1920x1080+, fullPage, CSS загружен.

## Какие роли покрыты

Роли исторических снимков: superadmin, company_owner и два logist. Учётные данные удалены из публикуемой документации.

## Какие документы читать проверяющему

1. **PAGE_INVENTORY.md** — карта всех страниц с URL, меню, ролью, view-файлом, функцией, действиями, состояниями и screenshot-файлами.
2. **UI_ARCHITECTURE_MAP.md** — архитектура интерфейса: меню, зоны, связи сущностей, role-based visibility, каскадная видимость, документы, доступы.
3. **SCREENSHOT_MANIFEST.md** — связь каждого screenshot со страницей, размер, URL, роль, состояние, валидность.
4. **RUNTIME_ACCESS_NOTES.md** — тестовые доступы, runtime-сущности, ID, гранты, проверенные сценарии.

## Запуск текущей ERP

Этот пакет датирован июнем 2026 года и не является инструкцией для текущего запуска. Используйте `C:\plan-ex\ERP-PLANEX\LOCAL-DEVELOPMENT.md` и задачу с собственным `TASK_ID`, worktree и динамическим портом.

## Основные файлы пакета

```
docs/design-audit/full-ui-revision/
├── README.md                    ← этот файл
├── PAGE_INVENTORY.md            ← карта всех страниц
├── UI_ARCHITECTURE_MAP.md       ← архитектура интерфейса
├── SCREENSHOT_MANIFEST.md       ← манифест screenshots
├── RUNTIME_ACCESS_NOTES.md      ← заметки о доступах и runtime
└── screenshots/                 ← 79 FULL HD full-page screenshots
```

## Что этот пакет НЕ делает

- НЕ оценивает дизайн
- НЕ исправляет UI
- НЕ меняет функционал
- НЕ меняет бизнес-логику
- НЕ меняет маршруты
- НЕ меняет права доступа

## Статус

DESIGN_AUDIT_PACKAGE_READY

Все screenshots валидны: FULL HD (1920x1080+), fullPage, CSS загружен, контент виден.
