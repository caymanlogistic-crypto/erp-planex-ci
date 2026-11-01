# КАРТА UI-АРХИТЕКТУРЫ ERP PLANEX

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

Документ для дизайн-аудитора. Описывает всю структуру интерфейса, навигацию, страницы, роли, видимость и технические детали вёрстки.

Дата: 2026-06-17

---

## 1. ГЛАВНЫЕ МЕНЮ (SIDEBAR)

Боковая панель (`aside.app-sidebar`) рендерится в `app/View/layouts/main.php` на основе `$_SESSION['role_code']`. Меню различается для каждой роли.

### 1.1. SUPERADMIN

Группа **СИСТЕМА**:

| Пункт | Ссылка | Состояние |
|---|---|---|
| Компании | `/superadmin/companies` | Активен |
| Настройки | — | `is-disabled` (неактивен) |

Всего: 2 пункта в сайдбаре. Группа "ОПЕРАЦИИ" отсутствует.

### 1.2. COMPANY_OWNER

Группа **ОПЕРАЦИИ** (верхняя):

| Пункт | Ссылка | Состояние |
|---|---|---|
| Рейсы | — | `is-disabled` |
| Водители | `/company/drivers` | Активен |
| Транспортные единицы | `/company/vehicles` | Активен |
| Транспортные комплекты | `/company/vehicle-sets` | Активен |
| Водитель+ТС | `/company/driver-vehicle-blocks` | Активен |
| Клиенты | `/company/clients` | Активен |
| Подрядчики | `/company/contractors` | Активен |
| Экипажи | `/company/crews` | Активен |

Группа **СИСТЕМА** (нижняя, отделена `.nav-spacer`):

| Пункт | Ссылка | Состояние |
|---|---|---|
| Пользователи | `/company/logists` | Активен |
| Настройки | — | `is-disabled` |

Всего: 10 пунктов (8 активных + 2 disabled).

### 1.3. LOGIST

Группа **ОПЕРАЦИИ** (верхняя):

Полностью идентична COMPANY_OWNER (те же 8 пунктов, «Рейсы» disabled, остальные активны).

Группа **СИСТЕМА** (нижняя, отделена `.nav-spacer`):

| Пункт | Ссылка | Состояние |
|---|---|---|
| Настройки | — | `is-disabled` |

Пункт «Пользователи» у логиста **отсутствует**. Только один disabled-пункт.

Всего: 9 пунктов (7 активных + 2 disabled).

### Общая структура сайдбара

- `.nav-group` — группа пунктов
- `.nav-section-label` — заголовок группы (СИСТЕМА / ОПЕРАЦИИ)
- `.nav-item` — пункт меню (ссылка или span)
- `.nav-item.is-disabled` — неактивный пункт (рендерится как `<span>`, не ссылка)
- `.nav-item.is-active` — активный пункт (определяется по `str_starts_with($_SERVER['REQUEST_URI'], ...)`)
- `.nav-spacer` — визуальный разделитель между группами
- Каждый `.nav-item` содержит встроенный SVG-значок (`.nav-icon`) и текстовую метку (`.nav-label`)

---

## 2. ЗОНЫ СТРАНИЦ

### 2.1. AUTH — Страницы аутентификации

Используют макет `auth-layout.php` (отдельный, без сайдбара и топбара).

| Маршрут | Файл | Описание |
|---|---|---|
| `/login` | `login_form.php` | Форма входа. Содержит `.login-card` с полями логин/пароль. 5 состояний: dev-seed-пароль, ошибки валидации, authError, multiLogistError, обычная форма. |
| `/logout` | — | Выход (перенаправление, нет view) |

### 2.2. SUPERADMIN — Страницы суперадминистратора

Используют макет `main.php`. Все пути начинаются с `/superadmin`.

**Уровень реестра компаний:**

| Маршрут | Файл | Тип | Описание |
|---|---|---|---|
| `/superadmin` | `superadmin_dashboard.php` | dashboard | Центральная панель управления |
| `/superadmin/companies` | `superadmin_companies.php` | list | Реестр всех компаний. Фильтр по поиску (название/ИНН) и статусу. |
| `/superadmin/companies/create` | `superadmin_companies_create.php` | create | Форма создания новой компании (экспедитора) |

**Уровень карточки компании `/superadmin/companies/{id}`:**

