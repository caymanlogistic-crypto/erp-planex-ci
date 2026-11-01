# SCREENSHOT MANIFEST — FULL UI REVISION

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

Generated: 2026-06-17

Total screenshots: 79

## Summary

| Role | Count |
|---|---|
| unauth | 1 |
| logist | 28 |
| company_owner | 31 |
| superadmin | 19 |
| **Total** | **79** |

## Validation

- All screenshots: width=1920, height>=1080 ✅
- fullPage: true ✅
- CSS loaded (erp-ui.css + app.css) ✅
- Content visible ✅
- No broken/cropped screenshots ✅

---

## UNAUTH

### auth__login__form__empty.png

- **URL**: `/login`
- **Role**: unauth
- **Login**: -
- **Menu**: AUTH
- **Page**: Вход в ERP PLANEX
- **Function**: Форма входа с полями логина и пароля
- **State**: empty_form
- **Designer Comment**: Проверить центрирование формы, поля, кнопку входа, сообщения об ошибках

## LOGIST

### logist1__contractors__edit__own_record.png

- **URL**: `/company/contractors/1/edit`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ / Подрядчики
- **Page**: Редактировать подрядчика
- **Function**: Редактирование своего подрядчика
- **State**: filled_form
- **Designer Comment**: Проверить форму редактирования для логиста-владельца

### logist1__contractors__list.png

- **URL**: `/company/contractors`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ / Подрядчики
- **Page**: Подрядчики
- **Function**: Список подрядчиков (свои записи + grant-доступ)
- **State**: filled
- **Designer Comment**: Логист видит свои записи, нет UI грантов

### logist1__contractors__view__own_record.png

- **URL**: `/company/contractors/1`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ / Подрядчики
- **Page**: Карточка подрядчика
- **Function**: Карточка своего подрядчика: полный доступ к редактированию
- **State**: filled
- **Designer Comment**: Логист-владелец: видит edit, archive, inline-формы. НЕ видит grants

### logist1__crews__list.png

- **URL**: `/company/crews`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ / Экипажи
- **Page**: Экипажи
- **Function**: Список экипажей (свои записи)
- **State**: filled
- **Designer Comment**: Проверить таблицу

### logist1__crews__view__own_record.png

- **URL**: `/company/crews/1`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ / Экипажи
- **Page**: Карточка экипажа
- **Function**: Карточка своего экипажа
- **State**: filled
- **Designer Comment**: Проверить карточку экипажа логиста-владельца

### logist1__dashboard__dashboard__filled.png

- **URL**: `/company/dashboard`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ
- **Page**: Dashboard
- **Function**: Главная страница (логист — владелец записей)
- **State**: filled
- **Designer Comment**: Проверить dashboard логиста

### logist1__drivers__edit__own_record.png

- **URL**: `/company/drivers/1/edit`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ / Водители
- **Page**: Редактировать водителя
- **Function**: Редактирование своего водителя
- **State**: filled_form
- **Designer Comment**: Проверить форму

### logist1__drivers__list.png

- **URL**: `/company/drivers`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ / Водители
- **Page**: Водители
- **Function**: Список водителей (свои записи)
- **State**: filled
- **Designer Comment**: Проверить таблицу водителей логиста

### logist1__drivers__view__own_record.png

- **URL**: `/company/drivers/1`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ / Водители
- **Page**: Карточка водителя
- **Function**: Карточка своего водителя
- **State**: filled
- **Designer Comment**: Проверить карточку водителя для логиста-владельца

### logist1__dvb__list.png

- **URL**: `/company/driver-vehicle-blocks`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ / Водитель+ТС
- **Page**: Водитель+ТС
- **Function**: Список блоков (свои записи)
- **State**: filled
- **Designer Comment**: Проверить таблицу

### logist1__dvb__view__own_record.png

- **URL**: `/company/driver-vehicle-blocks/1`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ / Водитель+ТС
- **Page**: Карточка блока
- **Function**: Карточка своего блока
- **State**: filled
- **Designer Comment**: Проверить карточку

### logist1__vehicle_sets__list.png

