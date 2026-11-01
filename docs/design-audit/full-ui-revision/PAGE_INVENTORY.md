# PAGE INVENTORY — ERP PLANEX Full UI Revision

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
Commit: 1e85dc7
Branch: master

## AUTH

### PAGE-AUTH-LOGIN
- **URL**: /login
- **Menu**: AUTH
- **Role**: all (неаутентифицированные)
- **Title**: Вход в ERP PLANEX
- **View**: app/View/pages/login_form.php
- **Layout**: app/View/layouts/auth-layout.php
- **Function**: Форма входа с полями логина и пароля. При первой загрузке (если нет аккаунтов в БД) создаёт системный аккаунт superadmin и показывает одноразовый пароль. При ошибке валидации показывает ошибки полей. При неверных учётных данных показывает authError. При дублировании логина в нескольких компаниях показывает multiLogistError.
- **POST-actions**: POST /login
- **States**:
  - `empty` — пустая форма входа
  - `dev-seed` — показан одноразовый пароль superadmin
  - `validation-error` — ошибки валидации полей (пустой логин/пароль)
  - `auth-error` — неверный логин или пароль
  - `multi-logist-error` — логин найден в нескольких компаниях, требуется помощь администратора
- **Screenshot**: auth__login__form__empty.png
- **Comment**: Проверить центрирование формы (auth-shell), отступы и размеры login-card, контрастность полей ввода, стили ошибок (form-alert alert-error, field-msg), кнопку входа (btn-primary btn-full). На мобильных проверить адаптивность auth-content.

## SUPERADMIN

### PAGE-SA-DASHBOARD
- **URL**: /superadmin
- **Menu**: СИСТЕМА → Компании
- **Role**: superadmin
- **Title**: SUPERADMIN
- **Context**: Центральная панель управления
- **View**: app/View/pages/superadmin_dashboard.php
- **Function**: Дашборд суперадминистратора. Применяет центральные миграции при загрузке. Показывает сводную информацию о системе.
- **POST-actions**: нет
- **States**:
  - `loaded` — дашборд с данными
- **Screenshot**: sa__dashboard.png
- **Comment**: Проверить отступы page-head, информационные блоки, общую компоновку.

### PAGE-SA-COMPANIES
- **URL**: /superadmin/companies
- **Menu**: СИСТЕМА → Компании (активный)
- **Role**: superadmin
- **Title**: Реестр компаний
- **Context**: (пусто)
- **View**: app/View/pages/superadmin_companies.php
- **Function**: Таблица всех компаний с поиском по названию/ИНН и фильтром по статусу. Для каждой компании показывает название, ИНН, статус, руководителя, количество пользователей (руководитель + логисты из локальной БД). Применяет локальные миграции к каждой активной компании.
- **POST-actions**: нет (только GET с query-параметрами search/status)
- **States**:
  - `loaded` — список компаний с данными
  - `empty` — нет компаний или нет результатов поиска
  - `db-error` — ошибка загрузки списка
- **Screenshot**: sa__companies__list.png
- **Comment**: Проверить таблицу (компонент table), строку поиска и фильтр статусов, бейджи статусов (status_badge), кнопку «Создать экспедитора».

### PAGE-SA-COMPANY-CREATE
- **URL**: /superadmin/companies/create
- **Menu**: СИСТЕМА → Компании
- **Role**: superadmin
- **Title**: Создать экспедитора
- **Context**: Реестр компаний
- **View**: app/View/pages/superadmin_companies_create.php
- **Function**: Форма создания новой компании-экспедитора. Поля: название, ИНН, КПП, ОГРН, юр. адрес, факт. адрес, должность руководителя, ФИО руководителя, комментарии. При успешном создании: создаётся запись в companies, создаётся локальная БД, создаётся storage-папка. Редирект на /superadmin/companies.
- **POST-actions**: POST /superadmin/companies/create
- **States**:
  - `empty` — пустая форма
  - `validation-error` — ошибки валидации (пустое название/ИНН)
  - `db-error` — ошибка создания БД или storage-папки
  - `fatal-error` — общая ошибка создания
- **Screenshot**: sa__company__create__empty.png
- **Comment**: Проверить группировку полей, обязательные поля (название, ИНН), кнопки form_actions (Сохранить/Отмена).

### PAGE-SA-COMPANY-VIEW
- **URL**: /superadmin/companies/{id} (пример: /superadmin/companies/9)
- **Menu**: СИСТЕМА → Компании
- **Role**: superadmin
- **Title**: Компания: {название}
- **Context**: Реестр компаний
- **View**: app/View/pages/superadmin_company_view.php
- **Function**: Карточка компании. Показывает: реквизиты (название, ИНН, КПП, ОГРН, адреса), должность и ФИО директора, статус, комментарии. Блок руководителя с кнопками просмотра/создания. Блок статистики: пользователи (всего/активных/заблокированных), справочники (клиенты, подрядчики, водители, ТЕ, комплекты, блоки, экипажи), документы, доступы. Блок инфраструктуры: наличие локальной БД и storage. Кнопки действий: редактировать компанию, активировать/заблокировать/архивировать/деактивировать.
- **POST-actions**: POST /superadmin/companies/{id}/activate, POST /superadmin/companies/{id}/block, POST /superadmin/companies/{id}/archive, POST /superadmin/companies/{id}/deactivate
- **States**:
  - `loaded` — карточка с данными
  - `not-found` — компания не найдена
  - `db-error` — ошибка загрузки
- **Screenshot**: sa__company__view__active.png
- **Comment**: Проверить карточку реквизитов, блоки статистики (счётчики), кнопки действий (цвета по типу действия), навигацию по подразделам (пользователи, справочники и т.д.).

### PAGE-SA-COMPANY-EDIT
- **URL**: /superadmin/companies/{id}/edit (пример: /superadmin/companies/9/edit)
- **Menu**: СИСТЕМА → Компании
- **Role**: superadmin
- **Title**: Редактировать компанию
- **Context**: Реестр компаний
- **View**: app/View/pages/superadmin_company_edit.php
- **Function**: Форма редактирования реквизитов компании. Поля: название, ИНН, КПП, ОГРН, юр. адрес, факт. адрес, должность директора, ФИО директора, статус, комментарии. Валидация: название и ИНН обязательны, ИНН уникален.
- **POST-actions**: POST /superadmin/companies/{id}/edit
- **States**:
  - `filled` — форма с текущими данными
  - `validation-error` — ошибки валидации
  - `not-found` — компания не найдена
  - `fatal-error` — ошибка загрузки/сохранения
- **Screenshot**: sa__company__edit__filled.png
- **Comment**: Проверить поля формы, выпадающий список статусов, индикацию обязательных полей.

### PAGE-SA-COMPANY-OWNER-VIEW
- **URL**: /superadmin/companies/{id}/owner (пример: /superadmin/companies/9/owner)
- **Menu**: СИСТЕМА → Компании
- **Role**: superadmin
- **Title**: Руководитель: {ФИО} или Руководитель
- **Context**: Реестр компаний
- **View**: app/View/pages/superadmin_company_owner_view.php
- **Function**: Карточка руководителя компании. Показывает: ФИО, логин, email, телефон, должность, статус, комментарии. Кнопки: редактировать, сбросить пароль (показывает временный пароль). Если руководитель не создан — предложение создать.
- **POST-actions**: POST /superadmin/companies/{id}/owner/reset-password
- **States**:
  - `loaded` — карточка с данными руководителя
  - `no-owner` — руководитель не создан
  - `company-not-found` — компания не найдена
  - `db-error` — ошибка загрузки
  - `password-reset` — показан новый временный пароль
- **Screenshot**: sa__company__owner__view.png
- **Comment**: Проверить отображение данных в read-only режиме, кнопку сброса пароля (показ временного пароля), состояние без руководителя.

### PAGE-SA-COMPANY-OWNER-EDIT
- **URL**: /superadmin/companies/{id}/owner/edit (пример: /superadmin/companies/9/owner/edit)
- **Menu**: СИСТЕМА → Компании
- **Role**: superadmin
- **Title**: Редактировать Руководителя
- **Context**: Реестр компаний
- **View**: app/View/pages/superadmin_company_owner_edit.php
- **Function**: Форма редактирования руководителя. Поля: ФИО, логин, email, телефон, должность, статус, комментарии. Валидация: ФИО и логин обязательны, логин — латиница/цифры/подчёркивание, уникален. Защита от самодеактивации и деактивации последнего активного руководителя.
- **POST-actions**: POST /superadmin/companies/{id}/owner/edit
- **States**:
  - `filled` — форма с текущими данными
  - `validation-error` — ошибки валидации
  - `no-owner` — руководитель не создан
  - `company-not-found` — компания не найдена
  - `fatal-error` — ошибка загрузки/сохранения