| Маршрут | Файл | Тип | Описание |
|---|---|---|---|
| `/superadmin/companies/{id}` | `superadmin_company_view.php` | view | Карточка компании: реквизиты, статус, статистика |
| `/superadmin/companies/{id}/edit` | `superadmin_company_edit.php` | edit | Форма редактирования реквизитов компании |
| `/superadmin/companies/{id}/delete` | `superadmin_company_delete.php` | delete-confirm | Подтверждение удаления компании |
| `/superadmin/companies/{id}/users` | `superadmin_company_users.php` | list | Список пользователей компании (руководитель + логисты) |
| `/superadmin/companies/{id}/owner` | `superadmin_company_owner_view.php` | view | Карточка руководителя компании |
| `/superadmin/companies/{id}/create-owner` | `superadmin_company_owner_create.php` | create | Форма создания руководителя (если не создан) |
| `/superadmin/companies/{id}/owner/edit` | `superadmin_company_owner_edit.php` | edit | Редактирование руководителя |
| `/superadmin/companies/{id}/users/logists/create` | `superadmin_company_logist_create.php` | create | Создание пользователя-логиста |
| `/superadmin/companies/{id}/users/logists/{logist_id}` | `superadmin_company_logist_view.php` | view | Карточка логиста |
| `/superadmin/companies/{id}/users/logists/{logist_id}/edit` | `superadmin_company_logist_edit.php` | edit | Редактирование логиста |
| `/superadmin/companies/{id}/directories` | `superadmin_company_directories.php` | stats | Обзор справочников компании со статистикой |
| `/superadmin/companies/{id}/documents` | `superadmin_company_documents.php` | list | Все документы компании |
| `/superadmin/companies/{id}/access-grants` | `superadmin_company_access_grants.php` | list | Все гранты доступа компании |
| `/superadmin/companies/{id}/clients` | `superadmin_company_clients.php` | list | Клиенты компании |
| `/superadmin/companies/{id}/contractors` | `superadmin_company_contractors.php` | list | Подрядчики компании |
| `/superadmin/companies/{id}/drivers` | `superadmin_company_drivers.php` | list | Водители компании |
| `/superadmin/companies/{id}/vehicles` | `superadmin_company_vehicles.php` | list | Транспортные единицы компании |
| `/superadmin/companies/{id}/crews` | `superadmin_company_crews.php` | list | Экипажи компании |

### 2.3. COMPANY — Страницы уровня компании

Используют макет `main.php`. Все пути начинаются с `/company`.

**Дашборд:**

| Маршрут | Файл | Тип | Описание |
|---|---|---|---|
| `/company` | `company_dashboard.php` | dashboard | Панель управления компании. Показывает доступные разделы (dash-link). Для owner — ссылка на Пользователи + справочники. Для logist — только справочники. |

**Справочники — списки (list):**

| Маршрут | Файл | Описание |
|---|---|---|
| `/company/drivers` | `company_drivers.php` | Список водителей. Таблица `.tbl`: ФИО, контакты, документы, статус. Для owner — колонка «Создал». Кнопки: Просмотр, Редактировать, Документы. |
| `/company/vehicles` | `company_vehicles.php` | Список транспортных единиц. Госномер, марка, модель, тип, статус. |
| `/company/vehicle-sets` | `company_vehicle_sets.php` | Список транспортных комплектов. Тип, основная ед., доп. ед., статус. |
| `/company/driver-vehicle-blocks` | `company_driver_vehicle_blocks.php` | Список блоков Водитель+ТС. Водитель, комплект, статус. |
| `/company/clients` | `company_clients.php` | Список клиентов. ID, название, ИНН, статус, дата. |
| `/company/contractors` | `company_contractors.php` | Список подрядчиков. Название, ИНН, тип, статус. |
| `/company/crews` | `company_crews.php` | Список экипажей. Подрядчик, водитель, ТС, статус. |
| `/company/logists` | `company_logists.php` | Список пользователей (только для owner). ФИО, роль, статус. |
| `/company/documents` | `company_documents.php` | Список документов (с параметрами `?entity_type=X&entity_id=Y`). Тип документа, файл, размер, статус, дата. Удалённые — с классом `.is-muted-row` и бейджем «Удалён». |

**Создание (create):**