- **URL**: `/company/vehicle-sets`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ / Транспортные комплекты
- **Page**: Транспортные комплекты
- **Function**: Список комплектов (свои записи)
- **State**: filled
- **Designer Comment**: Проверить таблицу

### logist1__vehicle_sets__view__own_record.png

- **URL**: `/company/vehicle-sets/1`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ / Транспортные комплекты
- **Page**: Карточка комплекта
- **Function**: Карточка своего комплекта
- **State**: filled
- **Designer Comment**: Проверить карточку

### logist1__vehicles__edit__own_record.png

- **URL**: `/company/vehicles/1/edit`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ / Транспортные единицы
- **Page**: Редактировать ТЕ
- **Function**: Редактирование своей ТЕ
- **State**: filled_form
- **Designer Comment**: Проверить форму

### logist1__vehicles__list.png

- **URL**: `/company/vehicles`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ / Транспортные единицы
- **Page**: Транспортные единицы
- **Function**: Список ТЕ (свои записи)
- **State**: filled
- **Designer Comment**: Проверить таблицу ТЕ логиста

### logist1__vehicles__view__own_record.png

- **URL**: `/company/vehicles/1`
- **Role**: logist
- **Login**: logist_runtime_1
- **Menu**: ОПЕРАЦИИ / Транспортные единицы
- **Page**: Карточка ТЕ
- **Function**: Карточка своего тягача
- **State**: filled
- **Designer Comment**: Проверить карточку ТЕ для логиста-владельца

### logist2__contractors__list.png

- **URL**: `/company/contractors`
- **Role**: logist
- **Login**: logist_runtime_2
- **Menu**: ОПЕРАЦИИ / Подрядчики
- **Page**: Подрядчики
- **Function**: Список подрядчиков (ограниченная видимость)
- **State**: limited_or_empty
- **Designer Comment**: Может быть пустым — нет своих записей и нет grants

### logist2__crews__list.png

- **URL**: `/company/crews`
- **Role**: logist
- **Login**: logist_runtime_2
- **Menu**: ОПЕРАЦИИ / Экипажи
- **Page**: Экипажи
- **Function**: Список экипажей (ограниченная видимость)
- **State**: limited_or_empty
- **Designer Comment**: Проверить

### logist2__crews__view__grant_access.png

- **URL**: `/company/crews/1`
- **Role**: logist
- **Login**: logist_runtime_2
- **Menu**: ОПЕРАЦИИ / Экипажи
- **Page**: Карточка экипажа
- **Function**: Просмотр экипажа через grant-доступ
- **State**: grant_view
- **Designer Comment**: Ключевой экран: grant view без edit, cascade visibility

### logist2__dashboard__dashboard__filled.png

- **URL**: `/company/dashboard`
- **Role**: logist
- **Login**: logist_runtime_2
- **Menu**: ОПЕРАЦИИ
- **Page**: Dashboard
- **Function**: Главная страница (логист с grant-доступом)
- **State**: filled
- **Designer Comment**: Проверить dashboard

### logist2__drivers__list.png

- **URL**: `/company/drivers`
- **Role**: logist
- **Login**: logist_runtime_2
- **Menu**: ОПЕРАЦИИ / Водители
- **Page**: Водители
- **Function**: Список водителей (ограниченная видимость)
- **State**: limited_or_empty
- **Designer Comment**: Проверить ограниченную видимость

### logist2__drivers__view__grant_or_denied.png

- **URL**: `/company/drivers/1`
- **Role**: logist
- **Login**: logist_runtime_2
- **Menu**: ОПЕРАЦИИ / Водители
- **Page**: Карточка водителя
- **Function**: Попытка просмотра чужого водителя
- **State**: access_denied_or_grant_view
- **Designer Comment**: Ключевой экран: показывает "нет доступа" или grant-view без edit

### logist2__dvb__list.png

- **URL**: `/company/driver-vehicle-blocks`
- **Role**: logist
- **Login**: logist_runtime_2
- **Menu**: ОПЕРАЦИИ / Водитель+ТС
- **Page**: Водитель+ТС
- **Function**: Список блоков (ограниченная видимость)
- **State**: limited_or_empty
- **Designer Comment**: Проверить