- **Screenshot**: sa__company__owner__edit__filled.png
- **Comment**: Проверить защитные валидации (самодеактивация, последний активный), поле статуса, сообщения об ошибках.

### PAGE-SA-COMPANY-OWNER-CREATE
- **URL**: /superadmin/companies/{id}/create-owner (пример: /superadmin/companies/9/create-owner)
- **Menu**: СИСТЕМА → Компании
- **Role**: superadmin
- **Title**: Создать Руководителя
- **Context**: Реестр компаний
- **View**: app/View/pages/superadmin_company_owner_create.php
- **Function**: Форма создания руководителя компании. Поля: ФИО, логин, пароль (автогенерация если пусто), email, телефон, должность, комментарии. При успехе показывает созданного пользователя и временный пароль. Блокирует создание если руководитель уже существует.
- **POST-actions**: POST /superadmin/companies/{id}/create-owner
- **States**:
  - `empty` — пустая форма с автогенерированным паролем
  - `owner-exists` — руководитель уже существует (форма скрыта)
  - `success` — руководитель создан, показан логин и пароль
  - `validation-error` — ошибки валидации
  - `company-not-found` — компания не найдена
- **Screenshot**: sa__company__owner__create__empty.png
- **Comment**: Проверить автогенерацию пароля, состояние успешного создания (вывод учётных данных), блокировку при существующем руководителе.

### PAGE-SA-COMPANY-USERS
- **URL**: /superadmin/companies/{id}/users (пример: /superadmin/companies/9/users)
- **Menu**: СИСТЕМА → Компании → Карточка компании
- **Role**: superadmin
- **Title**: Пользователи
- **Context**: Компания: {название}
- **View**: app/View/pages/superadmin_company_users.php
- **Function**: Список пользователей компании (руководитель + логисты из локальной БД). Для руководителя — кнопки активации/блокировки/архивации. Для логистов — ссылки на просмотр/редактирование и те же кнопки действий. Кнопка создания нового логиста.
- **POST-actions**: POST /superadmin/companies/{company_id}/users/owner/{user_id}/activate, POST .../block, POST .../archive; POST /superadmin/companies/{company_id}/users/logists/{user_id}/activate, POST .../block, POST .../archive
- **States**:
  - `loaded` — список пользователей
  - `empty` — нет пользователей
  - `db-error` — ошибка подключения к локальной БД
- **Screenshot**: sa__company__users__list.png
- **Comment**: Проверить разделение на руководителя и логистов, бейджи статусов, кнопки действий, состояние без пользователей.

### PAGE-SA-COMPANY-LOGIST-CREATE
- **URL**: /superadmin/companies/{id}/users/logists/create (пример: /superadmin/companies/9/users/logists/create)
- **Menu**: СИСТЕМА → Компании → Пользователи
- **Role**: superadmin
- **Title**: Создать пользователя
- **Context**: Пользователи — Компания: {название}
- **View**: app/View/pages/superadmin_company_logist_create.php
- **Function**: Создание нового логиста в локальной БД компании. Поля: ФИО, логин, пароль (автогенерация), email, телефон, роль, должность. При успехе показывает созданного пользователя и временный пароль.
- **POST-actions**: POST /superadmin/companies/{id}/users/logists/create
- **States**:
  - `empty` — пустая форма
  - `success` — пользователь создан, показан пароль
  - `validation-error` — ошибки валидации
  - `db-error` — ошибка БД
  - `company-not-found` — компания не найдена
- **Screenshot**: sa__company__logist__create__empty.png
- **Comment**: Проверить автогенерацию пароля, вывод учётных данных после создания.

### PAGE-SA-COMPANY-LOGIST-VIEW
- **URL**: /superadmin/companies/{company_id}/users/logists/{user_id} (пример: /superadmin/companies/9/users/logists/1)
- **Menu**: СИСТЕМА → Компании → Пользователи
- **Role**: superadmin
- **Title**: Пользователь: {ФИО}
- **Context**: Пользователи — Компания: {название}
- **View**: app/View/pages/superadmin_company_logist_view.php
- **Function**: Карточка логиста (read-only). Показывает ФИО, логин, email, телефон, роль, должность, статус, статистику созданных записей. Кнопки: редактировать, сбросить пароль, активировать/заблокировать/архивировать.
- **POST-actions**: POST /superadmin/companies/{company_id}/users/logists/{user_id}/reset-password, POST .../activate, POST .../block, POST .../archive
- **States**:
  - `loaded` — карточка с данными
  - `not-found` — пользователь не найден
  - `db-error` — ошибка загрузки
  - `password-reset` — показан новый пароль
- **Screenshot**: sa__company__logist__view.png
- **Comment**: Проверить read-only карточку, кнопки действий, сброс пароля.

### PAGE-SA-COMPANY-LOGIST-EDIT
- **URL**: /superadmin/companies/{company_id}/users/logists/{user_id}/edit (пример: /superadmin/companies/9/users/logists/1/edit)
- **Menu**: СИСТЕМА → Компании → Пользователи
- **Role**: superadmin
- **Title**: Редактировать пользователя
- **Context**: Пользователи — Компания: {название}
- **View**: app/View/pages/superadmin_company_logist_edit.php
- **Function**: Форма редактирования логиста. Поля: ФИО, логин, email, телефон, роль, должность. Защита от самодеактивации и деактивации последнего активного пользователя.
- **POST-actions**: POST /superadmin/companies/{company_id}/users/logists/{user_id}/edit
- **States**:
  - `filled` — форма с текущими данными
  - `validation-error` — ошибки валидации
  - `not-found` — пользователь не найден
  - `fatal-error` — ошибка сохранения
- **Screenshot**: sa__company__logist__edit__filled.png
- **Comment**: Проверить защитные валидации, поле должности (position).

### PAGE-SA-COMPANY-DIRECTORIES
- **URL**: /superadmin/companies/{id}/directories (пример: /superadmin/companies/9/directories)
- **Menu**: СИСТЕМА → Компании → Карточка компании
- **Role**: superadmin
- **Title**: Справочники
- **Context**: Компания: {название}
- **View**: app/View/pages/superadmin_company_directories.php
- **Function**: Обзорная страница справочников компании. Показывает статистику по каждому справочнику (всего/активных/архивных): клиенты, подрядчики, водители, транспортные единицы, транспортные комплекты, блоки Водитель+ТС, экипажи. Ссылки на каждый справочник.
- **POST-actions**: нет
- **States**:
  - `loaded` — сводка справочников
  - `not-found` — компания не найдена
  - `db-error` — ошибка
- **Screenshot**: sa__company__directories__overview.png
- **Comment**: Проверить сетку карточек справочников, счётчики, навигационные ссылки.

### PAGE-SA-COMPANY-CLIENTS
- **URL**: /superadmin/companies/{id}/clients (пример: /superadmin/companies/9/clients)
- **Menu**: СИСТЕМА → Компании → Справочники
- **Role**: superadmin
- **Title**: Клиенты
- **Context**: Компания: {название}
- **View**: app/View/pages/superadmin_company_clients.php
- **Function**: Read-only список клиентов компании из локальной БД.
- **POST-actions**: нет
- **States**:
  - `loaded` — список клиентов
  - `empty` — нет клиентов
  - `db-error` — ошибка загрузки
- **Screenshot**: sa__company__clients__list.png
- **Comment**: Проверить read-only таблицу, состояние пустого списка.

### PAGE-SA-COMPANY-CONTRACTORS
- **URL**: /superadmin/companies/{id}/contractors (пример: /superadmin/companies/9/contractors)
- **Menu**: СИСТЕМА → Компании → Справочники
- **Role**: superadmin
- **Title**: Подрядчики
- **Context**: Компания: {название}
- **View**: app/View/pages/superadmin_company_contractors.php
- **Function**: Read-only список подрядчиков компании из локальной БД.
- **POST-actions**: нет
- **States**:
  - `loaded` — список подрядчиков
  - `empty` — нет подрядчиков
  - `db-error` — ошибка загрузки