| Маршрут | Файл |
|---|---|
| `/company/drivers/create` | `company_drivers_create.php` |
| `/company/vehicles/create` | `company_vehicles_create.php` |
| `/company/vehicle-sets/create` | `company_vehicle_sets_create.php` |
| `/company/driver-vehicle-blocks/create` | `company_driver_vehicle_blocks_create.php` |
| `/company/clients/create` | `company_clients_create.php` |
| `/company/contractors/create` | `company_contractors_create.php` |
| `/company/crews/create` | `company_crews_create.php` |
| `/company/logists/create` | `company_logists_create.php` |
| `/company/documents/upload` | `company_documents_upload.php` |

**Карточка (view):**

| Маршрут | Файл | Подсущности на карточке |
|---|---|---|
| `/company/drivers/{id}` | `company_driver_view.php` | Телефоны, связанные DVB-блоки, доступ логистов, служебные данные |
| `/company/vehicles/{id}` | `company_vehicle_view.php` | Доступ логистов, служебные данные |
| `/company/vehicle-sets/{id}` | `company_vehicle_set_view.php` | Основная/доп. ед., доступ логистов |
| `/company/driver-vehicle-blocks/{id}` | `company_driver_vehicle_block_view.php` | Водитель, комплект, доступ логистов |
| `/company/clients/{id}` | `company_client_view.php` | Доступ логистов |
| `/company/contractors/{id}` | `company_contractor_view.php` | Контакты, история налогообложения, доступ логистов, служебные данные |
| `/company/crews/{id}` | `company_crew_view.php` | Подрядчик, DVB-блок, водитель, комплект, ТЕ, доступ логистов |
| `/company/logists/{id}` | `company_logist_view.php` | Доступ логистов |

**Редактирование (edit):**

| Маршрут | Файл |
|---|---|
| `/company/drivers/{id}/edit` | `company_driver_edit.php` |
| `/company/vehicles/{id}/edit` | `company_vehicle_edit.php` |
| `/company/vehicle-sets/{id}/edit` | `company_vehicle_set_edit.php` |
| `/company/driver-vehicle-blocks/{id}/edit` | `company_driver_vehicle_block_edit.php` |
| `/company/clients/{id}/edit` | `company_client_edit.php` |
| `/company/contractors/{id}/edit` | `company_contractor_edit.php` |
| `/company/crews/{id}/edit` | `company_crew_edit.php` |
| `/company/logists/{id}/edit` | `company_logist_edit.php` |

### 2.4. SYSTEM / ERROR / FALLBACK

| Маршрут | Назначение |
|---|---|
| `/` | Корень — редирект в зависимости от роли |
| `/test` | Тестовая страница |
| `/test-db` | Тест соединения с БД |
| `/ui-demo` | `ui_demo.php` — демо-страница UI-компонентов |

---

## 3. СВЯЗИ СУЩНОСТЕЙ

### 3.1. Архитектурная схема

```
Подрядчик (contractor) + Блок «Водитель+ТС» (driver_vehicle_block) = Экипаж (crew)
                                                    │
                         ┌──────────────────────────┤
                         │                          │
                    Водитель (driver)     Транспортный комплект (vehicle_set)
                                                    │
                                    ┌───────────────┤
                                    │               │
                           Основная ТЕ (primary)  Доп. ТЕ (secondary)
                           → vehicle_units        → vehicle_units
```

### 3.2. Таблицы БД и их назначение

**contractors (Подрядчики):**
- Поля: `name`, `inn`, `kpp`, `ogrn`, `contractor_type`, `legal_address`, `physical_address`
- Банковские реквизиты: `bank_account`, `bank_name`, `bank_bik`, `bank_corr_account`
- Статус: `active` / `inactive` / `archived`
- `comments`, `created_by_user_id`, `created_by_role`

**contractor_contacts (Контакты подрядчика):**
- Множественные контакты, привязаны к `contractor_id`
- Поля: `contact_person`, `phone`, `email`, `is_primary` (главный контакт), `is_document_email` (email для документов), `comment`
- На карточке подрядчика: таблица контактов, inline-редактирование, кнопки «Сделать главным», «Email для документов»

**contractor_tax_history (История налогообложения):**
- Append-only таблица, привязана к `contractor_id`
- Поля: `tax_system` (ОСНО, УСН 6%, УСН 15% и т.д.), `vat_mode` (НДС), `effective_from` (дата начала действия), `comment`
- На карточке подрядчика: таблица истории + форма добавления записи
- Актуальная система определяется по последней записи (max effective_from)