### logist2__dvb__view__grant_or_denied.png

- **URL**: `/company/driver-vehicle-blocks/1`
- **Role**: logist
- **Login**: logist_runtime_2
- **Menu**: ОПЕРАЦИИ / Водитель+ТС
- **Page**: Карточка блока
- **Function**: Попытка просмотра чужого блока
- **State**: access_denied_or_grant_view
- **Designer Comment**: Проверить

### logist2__vehicle_sets__list.png

- **URL**: `/company/vehicle-sets`
- **Role**: logist
- **Login**: logist_runtime_2
- **Menu**: ОПЕРАЦИИ / Транспортные комплекты
- **Page**: Транспортные комплекты
- **Function**: Список комплектов (ограниченная видимость)
- **State**: limited_or_empty
- **Designer Comment**: Проверить

### logist2__vehicle_sets__view__grant_or_denied.png

- **URL**: `/company/vehicle-sets/1`
- **Role**: logist
- **Login**: logist_runtime_2
- **Menu**: ОПЕРАЦИИ / Транспортные комплекты
- **Page**: Карточка комплекта
- **Function**: Попытка просмотра чужого комплекта
- **State**: access_denied_or_grant_view
- **Designer Comment**: Проверить

### logist2__vehicles__list.png

- **URL**: `/company/vehicles`
- **Role**: logist
- **Login**: logist_runtime_2
- **Menu**: ОПЕРАЦИИ / Транспортные единицы
- **Page**: Транспортные единицы
- **Function**: Список ТЕ (ограниченная видимость)
- **State**: limited_or_empty
- **Designer Comment**: Проверить

### logist2__vehicles__view__grant_or_denied.png

- **URL**: `/company/vehicles/1`
- **Role**: logist
- **Login**: logist_runtime_2
- **Menu**: ОПЕРАЦИИ / Транспортные единицы
- **Page**: Карточка ТЕ
- **Function**: Попытка просмотра чужой ТЕ
- **State**: access_denied_or_grant_view
- **Designer Comment**: Проверить отображение ограничения доступа

## COMPANY_OWNER

### owner__clients__list__filled.png

- **URL**: `/company/clients`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Клиенты
- **Page**: Клиенты
- **Function**: Список клиентов
- **State**: filled
- **Designer Comment**: Проверить таблицу клиентов

### owner__contractors__create__form.png

- **URL**: `/company/contractors/create`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Подрядчики
- **Page**: Создать подрядчика
- **Function**: Форма создания подрядчика с реквизитами
- **State**: empty_form
- **Designer Comment**: Проверить форму, банковские реквизиты

### owner__contractors__edit__form.png

- **URL**: `/company/contractors/1/edit`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Подрядчики
- **Page**: Редактировать подрядчика
- **Function**: Форма редактирования подрядчика
- **State**: filled_form
- **Designer Comment**: Проверить форму редактирования

### owner__contractors__list__filled.png

- **URL**: `/company/contractors`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Подрядчики
- **Page**: Подрядчики
- **Function**: Список подрядчиков компании с основным контактом
- **State**: filled
- **Designer Comment**: Проверить таблицу, cell-main+cell-sub, действия

### owner__contractors__view__runtime_contractor.png

- **URL**: `/company/contractors/1`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Подрядчики
- **Page**: Карточка подрядчика
- **Function**: Детальная карточка: реквизиты, контакты, налоговая история, документы, гранты
- **State**: filled
- **Designer Comment**: Проверить структуру блоков, inline-формы контактов, таблицу налогов, документы, grants

### owner__crews__create__form.png

- **URL**: `/company/crews/create`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Экипажи
- **Page**: Создать экипаж
- **Function**: Форма создания экипажа (contractor + DVB)
- **State**: empty_form
- **Designer Comment**: Проверить форму выбора подрядчика и блока

### owner__crews__edit__form.png

- **URL**: `/company/crews/1/edit`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Экипажи
- **Page**: Редактировать экипаж
- **Function**: Форма редактирования экипажа
- **State**: filled_form
- **Designer Comment**: Проверить форму