- **Screenshot**: sa__company__contractors__list.png
- **Comment**: Проверить read-only таблицу.

### PAGE-SA-COMPANY-DRIVERS
- **URL**: /superadmin/companies/{id}/drivers (пример: /superadmin/companies/9/drivers)
- **Menu**: СИСТЕМА → Компании → Справочники
- **Role**: superadmin
- **Title**: Водители
- **Context**: Компания: {название}
- **View**: app/View/pages/superadmin_company_drivers.php
- **Function**: Read-only список водителей компании.
- **POST-actions**: нет
- **States**:
  - `loaded` — список водителей
  - `empty` — нет водителей
- **Screenshot**: sa__company__drivers__list.png
- **Comment**: Проверить таблицу.

### PAGE-SA-COMPANY-VEHICLES
- **URL**: /superadmin/companies/{id}/vehicles (пример: /superadmin/companies/9/vehicles)
- **Menu**: СИСТЕМА → Компании → Справочники
- **Role**: superadmin
- **Title**: Транспортные единицы
- **Context**: Компания: {название}
- **View**: app/View/pages/superadmin_company_vehicles.php
- **Function**: Read-only список транспортных единиц компании (тягачи и полуприцепы).
- **POST-actions**: нет
- **States**:
  - `loaded` — список ТЕ
  - `empty` — нет ТЕ
- **Screenshot**: sa__company__vehicles__list.png
- **Comment**: Проверить различие типов ТЕ в таблице.

### PAGE-SA-COMPANY-CREWS
- **URL**: /superadmin/companies/{id}/crews (пример: /superadmin/companies/9/crews)
- **Menu**: СИСТЕМА → Компании → Справочники
- **Role**: superadmin
- **Title**: Экипажи
- **Context**: Компания: {название}
- **View**: app/View/pages/superadmin_company_crews.php
- **Function**: Read-only список экипажей компании.
- **POST-actions**: нет
- **States**:
  - `loaded` — список экипажей
  - `empty` — нет экипажей
- **Screenshot**: sa__company__crews__list.png
- **Comment**: Проверить таблицу.

### PAGE-SA-COMPANY-DOCUMENTS
- **URL**: /superadmin/companies/{id}/documents (пример: /superadmin/companies/9/documents)
- **Menu**: СИСТЕМА → Компании → Карточка компании
- **Role**: superadmin
- **Title**: Документы
- **Context**: Компания: {название}
- **View**: app/View/pages/superadmin_company_documents.php
- **Function**: Read-only список документов компании с возможностью скачивания.
- **POST-actions**: нет (скачивание через GET /superadmin/companies/{company_id}/documents/{document_id}/download)
- **States**:
  - `loaded` — список документов
  - `empty` — нет документов
- **Screenshot**: sa__company__documents__list.png
- **Comment**: Проверить таблицу документов, ссылки на скачивание.

### PAGE-SA-COMPANY-ACCESS-GRANTS
- **URL**: /superadmin/companies/{id}/access-grants (пример: /superadmin/companies/9/access-grants)
- **Menu**: СИСТЕМА → Компании → Карточка компании
- **Role**: superadmin
- **Title**: Доступы
- **Context**: Компания: {название}
- **View**: app/View/pages/superadmin_company_access_grants.php
- **Function**: Список всех грантов доступа (entity_access_grants) в компании. Каждый грант показывает: кому выдан, к какой сущности, уровень доступа. Кнопка отзыва гранта.
- **POST-actions**: POST /superadmin/companies/{id}/access-grants/{grant_id}/revoke
- **States**:
  - `loaded` — список грантов
  - `empty` — нет грантов
- **Screenshot**: sa__company__access_grants__list.png
- **Comment**: Проверить таблицу грантов, бейджи уровней доступа, кнопку отзыва.

### PAGE-SA-COMPANY-DELETE
- **URL**: /superadmin/companies/{id}/delete (пример: /superadmin/companies/9/delete)
- **Menu**: СИСТЕМА → Компании
- **Role**: superadmin
- **Title**: Удаление компании
- **Context**: Реестр компаний
- **View**: app/View/pages/superadmin_company_delete.php
- **Function**: Страница подтверждения удаления компании. Показывает информацию о компании и предупреждение о необратимости. Кнопка подтверждения удаления.
- **POST-actions**: POST /superadmin/companies/{id}/delete
- **States**:
  - `confirm` — страница подтверждения
  - `not-found` — компания не найдена
- **Screenshot**: sa__company__delete__confirm.png
- **Comment**: Проверить стиль предупреждения (опасное действие), чёткость текста о необратимости.

## COMPANY OWNER

### PAGE-CO-DASHBOARD
- **URL**: /company/dashboard
- **Menu**: (нет отдельного пункта, заглушка)
- **Role**: company_owner, logist
- **Title**: Панель управления
- **Context**: Компания: {название}
- **View**: app/View/pages/company_dashboard.php
- **Function**: Дашборд компании. Показывает название компании, сообщение «Система в разработке» и список доступных разделов. При ошибке загрузки — предупреждение.
- **POST-actions**: нет
- **States**:
  - `loaded` — дашборд с названием компании и списком разделов
  - `not-found` — компания не найдена
- **Screenshot**: co__dashboard.png
- **Comment**: Проверить отступы, сообщение о разработке, список разделов.

### PAGE-CO-LOGISTS
- **URL**: /company/logists
- **Menu**: СИСТЕМА → Пользователи
- **Role**: company_owner
- **Title**: Пользователи
- **Context**: Пользователи — Компания: {название}
- **View**: app/View/pages/company_logists.php
- **Function**: Список пользователей компании (логистов). Для company_owner — видит всех. Для logist — видит своих созданных и выданных по грантам. Таблица: ФИО, логин, email, телефон, роль, статус, дата создания. Кнопка создания нового пользователя (только для company_owner).
- **POST-actions**: нет на этой странице (переход на create/edit)
- **States**:
  - `loaded` — список пользователей
  - `empty` — нет пользователей
  - `db-error` — ошибка подключения к локальной БД
  - `company-inactive` — компания не активна
- **Screenshot**: co__logists__list.png
- **Comment**: Проверить таблицу, кнопку «Создать пользователя» (видна только owner), состояние пустого списка, сообщение об ошибке БД.

### PAGE-CO-LOGISTS-CREATE
- **URL**: /company/logists/create
- **Menu**: СИСТЕМА → Пользователи
- **Role**: company_owner
- **Title**: Создать пользователя
- **Context**: Пользователи — Компания: {название}
- **View**: app/View/pages/company_logists_create.php
- **Function**: Форма создания пользователя (логиста). Поля: ФИО, логин, пароль (автогенерация если пусто), email, телефон, роль (logist). При успехе показывает созданного пользователя и временный пароль. Записывает created_by_user_id и created_by_role.
- **POST-actions**: POST /company/logists/create
- **States**:
  - `empty` — пустая форма с автогенерированным паролем
  - `success` — пользователь создан, показан логин и пароль
  - `validation-error` — ошибки валидации
  - `company-inactive` — создание недоступно
  - `fatal-error` — ошибка создания
- **Screenshot**: co__logists__create__empty.png
- **Comment**: Проверить автогенерацию пароля, состояние успеха, валидацию роли (только logist).

### PAGE-CO-LOGIST-VIEW
- **URL**: /company/logists/{id} (пример: /company/logists/2)
- **Menu**: СИСТЕМА → Пользователи
- **Role**: company_owner
- **Title**: Пользователь: {ФИО}
- **Context**: Пользователи — Компания: {название}
- **View**: app/View/pages/company_logist_view.php
- **Function**: Карточка пользователя (read-only). Показывает ФИО, логин, email, телефон, роль, должность, статус. Кнопки: редактировать, сбросить пароль, архивировать.
- **POST-actions**: POST /company/logists/{id}/reset-password, POST /company/logists/{id}/archive
- **States**:
  - `loaded` — карточка с данными
  - `not-found` — пользователь не найден
  - `db-error` — ошибка загрузки
  - `password-reset` — показан новый пароль
- **Screenshot**: co__logist__view.png
- **Comment**: Проверить read-only карточку, кнопку сброса пароля, кнопку архивации.