**drivers (Водители):**
- Поля: `full_name`, `passport_number`, `passport_issued_by`, `passport_department_code`, `passport_issue_date`
- Водительское удостоверение: `license_number`, `license_category`, `license_issue_date`, `license_expire_date`
- `snils`, `comments`, `status`

**driver_phones (Телефоны водителей):**
- Множественные телефоны, привязаны к `driver_id`
- Поля: `phone`, `is_main` (основной), `comment`
- На карточке водителя: таблица телефонов, inline-редактирование, кнопка «Сделать основным»

**vehicle_units (Транспортные единицы):**
- Бывшая таблица `vehicles` (переименована)
- Поля: `plate_number` (госномер), `brand` (марка), `model` (модель), `vehicle_type`, `vin`, `status`
- Типы: `tractor` (тягач), `trailer` (прицеп), `semi_trailer` (полуприцеп), `single` (одиночное ТС)

**vehicle_sets (Транспортные комплекты):**
- Поля: `set_type` (тип), `primary_unit_id` → `vehicle_units`, `secondary_unit_id` → `vehicle_units` (nullable)
- Типы комплектов: `single` (одиночное ТС), `coupling` (тягач + прицеп), `road_train` (тягач + полуприцеп)
- `status`, `comments`

**driver_vehicle_blocks (Блоки Водитель+ТС):**
- Связка: `driver_id` + `vehicle_set_id`
- Статус, комментарий
- На карточке водителя: таблица связанных DVB-блоков
- На карточке DVB: водитель + комплект + единицы

**crews (Экипажи):**
- Связка: `contractor_id` + `driver_vehicle_block_id`
- Статус, комментарий
- На карточке экипажа: подрядчик (ссылка), DVB-блок (ссылка), водитель, комплект, транспортные единицы (каскадно)

**documents (Документы):**
- Soft delete: `deleted_at IS NULL` = активный
- Привязка к любой сущности через `entity_type` + `entity_id`
- Поля: `document_type`, `original_name`, `mime_type`, `file_size`, `file_path`, `comments`, `status`, `deleted_at`
- Допустимые `entity_type`: `client`, `contractor`, `driver`, `vehicle_unit`, `vehicle_set`, `driver_vehicle_block`, `crew`

**entity_access_grants (Доступы):**
- Поля: `company_id`, `entity_type`, `entity_id`, `granted_to_user_id`, `access_level`, `created_by_user_id`
- Уровни доступа: `view` (просмотр), `edit` (редактирование)

---

## 4. ТИПЫ СТРАНИЦ

### 4.1. List (Список) — для каждой сущности

Общая структура:
1. `page-head` — хедер: кнопка «← К списку» (если из drill-down), заголовок `<h1>`, описание `<p class="text-muted">`
2. Панель фильтров (опционально, пока только на superadmin/companies: поиск + select статуса)
3. `panel` -> `panel-body` -> `tbl-wrap` -> `table.tbl`
4. Строки таблицы с `cell-main` / `cell-sub` для двухстрочных ячеек
5. Колонка действий `.col-actions` с кнопками `.btn-toolbar`: Просмотр, Редактировать, Документы
6. Для owner: дополнительная колонка «Создал» (на drivers, опционально на других)
7. Для logist: фильтрация записей по владельцу/грантам

**Сущности со списками:**
- contractors (Подрядчики)
- drivers (Водители)
- vehicles (Транспортные единицы)
- vehicle-sets (Транспортные комплекты)
- driver-vehicle-blocks (Блоки Водитель+ТС)
- crews (Экипажи)
- clients (Клиенты)
- documents (Документы — с параметрами entity_type + entity_id)
- logists (Пользователи — только для owner)

### 4.2. Create (Создание) — для каждой сущности

Общая структура:
1. `page-head` с заголовком «Создать ...»
2. Форма `method="post"` на `panel` -> `panel-body`
3. Секции формы через `.form-section` с заголовком `.panel-head-title` (или `h3.panel-head-title`)
4. Поля: `.field` > `.field-label` + `.field-input` / `.field-select` / `.field-textarea`
5. Обязательные поля помечены `<span class="req">*</span>`
6. Ошибки валидации: `.field-msg.is-error`
7. Кнопки: `.form-actions` с `.btn.btn-primary` (Сохранить/Создать) + `.btn.btn-ghost` (Отмена/Назад)

**Формы создания существуют для всех сущностей** (9 create-форм в `/company/*/create`).

Плюс особая форма: `company_documents_upload.php` — загрузка документа с выбором `entity_type` + `entity_id` через GET-параметры.

