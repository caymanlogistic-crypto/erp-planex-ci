# ERP PLANEX — Tenant Physical Schema — Operations / Routes — Part 2

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `368b5cf1642f5ddd8c7f2d659445de03353b30370001e5a2113967358becc632`

## `linear_route_services`

**Domain:** Operations / Routes<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `linear_route_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `contractor_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `service_name` | `varchar(190)` | NO | NULL | — | — |
| `description` | `text` | YES | NULL | — | — |
| `sort_order` | `int unsigned` | NO | `0` | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(40)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(40)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |
| `deleted_at` | `datetime` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(40)` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_linear_route_services_contractor` (`contractor_id`)
- **INDEX** `idx_linear_route_services_route` (`linear_route_id`, `deleted_at`, `sort_order`, `id`)

### Physical foreign keys

- None enforced by MySQL.

## `linear_routes`

**Domain:** Operations / Routes<br>
**Purpose:** Linear route/trip operational records.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `route_type` | `varchar(20)` | NO | NULL | — | `MUL` |
| `client_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `carrier_contractor_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `principal_type` | `varchar(20)` | YES | NULL | — | `MUL` |
| `principal_id` | `int unsigned` | YES | NULL | — | — |
| `agency_contract_with` | `varchar(20)` | YES | NULL | — | — |
| `route_executor_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `cargo_type_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `planned_loading_date` | `date` | YES | NULL | — | `MUL` |
| `planned_unloading_date` | `date` | YES | NULL | — | — |
| `actual_loading_date` | `date` | YES | NULL | — | — |
| `actual_unloading_date` | `date` | YES | NULL | — | — |
| `closing_documents_received_date` | `date` | YES | NULL | — | `MUL` |
| `status` | `varchar(20)` | NO | `active` | — | `MUL` |
| `comments` | `text` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `created_by_membership_id` | `bigint unsigned` | YES | NULL | — | `MUL` |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `deleted_at` | `datetime` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_linear_routes_cargo_type` (`cargo_type_id`)
- **INDEX** `idx_linear_routes_carrier` (`carrier_contractor_id`)
- **INDEX** `idx_linear_routes_client` (`client_id`)
- **INDEX** `idx_linear_routes_created_by` (`created_by_user_id`)
- **INDEX** `idx_linear_routes_creator_membership` (`created_by_membership_id`)
- **INDEX** `idx_linear_routes_dates` (`planned_loading_date`, `planned_unloading_date`)
- **INDEX** `idx_linear_routes_executor` (`route_executor_id`)
- **INDEX** `idx_linear_routes_principal` (`principal_type`, `principal_id`)
- **INDEX** `idx_linear_routes_status` (`status`)
- **INDEX** `idx_linear_routes_type` (`route_type`)
- **INDEX** `idx_lr_closing_docs_date` (`closing_documents_received_date`)

### Physical foreign keys

- None enforced by MySQL.

## `production_calendar_days`

**Domain:** Operations / Routes<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `calendar_date` | `date` | NO | NULL | — | `UNI` |
| `calendar_year` | `smallint unsigned` | NO | NULL | — | `MUL` |
| `day_type` | `varchar(32)` | NO | NULL | — | — |
| `is_working_day` | `tinyint(1)` | NO | `0` | — | — |
| `name` | `varchar(255)` | YES | NULL | — | — |
| `note` | `varchar(1000)` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(50)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(50)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_production_calendar_working` (`calendar_year`, `is_working_day`)
- **INDEX** `idx_production_calendar_year` (`calendar_year`)
- **UNIQUE** `uk_production_calendar_date` (`calendar_date`)

### Physical foreign keys

- `fk_production_calendar_days_year`: (`calendar_year`) → `production_calendar_years`.`calendar_year` ON UPDATE CASCADE ON DELETE CASCADE

## `production_calendar_years`

**Domain:** Operations / Routes<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `calendar_year` | `smallint unsigned` | NO | NULL | — | `PRI` |
| `status` | `varchar(16)` | NO | `DRAFT` | — | `MUL` |
| `source_title` | `varchar(255)` | YES | NULL | — | — |
| `source_url` | `varchar(500)` | YES | NULL | — | — |
| `note` | `varchar(1000)` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(50)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(50)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`calendar_year`)
- **INDEX** `idx_production_calendar_year_status` (`status`)

### Physical foreign keys

- None enforced by MySQL.