### PAGE-CO-LOGIST-EDIT
- **URL**: /company/logists/{id}/edit (пример: /company/logists/2/edit)
- **Menu**: СИСТЕМА → Пользователи
- **Role**: company_owner
- **Title**: Редактировать пользователя
- **Context**: Пользователи — Компания: {название}
- **View**: app/View/pages/company_logist_edit.php
- **Function**: Форма редактирования пользователя. Поля: ФИО, логин, email, телефон, роль, должность. Валидация роли: только logist.
- **POST-actions**: POST /company/logists/{id}/edit
- **States**:
  - `filled` — форма с данными
  - `validation-error` — ошибки валидации
  - `not-found` — пользователь не найден
  - `company-inactive` — редактирование недоступно
- **Screenshot**: co__logist__edit__filled.png
- **Comment**: Проверить ограничение роли, поля формы.

### PAGE-CO-CONTRACTORS
- **URL**: /company/contractors
- **Menu**: ОПЕРАЦИИ → Подрядчики
- **Role**: company_owner, logist
- **Title**: Подрядчики
- **Context**: Подрядчики — Компания: {название}
- **View**: app/View/pages/company_contractors.php
- **Function**: Таблица подрядчиков с поиском/фильтрацией. Для company_owner — все записи. Для logist — свои + по грантам. Колонки: название, ИНН, статус, контакты. Кнопка создания (только для company_owner и logist-владельца).
- **POST-actions**: нет (переход на create/view)
- **States**:
  - `loaded` — список подрядчиков
  - `empty` — нет подрядчиков
  - `db-error` — ошибка загрузки
- **Screenshot**: co__contractors__list.png
- **Comment**: Проверить таблицу, поиск, кнопку создания, состояние пустого списка.

### PAGE-CO-CONTRACTORS-CREATE
- **URL**: /company/contractors/create
- **Menu**: ОПЕРАЦИИ → Подрядчики
- **Role**: company_owner, logist (владелец)
- **Title**: Создать подрядчика
- **Context**: Подрядчики — Компания: {название}
- **View**: app/View/pages/company_contractors_create.php
- **Function**: Форма создания подрядчика. Поля: название, ИНН, КПП, ОГРН, юр. адрес, факт. адрес, контактное лицо, телефон, email. Валидация: название и ИНН обязательны, ИНН уникален.
- **POST-actions**: POST /company/contractors/create
- **States**:
  - `empty` — пустая форма
  - `validation-error` — ошибки валидации
  - `fatal-error` — ошибка создания
- **Screenshot**: co__contractors__create__empty.png
- **Comment**: Проверить форму, обязательные поля, валидацию ИНН.

### PAGE-CO-CONTRACTOR-VIEW
- **URL**: /company/contractors/{id} (пример: /company/contractors/1)
- **Menu**: ОПЕРАЦИИ → Подрядчики
- **Role**: company_owner, logist (владелец или по гранту)
- **Title**: Подрядчик: {название}
- **Context**: Подрядчики — Компания: {название}
- **View**: app/View/pages/company_contractor_view.php
- **Function**: Карточка подрядчика. Блоки: реквизиты, контакты (таблица с кнопками добавления/редактирования/удаления/назначения основным/email для документов), налоговая история (таблица с кнопкой добавления), документы (привязанные к подрядчику), гранты доступа (кто из логистов имеет доступ, с кнопками выдачи/отзыва). Кнопки: редактировать, архивировать.
- **POST-actions**: POST /company/contractors/{id}/archive; POST .../contacts/create, POST .../contacts/{contact_id}/edit, POST .../contacts/{contact_id}/delete, POST .../contacts/{contact_id}/set-primary, POST .../contacts/{contact_id}/set-document-email; POST .../tax-history/create
- **States**:
  - `loaded` — карточка с данными
  - `not-found` — подрядчик не найден
  - `forbidden` — нет доступа (для logist без гранта)
- **Screenshot**: co__contractor__view.png
- **Comment**: Ключевая страница дизайн-аудита. Проверить все блоки: реквизиты, контакты (таблица с inline-действиями), налоговая история, документы, гранты. Бейджи основного контакта и email для документов. Кнопки выдачи/отзыва грантов (видны только owner).

### PAGE-CO-CONTRACTOR-EDIT
- **URL**: /company/contractors/{id}/edit (пример: /company/contractors/1/edit)
- **Menu**: ОПЕРАЦИИ → Подрядчики
- **Role**: company_owner, logist (владелец)
- **Title**: Редактировать подрядчика
- **Context**: Подрядчики — Компания: {название}
- **View**: app/View/pages/company_contractor_edit.php
- **Function**: Форма редактирования подрядчика. Поля: название, ИНН, КПП, ОГРН, юр. адрес, факт. адрес. Валидация: название и ИНН обязательны, ИНН уникален.
- **POST-actions**: POST /company/contractors/{id}/edit
- **States**:
  - `filled` — форма с данными
  - `validation-error` — ошибки валидации
  - `not-found` — подрядчик не найден
- **Screenshot**: co__contractor__edit__filled.png
- **Comment**: Проверить форму.

### PAGE-CO-DRIVERS
- **URL**: /company/drivers
- **Menu**: ОПЕРАЦИИ → Водители
- **Role**: company_owner, logist
- **Title**: Водители
- **Context**: Водители — Компания: {название}
- **View**: app/View/pages/company_drivers.php
- **Function**: Таблица водителей с поиском. Колонки: ФИО, телефон, статус. Кнопка создания.
- **POST-actions**: нет
- **States**:
  - `loaded` — список водителей
  - `empty` — нет водителей
- **Screenshot**: co__drivers__list.png
- **Comment**: Проверить таблицу, поиск.

### PAGE-CO-DRIVERS-CREATE
- **URL**: /company/drivers/create
- **Menu**: ОПЕРАЦИИ → Водители
- **Role**: company_owner, logist (владелец)
- **Title**: Создать водителя
- **Context**: Водители — Компания: {название}
- **View**: app/View/pages/company_drivers_create.php
- **Function**: Форма создания водителя. Поля: ФИО, телефон, email, паспортные данные, адрес, комментарий. Можно добавить несколько телефонов.
- **POST-actions**: POST /company/drivers/create
- **States**:
  - `empty` — пустая форма
  - `validation-error` — ошибки валидации
- **Screenshot**: co__drivers__create__empty.png
- **Comment**: Проверить форму с несколькими телефонами.

### PAGE-CO-DRIVER-VIEW
- **URL**: /company/drivers/{id} (пример: /company/drivers/1)
- **Menu**: ОПЕРАЦИИ → Водители
- **Role**: company_owner, logist (владелец или по гранту)
- **Title**: Водитель: {ФИО}
- **Context**: Водители — Компания: {название}
- **View**: app/View/pages/company_driver_view.php
- **Function**: Карточка водителя. Блоки: личные данные, телефоны (таблица с inline-действиями: добавить/редактировать/удалить/назначить основным), документы, блокировки (история driver_vehicle_blocks), гранты доступа. Кнопки: редактировать, архивировать.
- **POST-actions**: POST /company/drivers/{id}/archive; POST .../phones/create, POST .../phones/{phone_id}/edit, POST .../phones/{phone_id}/delete, POST .../phones/{phone_id}/set-main
- **States**:
  - `loaded` — карточка с данными
  - `not-found` — водитель не найден
  - `forbidden` — нет доступа
- **Screenshot**: co__driver__view.png
- **Comment**: Проверить блок телефонов (inline CRUD), блок блокировок, гранты.

### PAGE-CO-DRIVER-EDIT
- **URL**: /company/drivers/{id}/edit (пример: /company/drivers/1/edit)
- **Menu**: ОПЕРАЦИИ → Водители
- **Role**: company_owner, logist (владелец)
- **Title**: Редактировать водителя
- **Context**: Водители — Компания: {название}
- **View**: app/View/pages/company_driver_edit.php
- **Function**: Форма редактирования водителя. Поля те же, что при создании.
- **POST-actions**: POST /company/drivers/{id}/edit
- **States**:
  - `filled` — форма с данными
  - `validation-error` — ошибки валидации
- **Screenshot**: co__driver__edit__filled.png
- **Comment**: Проверить форму.

### PAGE-CO-VEHICLES
- **URL**: /company/vehicles
- **Menu**: ОПЕРАЦИИ → Транспортные единицы
- **Role**: company_owner, logist
- **Title**: Транспортные единицы
- **Context**: Транспортные единицы — Компания: {название}
- **View**: app/View/pages/company_vehicles.php
- **Function**: Таблица транспортных единиц (тягачи и полуприцепы). Колонки: госномер, тип, марка/модель, статус. Кнопка создания.
- **POST-actions**: нет
- **States**:
  - `loaded` — список ТЕ
  - `empty` — нет ТЕ