### 4.3. View (Карточка) — для каждой сущности

Общая структура:
1. `page-head` с `.page-eyebrow` (категория / компания), `<h1>` (название сущности), кнопки действий
2. `panel` -> `panel-body`
3. Секции через `.form-section` с заголовком `.panel-head-title`
4. Данные выводятся в `<dl class="kv">` — пары dt/dd (ключ-значение)
5. Подсущности выводятся в таблицах `.tbl` внутри секций:
   - driver_view: phones (inline-edit), driver_vehicle_blocks (таблица), grants
   - contractor_view: contacts (inline-edit), tax_history (таблица + форма добавления), grants
   - crew_view: contractor (ссылка), dvb (ссылка), driver, set, units (каскадно), grants
   - vehicle_set_view: primary_unit, secondary_unit, grants
   - dvb_view: driver, set, grants
6. Для owner: секция «Доступ логистов» с таблицей грантов и формой выдачи
7. Секция «Служебные данные»: создал, создан, обновил, обновлён
8. Нижние кнопки: Документы, Архивировать (POST-форма с confirm)

**Карточки существуют для всех 8 entity-типов**, плюс:
- `company_logist_view.php` — карточка пользователя-логиста
- `superadmin_company_view.php` — карточка компании
- `superadmin_company_owner_view.php` — карточка руководителя
- `superadmin_company_logist_view.php` — карточка логиста (из superadmin)

### 4.4. Edit (Редактирование) — для каждой сущности

Общая структура идентична Create, но:
1. Заголовок «Редактировать ...»
2. Поля предзаполнены значениями из БД: `value="<?= e($old['field'] ?? $entity['field']) ?>"`
3. Кнопка submit: «Сохранить» вместо «Создать»
4. Кнопка отмены: «← К карточке» или «← К списку»
5. Форма action: `POST /company/{entity}/{id}/edit`

**Формы редактирования существуют для всех 8 entity-типов**, плюс:
- `company_logist_edit.php` — редактирование пользователя
- `superadmin_company_edit.php` — редактирование компании
- `superadmin_company_owner_edit.php` — редактирование руководителя
- `superadmin_company_logist_edit.php` — редактирование логиста (из superadmin)

---

## 5. ВИДИМОСТЬ ПО РОЛЯМ

### 5.1. SUPERADMIN

- Видит реестр **всех** компаний системы (`/superadmin/companies`)
- Может создавать, редактировать, удалять компании
- Для каждой компании видит **все данные**: пользователей, руководителя, логистов, справочники, документы, гранты
- Режим: **только чтение (мониторинг)** данных компаний — не может создавать/редактировать сущности внутри компаний (только просмотр списков и карточек)
- Исключение: может создавать пользователей компании (руководителя и логистов) и управлять статусом компании
- Видит удалённые документы (с визуальным индикатором `is-muted-row`)
- Сайдбар: только «СИСТЕМА / Компании» + disabled «Настройки»

### 5.2. COMPANY_OWNER

- Видит **все записи** в своей компании (без ограничений по создателю)
- Может создавать, редактировать, архивировать любые сущности
- Может управлять пользователями (логистами): создавать, редактировать
- Может выдавать и просматривать **гранты доступа** (`entity_access_grants`) логистам на любойentity
- Видит удалённые документы (с визуальным индикатором)
- Видит колонку «Создал» в списках (кто из пользователей создал запись)
- Сайдбар: ОПЕРАЦИИ (8 пунктов) + СИСТЕМА (Пользователи + disabled Настройки)

### 5.3. LOGIST (владелец записи)

- Видит записи, которые **сам создал** (`created_by_user_id = session.user_id`)
- Может редактировать и архивировать **свои** записи
- Видит свои документы, может удалять (архивировать) свои документы
- **Не видит** секцию грантов на карточках
- **Не видит** меню «Пользователи» в сайдбаре
- Сайдбар: ОПЕРАЦИИ (8 пунктов) + СИСТЕМА (только disabled Настройки)

### 5.4. LOGIST (с грантом доступа)

- Видит записи, к которым ему выдан грант через `entity_access_grants`
- Уровень `view`: только просмотр карточки, без кнопок редактирования
- Уровень `edit`: просмотр + редактирование
- **Не видит** секцию грантов (даже на тех карточках, к которым имеет доступ)
- Гранты каскадные: view-грант на crew даёт view-доступ к связанным contractor, driver, vehicle_set, vehicle_units
- Гранты не показываются в UI логиста — он просто видит «лишние» записи в списках и может открыть их карточки

