# ERP PLANEX — Tenant Physical Schema — Transport

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `368b5cf1642f5ddd8c7f2d659445de03353b30370001e5a2113967358becc632`

## `crew_drivers`

**Domain:** Transport<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `crew_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `driver_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `position` | `smallint unsigned` | NO | `1` | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_crew_drivers_crew` (`crew_id`)
- **INDEX** `idx_crew_drivers_driver` (`driver_id`)
- **UNIQUE** `uq_crew_driver` (`crew_id`, `driver_id`)
- **UNIQUE** `uq_crew_position` (`crew_id`, `position`)

### Physical foreign keys

- None enforced by MySQL.

## `crews`

**Domain:** Transport<br>
**Purpose:** Operational crews.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `contractor_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `vehicle_id` | `int unsigned` | NO | NULL | — | — |
| `driver_id` | `int unsigned` | NO | NULL | — | — |
| `status` | `varchar(20)` | NO | `active` | — | — |
| `comments` | `text` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `driver_vehicle_block_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `deleted_at` | `timestamp` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(32)` | YES | NULL | — | — |
| `active_crew_key` | `tinyint` | YES | NULL | `STORED GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **UNIQUE** `uk_crew` (`contractor_id`, `driver_vehicle_block_id`, `active_crew_key`)

### Physical foreign keys

- None enforced by MySQL.

## `driver_phones`

**Domain:** Transport<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `driver_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `phone` | `varchar(50)` | YES | NULL | — | `MUL` |
| `is_main` | `tinyint(1)` | YES | `0` | — | — |
| `comment` | `text` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_driver_id` (`driver_id`)
- **INDEX** `idx_phone` (`phone`)

### Physical foreign keys

- None enforced by MySQL.

## `driver_vehicle_blocks`

**Domain:** Transport<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `driver_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `vehicle_set_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `status` | `varchar(20)` | YES | `active` | — | — |
| `comments` | `text` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |
| `deleted_at` | `timestamp` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(32)` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_driver_id` (`driver_id`)
- **INDEX** `idx_vehicle_set_id` (`vehicle_set_id`)
- **UNIQUE** `uk_driver_set` (`driver_id`, `vehicle_set_id`)

### Physical foreign keys

- None enforced by MySQL.

## `drivers`

**Domain:** Transport<br>
**Purpose:** Driver master records.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `full_name` | `varchar(255)` | NO | NULL | — | — |
| `phone` | `varchar(50)` | NO | NULL | — | — |
| `email` | `varchar(255)` | YES | NULL | — | — |
| `license_number` | `varchar(50)` | YES | NULL | — | — |
| `license_category` | `varchar(50)` | YES | NULL | — | — |
| `license_issue_date` | `date` | YES | NULL | — | — |
| `license_expire_date` | `date` | YES | NULL | — | — |
| `status` | `varchar(20)` | NO | `active` | — | — |
| `comments` | `text` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_membership_id` | `bigint unsigned` | YES | NULL | — | `MUL` |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `passport_number` | `varchar(50)` | YES | NULL | — | — |
| `passport_issued_by` | `varchar(500)` | YES | NULL | — | — |
| `passport_department_code` | `varchar(10)` | YES | NULL | — | — |
| `passport_issue_date` | `date` | YES | NULL | — | — |
| `snils` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `deleted_at` | `timestamp` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(32)` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_drivers_creator_membership` (`created_by_membership_id`)

### Physical foreign keys

- None enforced by MySQL.

## `vehicle_sets`

**Domain:** Transport<br>
**Purpose:** Vehicle-unit combinations/sets.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `set_type` | `varchar(20)` | YES | NULL | — | `MUL` |
| `primary_vehicle_unit_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `secondary_vehicle_unit_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `status` | `varchar(20)` | YES | `active` | — | — |
| `comments` | `text` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_membership_id` | `bigint unsigned` | YES | NULL | — | `MUL` |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |
| `deleted_at` | `timestamp` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(32)` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_primary` (`primary_vehicle_unit_id`)
- **INDEX** `idx_secondary` (`secondary_vehicle_unit_id`)
- **INDEX** `idx_set_type` (`set_type`)
- **INDEX** `idx_vehicle_sets_creator_membership` (`created_by_membership_id`)

### Physical foreign keys

- None enforced by MySQL.

## `vehicle_units`

**Domain:** Transport<br>
**Purpose:** Physical vehicle units.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `plate_number` | `varchar(20)` | NO | NULL | — | `MUL` |
| `brand` | `varchar(100)` | YES | NULL | — | — |
| `model` | `varchar(100)` | YES | NULL | — | — |
| `vehicle_type` | `varchar(50)` | YES | NULL | — | — |
| `vin` | `varchar(50)` | YES | NULL | — | — |
| `vin_absent` | `tinyint(1)` | NO | `0` | — | — |
| `sts_number` | `varchar(50)` | YES | NULL | — | — |
| `pts_number` | `varchar(50)` | YES | NULL | — | — |
| `capacity_tons` | `decimal(8,2)` | YES | NULL | — | — |
| `volume_m3` | `decimal(8,2)` | YES | NULL | — | — |
| `status` | `varchar(20)` | NO | `active` | — | — |
| `comments` | `text` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_membership_id` | `bigint unsigned` | YES | NULL | — | `MUL` |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `unit_type` | `varchar(20)` | YES | NULL | — | — |
| `diagnostic_card_number` | `varchar(50)` | YES | NULL | — | — |
| `diagnostic_card_date` | `date` | YES | NULL | — | — |
| `diagnostic_card_expire` | `date` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `deleted_at` | `timestamp` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(32)` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_plate` (`plate_number`)
- **INDEX** `idx_vehicle_units_creator_membership` (`created_by_membership_id`)

### Physical foreign keys

- None enforced by MySQL.