- **Screenshot**: co__vehicles__list.png
- **Comment**: Проверить различие типов ТЕ в таблице.

### PAGE-CO-VEHICLES-CREATE
- **URL**: /company/vehicles/create
- **Menu**: ОПЕРАЦИИ → Транспортные единицы
- **Role**: company_owner, logist (владелец)
- **Title**: Создать ТЕ
- **Context**: Транспортные единицы — Компания: {название}
- **View**: app/View/pages/company_vehicles_create.php
- **Function**: Форма создания транспортной единицы. Поля: тип (тягач/полуприцеп), госномер, марка, модель, VIN, год выпуска, цвет, масса, комментарий. Госномер обязателен. Валидация уникальности госномера.
- **POST-actions**: POST /company/vehicles/create
- **States**:
  - `empty` — пустая форма
  - `validation-error` — ошибки валидации
- **Screenshot**: co__vehicles__create__empty.png
- **Comment**: Проверить селект типа ТЕ, валидацию госномера.

### PAGE-CO-VEHICLE-VIEW
- **URL**: /company/vehicles/{id} (пример: /company/vehicles/1 — тягач А111АА777, /company/vehicles/2 — полуприцеп В222ВВ777)
- **Menu**: ОПЕРАЦИИ → Транспортные единицы
- **Role**: company_owner, logist (владелец или по гранту)
- **Title**: Транспортная единица: {госномер}
- **Context**: Транспортные единицы — Компания: {название}
- **View**: app/View/pages/company_vehicle_view.php
- **Function**: Карточка транспортной единицы. Блоки: основные данные (тип, госномер, марка, модель, VIN, год, цвет, масса), документы, комплекты (в каких vehicle_sets участвует), блоки (driver_vehicle_blocks), гранты доступа. Кнопки: редактировать, архивировать.
- **POST-actions**: POST /company/vehicles/{id}/archive
- **States**:
  - `loaded` — карточка (тягач)
  - `loaded` — карточка (полуприцеп) — отличается типом и связанными комплектами
  - `not-found` — ТЕ не найдена
- **Screenshot**: co__vehicle__view__tractor.png, co__vehicle__view__trailer.png
- **Comment**: Два скриншота — тягач и полуприцеп. Проверить различие в отображении типа, связанные комплекты и блоки.

### PAGE-CO-VEHICLE-EDIT
- **URL**: /company/vehicles/{id}/edit (пример: /company/vehicles/1/edit)
- **Menu**: ОПЕРАЦИИ → Транспортные единицы
- **Role**: company_owner, logist (владелец)
- **Title**: Редактировать ТЕ
- **Context**: Транспортные единицы — Компания: {название}
- **View**: app/View/pages/company_vehicle_edit.php
- **Function**: Форма редактирования транспортной единицы.
- **POST-actions**: POST /company/vehicles/{id}/edit
- **States**:
  - `filled` — форма с данными
  - `validation-error` — ошибки
- **Screenshot**: co__vehicle__edit__filled.png
- **Comment**: Проверить форму.

### PAGE-CO-VEHICLE-SETS
- **URL**: /company/vehicle-sets
- **Menu**: ОПЕРАЦИИ → Транспортные комплекты
- **Role**: company_owner, logist
- **Title**: Транспортные комплекты
- **Context**: Транспортные комплекты — Компания: {название}
- **View**: app/View/pages/company_vehicle_sets.php
- **Function**: Таблица транспортных комплектов (сцепок). Колонки: название, тягач, полуприцеп(ы), статус.
- **POST-actions**: нет
- **States**:
  - `loaded` — список комплектов
  - `empty` — нет комплектов
- **Screenshot**: co__vehicle_sets__list.png
- **Comment**: Проверить таблицу.

### PAGE-CO-VEHICLE-SETS-CREATE
- **URL**: /company/vehicle-sets/create
- **Menu**: ОПЕРАЦИИ → Транспортные комплекты
- **Role**: company_owner, logist (владелец)
- **Title**: Создать комплект
- **Context**: Транспортные комплекты — Компания: {название}
- **View**: app/View/pages/company_vehicle_sets_create.php
- **Function**: Форма создания транспортного комплекта. Выбор тягача (vehicle_units type=tractor), выбор полуприцепов (type=trailer), название.
- **POST-actions**: POST /company/vehicle-sets/create
- **States**:
  - `empty` — пустая форма с выпадающими списками ТЕ
  - `validation-error` — ошибки
- **Screenshot**: co__vehicle_sets__create__empty.png
- **Comment**: Проверить селекты тягача и полуприцепов.

### PAGE-CO-VEHICLE-SET-VIEW
- **URL**: /company/vehicle-sets/{id} (пример: /company/vehicle-sets/1)
- **Menu**: ОПЕРАЦИИ → Транспортные комплекты
- **Role**: company_owner, logist (владелец или по гранту)
- **Title**: Комплект: {название}
- **Context**: Транспортные комплекты — Компания: {название}
- **View**: app/View/pages/company_vehicle_set_view.php
- **Function**: Карточка транспортного комплекта. Показывает тягач, полуприцепы, гранты доступа. Кнопки: редактировать, архивировать.
- **POST-actions**: POST /company/vehicle-sets/{id}/archive
- **States**:
  - `loaded` — карточка комплекта
  - `not-found` — не найден
- **Screenshot**: co__vehicle_set__view.png
- **Comment**: Проверить отображение состава комплекта (тягач + прицепы).

### PAGE-CO-VEHICLE-SET-EDIT
- **URL**: /company/vehicle-sets/{id}/edit (пример: /company/vehicle-sets/1/edit)
- **Menu**: ОПЕРАЦИИ → Транспортные комплекты
- **Role**: company_owner, logist (владелец)
- **Title**: Редактировать комплект
- **Context**: Транспортные комплекты — Компания: {название}
- **View**: app/View/pages/company_vehicle_set_edit.php
- **Function**: Форма редактирования комплекта.
- **POST-actions**: POST /company/vehicle-sets/{id}/edit
- **States**:
  - `filled` — форма с данными
  - `validation-error` — ошибки
- **Screenshot**: co__vehicle_set__edit__filled.png
- **Comment**: Проверить форму.

### PAGE-CO-DRIVER-VEHICLE-BLOCKS
- **URL**: /company/driver-vehicle-blocks
- **Menu**: ОПЕРАЦИИ → Водитель+ТС
- **Role**: company_owner, logist
- **Title**: Водитель+ТС
- **Context**: Водитель+ТС — Компания: {название}
- **View**: app/View/pages/company_driver_vehicle_blocks.php
- **Function**: Таблица блоков Водитель+ТС. Колонки: водитель, транспортное средство, статус, даты.
- **POST-actions**: нет
- **States**:
  - `loaded` — список блоков
  - `empty` — нет блоков
- **Screenshot**: co__dvb__list.png
- **Comment**: Проверить таблицу.

### PAGE-CO-DRIVER-VEHICLE-BLOCKS-CREATE
- **URL**: /company/driver-vehicle-blocks/create
- **Menu**: ОПЕРАЦИИ → Водитель+ТС
- **Role**: company_owner, logist (владелец)
- **Title**: Создать блок
- **Context**: Водитель+ТС — Компания: {название}
- **View**: app/View/pages/company_driver_vehicle_blocks_create.php
- **Function**: Форма создания блока. Выбор водителя, выбор ТЕ (из активных vehicle_units), дата начала, дата окончания, комментарий.
- **POST-actions**: POST /company/driver-vehicle-blocks/create
- **States**:
  - `empty` — пустая форма
  - `validation-error` — ошибки
- **Screenshot**: co__dvb__create__empty.png
- **Comment**: Проверить селекты водителя и ТЕ.

### PAGE-CO-DRIVER-VEHICLE-BLOCK-VIEW
- **URL**: /company/driver-vehicle-blocks/{id} (пример: /company/driver-vehicle-blocks/1)
- **Menu**: ОПЕРАЦИИ → Водитель+ТС
- **Role**: company_owner, logist (владелец или по гранту)
- **Title**: Блок: {водитель} + {ТС}
- **Context**: Водитель+ТС — Компания: {название}
- **View**: app/View/pages/company_driver_vehicle_block_view.php
- **Function**: Карточка блока. Показывает водителя, ТС, даты, статус, гранты доступа. Кнопки: редактировать, архивировать.
- **POST-actions**: POST /company/driver-vehicle-blocks/{id}/archive
- **States**:
  - `loaded` — карточка
  - `not-found` — не найден