---

## 6. КАСКАДНАЯ ВИДИМОСТЬ

Связи между сущностями отображаются каскадно при просмотре:

**При просмотре Экипажа (crew view):**
- Виден подрядчик (contractor) — ссылка на карточку подрядчика + ИНН
- Виден блок DVB — ссылка на карточку блока
- Виден водитель (driver) — имя + телефон
- Виден тип транспортного комплекта (vehicle_set.set_type)
- Видны госномера транспортных единиц (primary_plate + secondary_plate)

**При просмотре Водителя (driver view):**
- Видны связанные DVB-блоки в таблице: ID блока, тип комплекта, основная ед., доп. ед., статус
- Каждый блок — ссылка на его карточку

**При просмотре DVB-блока (dvb view):**
- Виден водитель (имя + телефон)
- Виден транспортный комплект (тип + госномера)

**При просмотре Транспортного комплекта (vehicle_set view):**
- Видна основная ТЕ (госномер, марка, модель, VIN)
- Видна дополнительная ТЕ (госномер, марка, модель, VIN) — если есть

**Гранты доступа тоже каскадные:**
- Выдача view-гранта на crew автоматически даёт view-доступ ко всем связанным сущностям
- Выдача edit-гранта на crew даёт edit-доступ к связанным сущностям

---

## 7. ДОКУМЕНТЫ

### 7.1. Модель

- Таблица `documents`
- Связь с сущностями: `entity_type` (строка) + `entity_id` (int)
- Soft delete: `deleted_at IS NULL` = активный документ; `deleted_at IS NOT NULL` = удалён (архивирован)
- Загрузка файла: сохраняется на диск, в БД пишутся метаданные
- Замена файла: upload с параметром `replace=DOC_ID`

### 7.2. Страницы документов

- `/company/documents?entity_type=X&entity_id=Y` — список документов сущности
- `/company/documents/upload?entity_type=X&entity_id=Y` — загрузка нового документа
- `/company/documents/upload?entity_type=X&entity_id=Y&replace=DOC_ID` — замена существующего
- `/company/documents/download?id=DOC_ID` — скачивание файла
- `/company/documents/delete?id=DOC_ID&redirect=...` — архивирование (POST)

### 7.3. Видимость документов по ролям

- **COMPANY_OWNER**: видит все документы компании, включая удалённые. Удалённые строки помечаются классом `.is-muted-row` и бейджем «Удалён».
- **LOGIST (владелец)**: видит свои документы. Может удалять (архивировать) свои.
- **LOGIST (грант)**: видит документы сущностей, к которым имеет грант.
- **SUPERADMIN**: видит все документы компании в режиме мониторинга (через `/superadmin/companies/{id}/documents`).

### 7.4. Состояния страницы документов

1. `$entityTypeError` — неизвестный тип сущности
2. `$company === null` — компания не найдена
3. `$company['status'] !== 'active'` — компания неактивна
4. `$dbError` — ошибка БД
5. `$entityNotFound` — сущность не найдена
6. `empty($documents)` — пустой список (empty state с кнопкой загрузки)
7. Список документов с таблицей

### 7.5. Состояния страницы загрузки

1. `$entityTypeError` — неизвестный тип
2. `$company === null` — компания не найдена
3. `$company['status'] !== 'active'` — компания неактивна
4. `$dbError` — ошибка БД
5. `$entityNotFound` — сущность не найдена
6. `$success` — успешная загрузка (показ созданного документа)
7. Обычная форма загрузки (с опциональным режимом замены `$replaceDocId > 0`)

---

## 8. ГРАНТЫ ДОСТУПА

### 8.1. Модель

Таблица `entity_access_grants`:
- `company_id` — идентификатор компании
- `entity_type` — тип сущности (contractor, driver, vehicle_unit, vehicle_set, driver_vehicle_block, crew, client)
- `entity_id` — ID сущности
- `granted_to_user_id` — логист, которому выдан доступ
- `access_level` — `view` или `edit`
- `created_by_user_id` — кто выдал (company_owner или superadmin)

### 8.2. UI выдачи грантов

Гранты видны **только** company_owner на карточках сущностей (view-страницах).