### owner__crews__list__filled.png

- **URL**: `/company/crews`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Экипажи
- **Page**: Экипажи
- **Function**: Список экипажей
- **State**: filled
- **Designer Comment**: Проверить таблицу экипажей

### owner__crews__view__runtime_crew.png

- **URL**: `/company/crews/1`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Экипажи
- **Page**: Карточка экипажа
- **Function**: Детальная карточка экипажа #1
- **State**: filled
- **Designer Comment**: Проверить связь contractor + DVB, cascade visibility

### owner__dashboard__dashboard__filled.png

- **URL**: `/company/dashboard`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ
- **Page**: Dashboard компании
- **Function**: Главная страница компании
- **State**: filled
- **Designer Comment**: Проверить информативность dashboard

### owner__documents__list__filled.png

- **URL**: `/company/documents`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Документы
- **Page**: Документы
- **Function**: Список всех документов компании
- **State**: filled
- **Designer Comment**: Проверить таблицу документов, фильтры

### owner__documents__upload__form.png

- **URL**: `/company/documents/upload`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Документы
- **Page**: Загрузить документ
- **Function**: Форма загрузки документа с выбором типа сущности
- **State**: empty_form
- **Designer Comment**: Проверить форму загрузки

### owner__drivers__create__form.png

- **URL**: `/company/drivers/create`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Водители
- **Page**: Создать водителя
- **Function**: Форма создания водителя
- **State**: empty_form
- **Designer Comment**: Проверить форму

### owner__drivers__edit__form.png

- **URL**: `/company/drivers/1/edit`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Водители
- **Page**: Редактировать водителя
- **Function**: Форма редактирования водителя
- **State**: filled_form
- **Designer Comment**: Проверить форму

### owner__drivers__list__filled.png

- **URL**: `/company/drivers`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Водители
- **Page**: Водители
- **Function**: Список водителей с основным телефоном
- **State**: filled
- **Designer Comment**: Проверить таблицу водителей

### owner__drivers__view__runtime_driver.png

- **URL**: `/company/drivers/1`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Водители
- **Page**: Карточка водителя
- **Function**: Детальная карточка: данные, телефоны, блоки Водитель+ТС, документы, гранты
- **State**: filled
- **Designer Comment**: Проверить блоки данных, телефоны, связанные блоки

### owner__dvb__create__form.png

- **URL**: `/company/driver-vehicle-blocks/create`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Водитель+ТС
- **Page**: Создать блок
- **Function**: Форма создания блока Водитель+ТС
- **State**: empty_form
- **Designer Comment**: Проверить форму выбора водителя и комплекта

### owner__dvb__edit__form.png

- **URL**: `/company/driver-vehicle-blocks/1/edit`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Водитель+ТС
- **Page**: Редактировать блок
- **Function**: Форма редактирования блока Водитель+ТС
- **State**: filled_form
- **Designer Comment**: Проверить форму

### owner__dvb__list__filled.png

- **URL**: `/company/driver-vehicle-blocks`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Водитель+ТС
- **Page**: Водитель+ТС
- **Function**: Список блоков Водитель+ТС
- **State**: filled
- **Designer Comment**: Проверить таблицу блоков

### owner__dvb__view__runtime_block.png

- **URL**: `/company/driver-vehicle-blocks/1`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Водитель+ТС
- **Page**: Карточка блока Водитель+ТС
- **Function**: Детальная карточка блока Петров + coupling
- **State**: filled
- **Designer Comment**: Проверить отображение водителя и комплекта

### owner__logists__create__form.png

- **URL**: `/company/logists/create`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: СИСТЕМА / Пользователи
- **Page**: Создать пользователя
- **Function**: Форма создания пользователя-логиста
- **State**: empty_form
- **Designer Comment**: Проверить форму, автогенерацию пароля

### owner__logists__list__filled.png

- **URL**: `/company/logists`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: СИСТЕМА / Пользователи
- **Page**: Пользователи
- **Function**: Список пользователей компании (логистов)
- **State**: filled
- **Designer Comment**: Проверить таблицу пользователей, создание, действия