- **Screenshot**: co__dvb__view.png
- **Comment**: Проверить карточку.

### PAGE-CO-DRIVER-VEHICLE-BLOCK-EDIT
- **URL**: /company/driver-vehicle-blocks/{id}/edit (пример: /company/driver-vehicle-blocks/1/edit)
- **Menu**: ОПЕРАЦИИ → Водитель+ТС
- **Role**: company_owner, logist (владелец)
- **Title**: Редактировать блок
- **Context**: Водитель+ТС — Компания: {название}
- **View**: app/View/pages/company_driver_vehicle_block_edit.php
- **Function**: Форма редактирования блока.
- **POST-actions**: POST /company/driver-vehicle-blocks/{id}/edit
- **States**:
  - `filled` — форма
  - `validation-error` — ошибки
- **Screenshot**: co__dvb__edit__filled.png
- **Comment**: Проверить форму.

### PAGE-CO-CREWS
- **URL**: /company/crews
- **Menu**: ОПЕРАЦИИ → Экипажи
- **Role**: company_owner, logist
- **Title**: Экипажи
- **Context**: Экипажи — Компания: {название}
- **View**: app/View/pages/company_crews.php
- **Function**: Таблица экипажей. Колонки: название, водители (состав), статус. Кнопка создания.
- **POST-actions**: нет
- **States**:
  - `loaded` — список экипажей
  - `empty` — нет экипажей
- **Screenshot**: co__crews__list.png
- **Comment**: Проверить таблицу.

### PAGE-CO-CREWS-CREATE
- **URL**: /company/crews/create
- **Menu**: ОПЕРАЦИИ → Экипажи
- **Role**: company_owner, logist (владелец)
- **Title**: Создать экипаж
- **Context**: Экипажи — Компания: {название}
- **View**: app/View/pages/company_crews_create.php
- **Function**: Форма создания экипажа. Название, выбор водителей (мультивыбор).
- **POST-actions**: POST /company/crews/create
- **States**:
  - `empty` — пустая форма
  - `validation-error` — ошибки
- **Screenshot**: co__crews__create__empty.png
- **Comment**: Проверить мультивыбор водителей.

### PAGE-CO-CREW-VIEW
- **URL**: /company/crews/{id} (пример: /company/crews/1)
- **Menu**: ОПЕРАЦИИ → Экипажи
- **Role**: company_owner, logist (владелец или по гранту)
- **Title**: Экипаж: {название}
- **Context**: Экипажи — Компания: {название}
- **View**: app/View/pages/company_crew_view.php
- **Function**: Карточка экипажа. Показывает название, состав (водители), гранты доступа. Кнопки: редактировать, архивировать.
- **POST-actions**: POST /company/crews/{id}/archive
- **States**:
  - `loaded` — карточка
  - `not-found` — не найден
- **Screenshot**: co__crew__view.png
- **Comment**: Проверить отображение состава экипажа.

### PAGE-CO-CREW-EDIT
- **URL**: /company/crews/{id}/edit (пример: /company/crews/1/edit)
- **Menu**: ОПЕРАЦИИ → Экипажи
- **Role**: company_owner, logist (владелец)
- **Title**: Редактировать экипаж
- **Context**: Экипажи — Компания: {название}
- **View**: app/View/pages/company_crew_edit.php
- **Function**: Форма редактирования экипажа.
- **POST-actions**: POST /company/crews/{id}/edit
- **States**:
  - `filled` — форма
  - `validation-error` — ошибки
- **Screenshot**: co__crew__edit__filled.png
- **Comment**: Проверить форму.

### PAGE-CO-DOCUMENTS
- **URL**: /company/documents
- **Menu**: (нет в боковом меню, переход из карточек сущностей)
- **Role**: company_owner, logist
- **Title**: Документы
- **Context**: Документы — Компания: {название}
- **View**: app/View/pages/company_documents.php
- **Function**: Таблица всех документов компании. Колонки: название файла, тип, привязка к сущности, размер, дата загрузки. Фильтр по типу сущности. Кнопки: скачать, заменить, удалить. Кнопка загрузки нового документа.
- **POST-actions**: POST /company/documents/delete, POST /company/documents/replace
- **States**:
  - `loaded` — список документов
  - `empty` — нет документов
- **Screenshot**: co__documents__list.png
- **Comment**: Проверить таблицу, фильтр по типу сущности, inline-действия (скачать/заменить/удалить).

### PAGE-CO-DOCUMENTS-UPLOAD
- **URL**: /company/documents/upload
- **Menu**: (переход из страницы документов)
- **Role**: company_owner, logist
- **Title**: Загрузить документ
- **Context**: Документы — Компания: {название}
- **View**: app/View/pages/company_documents_upload.php
- **Function**: Форма загрузки документа. Поля: файл (file input), тип документа, привязка к сущности (тип + выбор конкретной записи), комментарий.
- **POST-actions**: POST /company/documents/upload
- **States**:
  - `empty` — пустая форма
  - `validation-error` — ошибки (файл не выбран, недопустимый формат)
  - `success` — документ загружен (редирект или сообщение)
- **Screenshot**: co__documents__upload__empty.png
- **Comment**: Проверить file input, каскадные селекты (тип сущности → конкретная запись).

### PAGE-CO-CLIENTS
- **URL**: /company/clients
- **Menu**: ОПЕРАЦИИ → Клиенты
- **Role**: company_owner, logist
- **Title**: Клиенты
- **Context**: Клиенты — Компания: {название}
- **View**: app/View/pages/company_clients.php
- **Function**: Таблица клиентов. Колонки: название, ИНН, контакты, статус. Кнопка создания.
- **POST-actions**: нет
- **States**:
  - `loaded` — список клиентов
  - `empty` — нет клиентов
- **Screenshot**: co__clients__list.png
- **Comment**: Проверить таблицу.

### PAGE-CO-CLIENTS-CREATE
- **URL**: /company/clients/create
- **Menu**: ОПЕРАЦИИ → Клиенты
- **Role**: company_owner, logist (владелец)
- **Title**: Создать клиента
- **Context**: Клиенты — Компания: {название}
- **View**: app/View/pages/company_clients_create.php
- **Function**: Форма создания клиента. Поля: название, ИНН, КПП, ОГРН, юр. адрес, факт. адрес, контактное лицо, телефон, email.
- **POST-actions**: POST /company/clients/create
- **States**:
  - `empty` — пустая форма
  - `validation-error` — ошибки
- **Screenshot**: co__clients__create__empty.png
- **Comment**: Проверить форму.

### PAGE-CO-CLIENT-VIEW
- **URL**: /company/clients/{id} (пример: /company/clients/1)
- **Menu**: ОПЕРАЦИИ → Клиенты
- **Role**: company_owner, logist (владелец или по гранту)
- **Title**: Клиент: {название}
- **Context**: Клиенты — Компания: {название}
- **View**: app/View/pages/company_client_view.php
- **Function**: Карточка клиента. Реквизиты, контакты, документы, гранты доступа. Кнопки: редактировать, архивировать.
- **POST-actions**: POST /company/clients/{id}/archive
- **States**:
  - `loaded` — карточка
  - `not-found` — не найден
- **Screenshot**: co__client__view.png
- **Comment**: Проверить карточку клиента.

### PAGE-CO-CLIENT-EDIT
- **URL**: /company/clients/{id}/edit (пример: /company/clients/1/edit)
- **Menu**: ОПЕРАЦИИ → Клиенты
- **Role**: company_owner, logist (владелец)
- **Title**: Редактировать клиента
- **Context**: Клиенты — Компания: {название}
- **View**: app/View/pages/company_client_edit.php
- **Function**: Форма редактирования клиента.
- **POST-actions**: POST /company/clients/{id}/edit
- **States**:
  - `filled` — форма
  - `validation-error` — ошибки
- **Screenshot**: co__client__edit__filled.png
- **Comment**: Проверить форму.

## LOGIST 1 (logist_runtime_1 — владелец записей)

Ниже перечислены страницы, доступные логисту с ролью «владелец записей».
Этот логист создал большинство записей сам и имеет к ним полные права редактирования.
Меню такое же как у COMPANY OWNER, но без раздела «Пользователи» и без «Настройки».
UI грантов доступа отсутствует. Доступ к /company/logists отсутствует.