Секция «Доступ логистов»:
1. Заголовок `.panel-head-title`
2. Таблица существующих грантов: Логист, Доступ (view/edit), Дата выдачи
3. Форма выдачи нового гранта:
   - Скрытые поля: `entity_type`, `entity_id`, `redirect`
   - Выпадающий список логистов (`.field-select`)
   - На некоторых карточках: выбор уровня доступа (view/edit)
   - Кнопка «Дать доступ»

Страницы доступа:
- `/company/access-grants/grant` — POST-эндпоинт выдачи гранта
- `/superadmin/companies/{id}/access-grants` — список всех грантов компании (superadmin)

### 8.3. Кто что видит

- **LOGIST** никогда не видит UI грантов. Он просто получает доступ к записям «молча».
- **COMPANY_OWNER** видит таблицу грантов на всех view-карточках и форму выдачи.
- **SUPERADMIN** видит общий список грантов компании через `/superadmin/companies/{id}/access-grants`.

### 8.4. Каскадные гранты

Грант на сущность верхнего уровня (crew) даёт доступ ко всем вложенным:
- `crew.view` → видит contractor, driver_vehicle_block, driver, vehicle_set, vehicle_units данного экипажа
- `crew.edit` → может редактировать contractor, driver_vehicle_block, driver, vehicle_set, vehicle_units данного экипажа
- Аналогично: грант на `driver_vehicle_block` даёт доступ к driver + vehicle_set + vehicle_units
- Грант на `vehicle_set` даёт доступ к vehicle_units (primary + secondary)

---

## 9. ТЕХНИЧЕСКИЕ ЗАМЕТКИ

### 9.1. Макеты (Layouts)

**`app/View/layouts/main.php`** — основной макет. Содержит:
- `<div class="app-shell">` — корневая обёртка
- `<header class="topbar">` — верхняя панель:
  - `.topbar-brand` — логотип (`.logo-mark`) + название ERP PLANEX
  - `.topbar-crumbs` — «хлебные крошки»: `$crumbTitle` + `$crumbContext`
  - `.topbar-right` — аватар (инициалы), имя пользователя, роль, кнопка «Выйти»
- `<aside class="app-sidebar">` — боковая панель навигации (вариативная по ролям)
- `<div class="app-main">` → `<main class="content">` — контентная область (`$content`)
- Подключает CSS: `erp-ui.css` (основной), `app.css` (совместимость)
- Подключает JS: `app.js`

**`app/View/layouts/auth-layout.php`** — макет аутентификации. Содержит:
- `<div class="auth-shell">` -> `<div class="auth-content">` -> `$content`
- Подключает только `app.css`
- Нет сайдбара, нет топбара

### 9.2. CSS-файлы

**`public/assets/css/erp-ui.css`** — основной системный CSS. Единственный источник UI-стилей. Содержит классы для:
- Каркаса приложения (`.app-shell`, `.topbar`, `.app-sidebar`, `.app-main`, `.content`)
- Навигации (`.nav-group`, `.nav-item`, `.is-active`, `.is-disabled`, `.nav-spacer`)
- Заголовков страниц (`.page-head`, `.page-eyebrow`, `.page-title`)
- Таблиц (`.tbl`, `.tbl-wrap`, `.col-tight`, `.col-num`, `.cell-main`, `.cell-sub`, `.col-muted`, `.col-mono`, `.col-actions`, `.is-muted-row`)
- Панелей (`.panel`, `.panel-body`, `.panel-head`, `.panel-head-title`)
- Форм (`.form-section`, `.section-title`, `.field`, `.field-label`, `.field-input`, `.field-select`, `.field-textarea`, `.field-msg`, `.is-error`)
- Кнопок (`.btn`, `.btn-primary`, `.btn-secondary`, `.btn-ghost`, `.btn-toolbar`, `.btn-danger`, `.btn-sm`, `.btn-full`)
- Статусов (`.badge`, `.badge-ok`, `.badge-warn`, `.dot`)
- Алертов/уведомлений (`.notice`, `.notice.warn`, `.notice.success`, `.notice.danger`, `.notice.notice-compact`)
- Пустых состояний (`.empty-state`, `.empty-title`, `.empty-desc`)
- Ключ-значение (`.kv`, `.kv-row`, `.kv-key`, `.kv-value`)
- Прочих: `.form-actions`, `.row-actions`, `.inline-form`, `.dash-link`, `.back-action`, `.hidden-subform`

**`public/assets/css/app.css`** — файл совместимости. Содержит остаточные стили. Подключается вторым.

