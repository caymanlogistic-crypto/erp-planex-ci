# ERP PLANEX — Tenant Physical Schema — Operations / Routes — Part 2

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `7299653b569db5d58c8c4c76138dc6f09ec3661886c8ab9ccac1f25ca6783e53`

## `production_calendar_days`

**Domain:** Operations / Routes  
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

**Domain:** Operations / Routes  
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