### PAGE-L1-DASHBOARD
- **URL**: /company/dashboard
- **Menu**: дашборд
- **Role**: logist
- **Title**: Панель управления
- **View**: app/View/pages/company_dashboard.php
- **Function**: Аналогично PAGE-CO-DASHBOARD.
- **States**: loaded, not-found
- **Screenshot**: l1__dashboard.png
- **Comment**: Боковое меню без «Пользователи» и «Настройки». Проверить корректность.

### PAGE-L1-CONTRACTORS
- **URL**: /company/contractors
- **Menu**: ОПЕРАЦИИ → Подрядчики
- **Role**: logist
- **View**: app/View/pages/company_contractors.php
- **Function**: Список подрядчиков. Логист-владелец видит все свои созданные записи. Может создавать новые.
- **Screenshot**: l1__contractors__list.png
- **Comment**: Данные полные (владелец видит все свои записи).

### PAGE-L1-CONTRACTORS-CREATE
- **URL**: /company/contractors/create
- **Menu**: ОПЕРАЦИИ → Подрядчики
- **Role**: logist
- **View**: app/View/pages/company_contractors_create.php
- **Function**: Создание подрядчика. Идентична PAGE-CO-CONTRACTORS-CREATE.
- **Screenshot**: l1__contractors__create__empty.png
- **Comment**: Проверить доступность формы.

### PAGE-L1-CONTRACTOR-VIEW
- **URL**: /company/contractors/{id}
- **Menu**: ОПЕРАЦИИ → Подрядчики
- **Role**: logist
- **View**: app/View/pages/company_contractor_view.php
- **Function**: Карточка подрядчика. Без блока грантов. Кнопки редактирования и архивации доступны (владелец записи).
- **States**: loaded, not-found
- **Screenshot**: l1__contractor__view.png
- **Comment**: Отсутствует блок грантов. Проверить, что нет пустого места.

### PAGE-L1-CONTRACTOR-EDIT
- **URL**: /company/contractors/{id}/edit
- **Menu**: ОПЕРАЦИИ → Подрядчики
- **Role**: logist
- **View**: app/View/pages/company_contractor_edit.php
- **Function**: Редактирование подрядчика. Доступно владельцу.
- **Screenshot**: l1__contractor__edit__filled.png
- **Comment**: Форма доступна.

### PAGE-L1-DRIVERS
- **URL**: /company/drivers
- **Menu**: ОПЕРАЦИИ → Водители
- **Role**: logist
- **View**: app/View/pages/company_drivers.php
- **Function**: Список водителей, созданных этим логистом.
- **Screenshot**: l1__drivers__list.png
- **Comment**: Данные владельца.

### PAGE-L1-DRIVERS-CREATE
- **URL**: /company/drivers/create
- **Role**: logist
- **View**: app/View/pages/company_drivers_create.php
- **Function**: Создание водителя.
- **Screenshot**: l1__drivers__create__empty.png
- **Comment**: Доступно.

### PAGE-L1-DRIVER-VIEW
- **URL**: /company/drivers/{id}
- **Role**: logist
- **View**: app/View/pages/company_driver_view.php
- **Function**: Карточка водителя. Без грантов. Кнопки доступны.
- **Screenshot**: l1__driver__view.png
- **Comment**: Без блока грантов.

### PAGE-L1-DRIVER-EDIT
- **URL**: /company/drivers/{id}/edit
- **Role**: logist
- **View**: app/View/pages/company_driver_edit.php
- **Function**: Редактирование водителя.
- **Screenshot**: l1__driver__edit__filled.png
- **Comment**: Доступно.

### PAGE-L1-VEHICLES
- **URL**: /company/vehicles
- **Menu**: ОПЕРАЦИИ → Транспортные единицы
- **Role**: logist
- **View**: app/View/pages/company_vehicles.php
- **Function**: Список ТЕ, созданных этим логистом.
- **Screenshot**: l1__vehicles__list.png
- **Comment**: Данные владельца.

### PAGE-L1-VEHICLES-CREATE
- **URL**: /company/vehicles/create
- **Role**: logist
- **View**: app/View/pages/company_vehicles_create.php
- **Function**: Создание ТЕ.
- **Screenshot**: l1__vehicles__create__empty.png
- **Comment**: Доступно.

### PAGE-L1-VEHICLE-VIEW
- **URL**: /company/vehicles/{id}
- **Role**: logist
- **View**: app/View/pages/company_vehicle_view.php
- **Function**: Карточка ТЕ. Без грантов. Кнопки доступны.
- **Screenshot**: l1__vehicle__view.png
- **Comment**: Без блока грантов.

### PAGE-L1-VEHICLE-EDIT
- **URL**: /company/vehicles/{id}/edit
- **Role**: logist
- **View**: app/View/pages/company_vehicle_edit.php
- **Function**: Редактирование ТЕ.
- **Screenshot**: l1__vehicle__edit__filled.png
- **Comment**: Доступно.

### PAGE-L1-VEHICLE-SETS
- **URL**: /company/vehicle-sets
- **Role**: logist
- **View**: app/View/pages/company_vehicle_sets.php
- **Function**: Список комплектов.
- **Screenshot**: l1__vehicle_sets__list.png
- **Comment**: Данные владельца.

### PAGE-L1-VEHICLE-SETS-CREATE
- **URL**: /company/vehicle-sets/create
- **Role**: logist
- **View**: app/View/pages/company_vehicle_sets_create.php
- **Function**: Создание комплекта.
- **Screenshot**: l1__vehicle_sets__create__empty.png
- **Comment**: Доступно.

### PAGE-L1-VEHICLE-SET-VIEW
- **URL**: /company/vehicle-sets/{id}
- **Role**: logist
- **View**: app/View/pages/company_vehicle_set_view.php
- **Function**: Карточка комплекта. Без грантов.
- **Screenshot**: l1__vehicle_set__view.png
- **Comment**: Без блока грантов.

### PAGE-L1-VEHICLE-SET-EDIT
- **URL**: /company/vehicle-sets/{id}/edit
- **Role**: logist
- **View**: app/View/pages/company_vehicle_set_edit.php
- **Function**: Редактирование комплекта.
- **Screenshot**: l1__vehicle_set__edit__filled.png
- **Comment**: Доступно.

### PAGE-L1-DRIVER-VEHICLE-BLOCKS
- **URL**: /company/driver-vehicle-blocks
- **Role**: logist
- **View**: app/View/pages/company_driver_vehicle_blocks.php
- **Function**: Список блоков.
- **Screenshot**: l1__dvb__list.png
- **Comment**: Данные владельца.

### PAGE-L1-DRIVER-VEHICLE-BLOCKS-CREATE
- **URL**: /company/driver-vehicle-blocks/create
- **Role**: logist
- **View**: app/View/pages/company_driver_vehicle_blocks_create.php
- **Function**: Создание блока.
- **Screenshot**: l1__dvb__create__empty.png
- **Comment**: Доступно.

### PAGE-L1-DRIVER-VEHICLE-BLOCK-VIEW
- **URL**: /company/driver-vehicle-blocks/{id}
- **Role**: logist
- **View**: app/View/pages/company_driver_vehicle_block_view.php
- **Function**: Карточка блока. Без грантов.
- **Screenshot**: l1__dvb__view.png
- **Comment**: Без блока грантов.

### PAGE-L1-DRIVER-VEHICLE-BLOCK-EDIT
- **URL**: /company/driver-vehicle-blocks/{id}/edit
- **Role**: logist
- **View**: app/View/pages/company_driver_vehicle_block_edit.php
- **Function**: Редактирование блока.
- **Screenshot**: l1__dvb__edit__filled.png
- **Comment**: Доступно.

### PAGE-L1-CREWS
- **URL**: /company/crews
- **Role**: logist
- **View**: app/View/pages/company_crews.php
- **Function**: Список экипажей.
- **Screenshot**: l1__crews__list.png
- **Comment**: Данные владельца.

### PAGE-L1-CREWS-CREATE
- **URL**: /company/crews/create
- **Role**: logist
- **View**: app/View/pages/company_crews_create.php
- **Function**: Создание экипажа.
- **Screenshot**: l1__crews__create__empty.png
- **Comment**: Доступно.

### PAGE-L1-CREW-VIEW
- **URL**: /company/crews/{id}
- **Role**: logist
- **View**: app/View/pages/company_crew_view.php
- **Function**: Карточка экипажа. Без грантов.
- **Screenshot**: l1__crew__view.png
- **Comment**: Без блока грантов.