### 9.3. Компоненты (Components)

Файлы в `app/View/components/` — PHP-функции-хелперы для генерации HTML:

| Файл | Функция | Назначение |
|---|---|---|
| `page_header.php` | `ui_page_header(title, desc, actionLabel)` | Генерация `.page-header` |
| `table.php` | `ui_table(headers, rows)` | Генерация таблицы `.table-wrap > table` |
| `status_badge.php` | `renderStatusBadge(status)` | Генерация `.badge` со статусом |
| `button.php` | `ui_button(label, variant, type)` | Генерация кнопки `.btn` |
| `alert.php` | `ui_alert(message, type)` | Генерация `.alert` |
| `empty_state.php` | `ui_empty_state(title, text, actionLabel)` | Генерация `.empty-state` |
| `input.php` | — | Поле ввода |
| `form_actions.php` | — | Контейнер кнопок формы |
| `view_formatters.php` | `ui_actor()`, `ui_date()`, `ui_access_level()`, `ui_contractor_type()`, `formatFileSize()` | Форматирование данных для отображения |

### 9.4. Паттерны страниц

**page-head + page-content:**
- Все страницы используют паттерн: `page-head` (заголовок) + `panel` (содержимое)
- `.page-head` содержит: заголовок `<h1>`, описание `<p class="text-muted">`, кнопки действий `.page-head-actions`
- Вариативно: `.page-eyebrow` над заголовком (категория / компания)

**Таблицы:**
- Таблицы используют класс `.tbl`
- Обёртка: `.tbl-wrap` вокруг `<table>`
- Ячейки: `.cell-main` (основная строка) / `.cell-sub` (подстрока) в `.cell-double`
- Колонки: `.col-tight` (узкая), `.col-num` (числовая), `.col-mono` (моноширинный), `.col-muted` (приглушённый), `.col-actions` (действия), `.col-truncate` (обрезание)
- Строки действий: `.row-actions` с кнопками `.btn-toolbar`
- Удалённые строки: `.is-muted-row`

**Формы:**
- Формы используют `class="panel"` на теге `<form>`
- Секции: `.form-section` с заголовком `h3.panel-head-title`
- Поля: `.field` > `.field-label` + `.field-input`/`.field-select`/`.field-textarea`
- Обязательные поля: `<span class="req">*</span>`
- Ошибки: `.field-msg.is-error`
- Контейнер кнопок: `.form-actions`

**Статусы:**
- Бейджи: `span.badge` + `.badge-ok` (зелёный), `.badge-warn` (жёлтый/архив), обычный (серый)
- Внутри бейджа: `span.dot` (цветная точка)
- Активен: `badge badge-ok`
- Неактивен: `badge`
- Архив: `badge badge-warn`
- Удалён: `badge badge-warn`

**Уведомления:**
- `div.notice.warn` — предупреждение
- `div.notice.success` — успех
- `div.notice.danger` — ошибка
- `div.notice.notice-compact` — компактное информационное

**Пустые состояния:**
- `div.empty-state` > `p.empty-title` + `p.empty-desc` + кнопка действия

### 9.5. Состояния страниц (общий паттерн)

Каждая страница имеет упорядоченную цепочку проверок:

1. `$company === null` — компания не найдена
2. `$company['status'] !== 'active'` — компания неактивна
3. `$dbError` — ошибка базы данных
4. `$entity === null` / `$entityNotFound` — сущность не найдена
5. `$accessDenied` — доступ запрещён (для logist)
6. `$success` — успешное действие (create/edit)
7. `$formError` / `$errors` — ошибки валидации формы
8. Основной контент (список / карточка / форма)

### 9.6. JavaScript

- `public/assets/js/app.js` — основной JS-файл
- Inline-скрипты на view-страницах: для показа/скрытия inline-форм (телефоны, контакты)
- Паттерн: `display:none` для скрытых форм, JS-функции `editPhone()`, `editContact()` для показа

### 9.7. Форматирование данных (view_formatters.php)

- `ui_date($value)` — форматирование даты
- `ui_actor($role, $userId, $user)` — «кто сделал» (Руководитель / Логист #ID)
- `ui_access_level($level)` — «Просмотр» / «Редактирование»
- `ui_contractor_type($type)` — тип подрядчика (ИП, ООО, Самозанятый, Физлицо)
- `formatFileSize($bytes)` — человекочитаемый размер файла