### owner__vehicle_sets__create__form.png

- **URL**: `/company/vehicle-sets/create`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Транспортные комплекты
- **Page**: Создать комплект
- **Function**: Форма создания транспортного комплекта
- **State**: empty_form
- **Designer Comment**: Проверить форму выбора primary/secondary ТЕ

### owner__vehicle_sets__edit__form.png

- **URL**: `/company/vehicle-sets/1/edit`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Транспортные комплекты
- **Page**: Редактировать комплект
- **Function**: Форма редактирования транспортного комплекта
- **State**: filled_form
- **Designer Comment**: Проверить форму

### owner__vehicle_sets__list__filled.png

- **URL**: `/company/vehicle-sets`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Транспортные комплекты
- **Page**: Транспортные комплекты
- **Function**: Список транспортных комплектов
- **State**: filled
- **Designer Comment**: Проверить таблицу комплектов

### owner__vehicle_sets__view__runtime_coupling.png

- **URL**: `/company/vehicle-sets/1`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Транспортные комплекты
- **Page**: Карточка сцепки
- **Function**: Детальная карточка сцепки А111АА777 + В222ВВ777
- **State**: filled
- **Designer Comment**: Проверить отображение primary/secondary ТЕ

### owner__vehicles__create__form.png

- **URL**: `/company/vehicles/create`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Транспортные единицы
- **Page**: Создать ТЕ
- **Function**: Форма создания транспортной единицы
- **State**: empty_form
- **Designer Comment**: Проверить форму, тип ТЕ

### owner__vehicles__edit__form.png

- **URL**: `/company/vehicles/1/edit`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Транспортные единицы
- **Page**: Редактировать ТЕ
- **Function**: Форма редактирования транспортной единицы
- **State**: filled_form
- **Designer Comment**: Проверить форму

### owner__vehicles__list__filled.png

- **URL**: `/company/vehicles`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Транспортные единицы
- **Page**: Транспортные единицы
- **Function**: Список транспортных единиц
- **State**: filled
- **Designer Comment**: Проверить таблицу ТЕ

### owner__vehicles__view__runtime_tractor.png

- **URL**: `/company/vehicles/1`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Транспортные единицы
- **Page**: Карточка тягача А111АА777
- **Function**: Детальная карточка тягача
- **State**: filled
- **Designer Comment**: Проверить данные тягача, документы, гранты

### owner__vehicles__view__runtime_trailer.png

- **URL**: `/company/vehicles/2`
- **Role**: company_owner
- **Login**: owner_test_runtime
- **Menu**: ОПЕРАЦИИ / Транспортные единицы
- **Page**: Карточка полуприцепа В222ВВ777
- **Function**: Детальная карточка полуприцепа
- **State**: filled
- **Designer Comment**: Проверить данные полуприцепа

## SUPERADMIN

### superadmin__companies__create__form.png

- **URL**: `/superadmin/companies/create`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Создать экспедитора
- **Function**: Форма создания новой компании с реквизитами
- **State**: empty_form
- **Designer Comment**: Проверить структуру формы, обязательные поля, валидацию

### superadmin__companies__list__filled.png

- **URL**: `/superadmin/companies`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Реестр компаний
- **Function**: Список всех компаний в системе с поиском и фильтром по статусу
- **State**: filled
- **Designer Comment**: Проверить таблицу: колонки, поиск, фильтр, действия, пагинацию

### superadmin__company__access_grants__list.png

- **URL**: `/superadmin/companies/9/access-grants`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Доступы компании
- **Function**: Список всех грантов доступа
- **State**: filled
- **Designer Comment**: Проверить таблицу грантов

### superadmin__company__clients__list.png

- **URL**: `/superadmin/companies/9/clients`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Клиенты компании
- **Function**: Список клиентов в компании (read-only для SUPERADMIN)
- **State**: filled_or_empty
- **Designer Comment**: Проверить таблицу клиентов

### superadmin__company__contractors__list.png