### PAGE-L1-CREW-EDIT
- **URL**: /company/crews/{id}/edit
- **Role**: logist
- **View**: app/View/pages/company_crew_edit.php
- **Function**: Редактирование экипажа.
- **Screenshot**: l1__crew__edit__filled.png
- **Comment**: Доступно.

### PAGE-L1-DOCUMENTS
- **URL**: /company/documents
- **Role**: logist
- **View**: app/View/pages/company_documents.php
- **Function**: Список документов. Видит документы своих сущностей.
- **Screenshot**: l1__documents__list.png
- **Comment**: Фильтрация по владельцу.

### PAGE-L1-DOCUMENTS-UPLOAD
- **URL**: /company/documents/upload
- **Role**: logist
- **View**: app/View/pages/company_documents_upload.php
- **Function**: Загрузка документа.
- **Screenshot**: l1__documents__upload__empty.png
- **Comment**: Доступно.

### PAGE-L1-CLIENTS
- **URL**: /company/clients
- **Role**: logist
- **View**: app/View/pages/company_clients.php
- **Function**: Список клиентов, созданных этим логистом.
- **Screenshot**: l1__clients__list.png
- **Comment**: Данные владельца.

### PAGE-L1-CLIENTS-CREATE
- **URL**: /company/clients/create
- **Role**: logist
- **View**: app/View/pages/company_clients_create.php
- **Function**: Создание клиента.
- **Screenshot**: l1__clients__create__empty.png
- **Comment**: Доступно.

### PAGE-L1-CLIENT-VIEW
- **URL**: /company/clients/{id}
- **Role**: logist
- **View**: app/View/pages/company_client_view.php
- **Function**: Карточка клиента. Без грантов.
- **Screenshot**: l1__client__view.png
- **Comment**: Без блока грантов.

### PAGE-L1-CLIENT-EDIT
- **URL**: /company/clients/{id}/edit
- **Role**: logist
- **View**: app/View/pages/company_client_edit.php
- **Function**: Редактирование клиента.
- **Screenshot**: l1__client__edit__filled.png
- **Comment**: Доступно.

## LOGIST 2 (logist_runtime_2 — доступ по грантам)

Ниже перечислены страницы, доступные логисту, который не создавал записи сам,
а получает к ним доступ через entity_access_grants.
Меню такое же как у LOGIST 1: без «Пользователи» и «Настройки».
Ключевое отличие: списки могут быть пустыми (нет выданных грантов),
а на страницах просмотра сущностей может быть отказ в доступе (403 Forbidden),
если грант не выдан или просрочен.

Все страницы используют те же URL и view-файлы, что COMPANY OWNER / LOGIST 1,
но с фильтрацией «только по грантам».

### PAGE-L2-DASHBOARD
- **URL**: /company/dashboard
- **Role**: logist
- **View**: app/View/pages/company_dashboard.php
- **Function**: Дашборд компании. Отображается так же, как у LOGIST 1.
- **Screenshot**: l2__dashboard.png
- **Comment**: Проверить, что нет лишних элементов.

### PAGE-L2-CONTRACTORS
- **URL**: /company/contractors
- **Role**: logist
- **View**: app/View/pages/company_contractors.php
- **Function**: Список подрядчиков. Если гранты не выданы — пустой список (empty state). Если выданы — только разрешённые записи. Кнопка создания скрыта (нет created_by_user_id).
- **States**: empty (нет грантов), loaded (есть гранты)
- **Screenshot**: l2__contractors__list__empty.png, l2__contractors__list__granted.png
- **Comment**: Пустой список — самый важный стейт для LOGIST 2. Проверить empty_state, отсутствие кнопки создания.

### PAGE-L2-CONTRACTOR-VIEW
- **URL**: /company/contractors/{id}
- **Role**: logist
- **View**: app/View/pages/company_contractor_view.php
- **Function**: Карточка подрядчика. Если грант выдан — read-only просмотр (без кнопок редактирования/архивации). Если грант не выдан — 403 Forbidden.
- **States**: loaded (read-only), forbidden (403)
- **Screenshot**: l2__contractor__view__readonly.png
- **Comment**: Ключевая страница: проверить отсутствие кнопок редактирования/архивации/грантов, read-only отображение.

### PAGE-L2-DRIVERS
- **URL**: /company/drivers
- **Role**: logist
- **View**: app/View/pages/company_drivers.php
- **Function**: Список водителей по грантам. Аналогично подрядчикам: empty или ограниченный список.
- **Screenshot**: l2__drivers__list__empty.png
- **Comment**: Пустой список.

### PAGE-L2-DRIVER-VIEW
- **URL**: /company/drivers/{id}
- **Role**: logist
- **View**: app/View/pages/company_driver_view.php
- **Function**: Карточка водителя в read-only режиме.
- **Screenshot**: l2__driver__view__readonly.png
- **Comment**: Read-only, без кнопок действий.

### PAGE-L2-VEHICLES
- **URL**: /company/vehicles
- **Role**: logist
- **View**: app/View/pages/company_vehicles.php
- **Function**: Список ТЕ по грантам.
- **Screenshot**: l2__vehicles__list__empty.png
- **Comment**: Пустой список.

### PAGE-L2-VEHICLE-VIEW
- **URL**: /company/vehicles/{id}
- **Role**: logist
- **View**: app/View/pages/company_vehicle_view.php
- **Function**: Карточка ТЕ в read-only.
- **Screenshot**: l2__vehicle__view__readonly.png
- **Comment**: Read-only.

### PAGE-L2-VEHICLE-SETS
- **URL**: /company/vehicle-sets
- **Role**: logist
- **View**: app/View/pages/company_vehicle_sets.php
- **Function**: Список комплектов по грантам.
- **Screenshot**: l2__vehicle_sets__list__empty.png
- **Comment**: Пустой список.

### PAGE-L2-VEHICLE-SET-VIEW
- **URL**: /company/vehicle-sets/{id}
- **Role**: logist
- **View**: app/View/pages/company_vehicle_set_view.php
- **Function**: Карточка комплекта в read-only.
- **Screenshot**: l2__vehicle_set__view__readonly.png
- **Comment**: Read-only.

### PAGE-L2-DRIVER-VEHICLE-BLOCKS
- **URL**: /company/driver-vehicle-blocks
- **Role**: logist
- **View**: app/View/pages/company_driver_vehicle_blocks.php
- **Function**: Список блоков по грантам.
- **Screenshot**: l2__dvb__list__empty.png
- **Comment**: Пустой список.

### PAGE-L2-DRIVER-VEHICLE-BLOCK-VIEW
- **URL**: /company/driver-vehicle-blocks/{id}
- **Role**: logist
- **View**: app/View/pages/company_driver_vehicle_block_view.php
- **Function**: Карточка блока в read-only.
- **Screenshot**: l2__dvb__view__readonly.png
- **Comment**: Read-only.

### PAGE-L2-CREWS
- **URL**: /company/crews
- **Role**: logist
- **View**: app/View/pages/company_crews.php
- **Function**: Список экипажей по грантам.
- **Screenshot**: l2__crews__list__empty.png
- **Comment**: Пустой список.

### PAGE-L2-CREW-VIEW
- **URL**: /company/crews/{id}
- **Role**: logist
- **View**: app/View/pages/company_crew_view.php
- **Function**: Карточка экипажа в read-only.
- **Screenshot**: l2__crew__view__readonly.png
- **Comment**: Read-only.

### PAGE-L2-DOCUMENTS
- **URL**: /company/documents
- **Role**: logist
- **View**: app/View/pages/company_documents.php
- **Function**: Документы сущностей, к которым есть доступ по грантам.
- **Screenshot**: l2__documents__list__empty.png
- **Comment**: Пустой список.

### PAGE-L2-CLIENTS
- **URL**: /company/clients
- **Role**: logist
- **View**: app/View/pages/company_clients.php
- **Function**: Список клиентов по грантам.
- **Screenshot**: l2__clients__list__empty.png
- **Comment**: Пустой список.

### PAGE-L2-CLIENT-VIEW
- **URL**: /company/clients/{id}
- **Role**: logist
- **View**: app/View/pages/company_client_view.php
- **Function**: Карточка клиента в read-only.
- **Screenshot**: l2__client__view__readonly.png
- **Comment**: Read-only.
