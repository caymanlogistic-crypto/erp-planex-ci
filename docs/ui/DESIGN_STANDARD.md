# ERP PLANEX — Design Standard

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

## Мастер-источник

`FINAL3.html` — единственный утверждённый визуальный источник дизайн-системы ERP PLANEX. Все цвета, отступы, радиусы, тени и типографика в проекте обязаны соответствовать тому, что зафиксировано в этом файле. Дизайнер может обновлять FINAL3.html; кодер не имеет права визуально отклоняться от него без отдельного указания.

## Какие файлы использовать

- `public/assets/css/erp-ui.css` — единственный системный CSS-файл интерфейса ERP. Подключается на каждой странице.
- `docs/ui/DESIGN_STANDARD.md` — этот документ, правила работы.
- `docs/ui/DESIGN_SYSTEM_PREP_REPORT.md` — отчёт о том, что извлечено и какие риски есть.

Кодеру запрещено создавать второй CSS-файл с UI-стилями, second UI-kit, или подключать сторонние CSS-фреймворки (Bootstrap, Tailwind, AdminLTE и т.п.).

## Какие классы использовать кодеру

| Назначение | Классы |
|---|---|
| Каркас приложения | `.app`, `.topbar`, `.sidebar`, `.content` |
| Навигация | `.nav-group`, `.nav-item`, `.is-active`, `.is-parent`, `.is-open`, `.nav-sub`, `.nav-sub-item`, `.nav-count`, `.sub-count` |
| Заголовок страницы | `.page-head`, `.page-eyebrow`, `.page-title`, `.page-summary` |
| Таблицы | `.table-card`, `.table-toolbar`, `.table-scroll`, `.table`, `.pagination` |
| Панели / секции | `.panel-section`, `.section-title`, `.doc-list`, `.activity-list` |
| Формы | `.field`, `.field-label`, `.field-input`, `.field-select`, `.field-textarea`, `.field-msg` |
| Специализированные поля | `.custom-select` (поиск в селекте), `.multiselect`, `.entity-lookup`, `.date-field`, `.number-input`, `.input-group`, `.input-clearable` |
| Кнопки | `.btn`, `.btn-primary`, `.btn-secondary`, `.btn-ghost`, `.btn-toolbar`, `.btn-danger`, `.btn-icon` |
| Статусы | `.badge` + `.badge-ok/warning/danger/neutral`, `.doc-status` + `.doc-ok/warning/pending/rejected/archived`, `.flight-status` + `.status-*` |
| Алерты | `.form-alert` + `.alert-error/warning/success/info`, `.alert-mark`, `.alert-body` |
| Действия в строке | `.row-actions`, `.row-btn`, `.btn-row-neutral` |
| Пустые состояния | `.empty-state`, `.empty-icon`, `.empty-title`, `.empty-desc`, `.empty-code` |
| Модальные окна | `.modal-overlay`, `.modal`, `.modal-sm/md/lg`, `.modal-head`, `.modal-body`, `.modal-foot`, `.modal-icon` |
| Toast-уведомления | `.toast-stack`, `.toast`, `.is-success/warning/danger/info` |
| Вспомогательные | `.tabs-page`/`.tabs-insp`, `.skeleton`, `.stepper`, `.ctx-menu`, `.tooltip-wrap` |

Полный список селекторов — в самом `erp-ui.css` (структурирован секциями с заголовками-комментариями, как в FINAL3.html).

## Что кодеру запрещено

- Писать inline-`style=""` для визуального оформления (допустимо только для динамических значений типа `width` прогресс-бара, генерируемых JS).
- Придумывать новый визуальный стиль, новые цвета, новые радиусы, новые тени.
- Увеличивать радиусы скругления выше 2px без отдельного решения дизайнера.
- Использовать классы Bootstrap/AdminLTE/случайные SaaS-паттерны.
- Переписывать страницу целиком ради маленькой правки — работать точечно.
- Ломать утверждённую HTML/CSS-структуру существующих блоков без прямого указания.
- Создавать второй UI-kit или дублировать стили в самих страницах вместо `erp-ui.css`.
- Использовать красную кнопку (`.btn-danger`) вне сценария destructive confirmation.

## Как добавлять новые UI-компоненты

1. Сначала проверить, нет ли уже подходящего класса в `erp-ui.css` — большинство паттернов (поля, кнопки, статусы, таблицы, модалки, пустые состояния) уже покрыты.
2. Если компонента нет вообще, кодер не придумывает новый стиль самостоятельно.
3. Кодер выбирает ближайший по смыслу существующий паттерн (например, для произвольной информационной карточки — `.panel-section`, для незнакомого статуса — ближайший по семантике `.badge-*`).
4. В коде ставится комментарий `<!-- DESIGN_TODO: <что именно отсутствует> -->` (HTML) или `/* DESIGN_TODO: ... */` (CSS), чтобы дизайнер потом разобрал такие места отдельно.
5. Финальное визуальное решение принимает дизайнер — кодер не должен закрывать DESIGN_TODO самостоятельно изменением цвета/радиуса/тени.

## Что делать, если подходящего компонента нет

Использовать ближайший существующий паттерн как временное решение + `DESIGN_TODO`-комментарий. Не блокировать функциональную разработку из-за отсутствия точного визуального компонента — визуальная доработка происходит позже, отдельным проходом дизайнера по списку DESIGN_TODO (см. `DESIGN_SYSTEM_PREP_REPORT.md`, раздел "Требует будущей доработки").

## Runtime-critical modal/forms additions

Для новых ERP-форм, добавленных в существующие списки и карточки:

- сохранять текущую визуальную структуру списка: `page-head`, `table-card`, `table-toolbar`, `table.table`;
- modal-слой (`ModalShell`, create/view/edit overlays) не должен визуально ломать базовую страницу и не заменяет full-page CRUD без отдельного решения владельца;
- client/contractor формы должны использовать единый layout legal entity, единый ContactFields block и единый document section pattern, если документы показаны в этой форме;
- любое изменение modal/form UX считается неполным без browser runtime-проверки после правок.

## Поиск в панелях реестров

В панели фильтров реестра поле поиска размещается последним среди полей ввода и выбора, справа от фильтров и сортировки. Действия сброса размещаются после поля поиска. На узких экранах поиск занимает отдельную строку полной ширины.