- **URL**: `/superadmin/companies/9/contractors`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Подрядчики компании
- **Function**: Список подрядчиков в компании
- **State**: filled
- **Designer Comment**: Проверить таблицу подрядчиков

### superadmin__company__create_owner__form.png

- **URL**: `/superadmin/companies/9/create-owner`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Создать Руководителя
- **Function**: Форма создания ERP-доступа для руководителя компании
- **State**: filled_or_exists
- **Designer Comment**: Если руководитель уже создан — показывает информацию о существующем

### superadmin__company__crews__list.png

- **URL**: `/superadmin/companies/9/crews`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Экипажи компании
- **Function**: Список экипажей
- **State**: filled
- **Designer Comment**: Проверить таблицу экипажей

### superadmin__company__delete__confirm.png

- **URL**: `/superadmin/companies/9/delete`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Удаление компании
- **Function**: Страница подтверждения удаления компании с danger flow
- **State**: danger_confirm
- **Designer Comment**: Проверить danger flow, подтверждение, отмену

### superadmin__company__directories__stats.png

- **URL**: `/superadmin/companies/9/directories`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Справочники компании
- **Function**: Обзор всех справочников компании со статистикой
- **State**: filled
- **Designer Comment**: Проверить навигацию по справочникам, счётчики

### superadmin__company__documents__list.png

- **URL**: `/superadmin/companies/9/documents`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Документы компании
- **Function**: Список всех документов компании со статусами
- **State**: filled
- **Designer Comment**: Проверить таблицу документов, статусы, soft delete

### superadmin__company__drivers__list.png

- **URL**: `/superadmin/companies/9/drivers`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Водители компании
- **Function**: Список водителей в компании
- **State**: filled
- **Designer Comment**: Проверить таблицу водителей

### superadmin__company__edit__form.png

- **URL**: `/superadmin/companies/9/edit`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Редактировать компанию
- **Function**: Форма редактирования реквизитов компании
- **State**: filled_form
- **Designer Comment**: Проверить форму редактирования, секции, grid

### superadmin__company__logist_create__form.png

- **URL**: `/superadmin/companies/9/users/logists/create`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Создать пользователя
- **Function**: SUPERADMIN создаёт пользователя-логиста в компании
- **State**: empty_form
- **Designer Comment**: Проверить форму создания пользователя

### superadmin__company__owner__view.png

- **URL**: `/superadmin/companies/9/owner`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Руководитель компании
- **Function**: Карточка руководителя компании с возможностью сброса пароля
- **State**: filled
- **Designer Comment**: Проверить блоки данных, управление доступом

### superadmin__company__owner_edit__form.png

- **URL**: `/superadmin/companies/9/owner/edit`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Редактировать руководителя
- **Function**: Форма редактирования данных руководителя
- **State**: filled_form
- **Designer Comment**: Проверить форму редактирования

### superadmin__company__users__list.png

- **URL**: `/superadmin/companies/9/users`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Пользователи компании
- **Function**: Список пользователей: руководитель + логисты
- **State**: filled
- **Designer Comment**: Проверить разделение руководитель/логисты, таблицы, действия

### superadmin__company__vehicles__list.png

- **URL**: `/superadmin/companies/9/vehicles`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Транспортные единицы компании
- **Function**: Список транспортных единиц
- **State**: filled
- **Designer Comment**: Проверить таблицу ТЕ

### superadmin__company__view__runtime_company.png

- **URL**: `/superadmin/companies/9`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА / Компании
- **Page**: Карточка компании
- **Function**: Детальная карточка компании: readiness checklist, пользователи, справочники, документы, danger zone
- **State**: filled
- **Designer Comment**: Command center компании — проверить иерархию блоков, счётчики, навигацию

### superadmin__dashboard__dashboard__filled.png

- **URL**: `/superadmin`
- **Role**: superadmin
- **Login**: admin@planex.local
- **Menu**: СИСТЕМА
- **Page**: SUPERADMIN Dashboard
- **Function**: Центральная панель управления суперадминистратора
- **State**: filled
- **Designer Comment**: Проверить информативность dashboard, навигацию

