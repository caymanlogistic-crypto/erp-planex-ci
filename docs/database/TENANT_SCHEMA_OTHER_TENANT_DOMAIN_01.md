# ERP PLANEX — Tenant Physical Schema — Other tenant domain — Part 1

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `368b5cf1642f5ddd8c7f2d659445de03353b30370001e5a2113967358becc632`

## `entity_responsibilities`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `entity_type` | `enum('client','contractor','vehicle_unit','driver','vehicle_set','route_executor')` | NO | NULL | — | `PRI` |
| `entity_id` | `bigint unsigned` | NO | NULL | — | `PRI` |
| `membership_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `assigned_by_membership_id` | `bigint unsigned` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`entity_type`, `entity_id`)
- **INDEX** `idx_entity_responsibility_member` (`membership_id`, `entity_type`, `entity_id`)

### Physical foreign keys

- None enforced by MySQL.

## `feo_import_changes`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `import_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `zayavka_id` | `varchar(64)` | NO | NULL | — | `MUL` |
| `action` | `enum('insert','update')` | NO | NULL | — | — |
| `previous_import_id` | `bigint unsigned` | YES | NULL | — | — |
| `before_json` | `longtext` | YES | NULL | — | — |
| `after_json` | `longtext` | NO | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_feo_import_changes_import` (`import_id`, `id`)
- **INDEX** `idx_feo_import_changes_request` (`zayavka_id`)

### Physical foreign keys

- `fk_feo_import_changes_import`: (`import_id`) → `feo_imports`.`id` ON UPDATE NO ACTION ON DELETE CASCADE

## `feo_imports`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `filename` | `varchar(255)` | NO | NULL | — | — |
| `file_hash` | `char(64)` | NO | NULL | — | `MUL` |
| `rows_total` | `int unsigned` | NO | `0` | — | — |
| `unique_requests` | `int unsigned` | NO | `0` | — | — |
| `new_count` | `int unsigned` | NO | `0` | — | — |
| `updated_count` | `int unsigned` | NO | `0` | — | — |
| `unchanged_count` | `int unsigned` | NO | `0` | — | — |
| `missing_count` | `int unsigned` | NO | `0` | — | — |
| `status` | `enum('applied','rolled_back')` | NO | `applied` | — | `MUL` |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `rolled_back_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `rolled_back_by_role` | `varchar(20)` | YES | NULL | — | — |
| `rolled_back_at` | `datetime` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_feo_imports_file_hash` (`file_hash`)
- **INDEX** `idx_feo_imports_status_id` (`status`, `id`)

### Physical foreign keys

- None enforced by MySQL.

## `feo_location_overrides`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `zayavka_id` | `varchar(64)` | NO | NULL | — | `PRI` |
| `batch_id` | `bigint unsigned` | NO | NULL | — | `MUL` |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`zayavka_id`)
- **INDEX** `fk_feo_location_batch` (`batch_id`)

### Physical foreign keys

- `fk_feo_location_batch`: (`batch_id`) → `feo_transfer_batches`.`id` ON UPDATE NO ACTION ON DELETE NO ACTION

## `feo_placement_block_requests`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `block_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `zayavka_id` | `varchar(64)` | NO | NULL | — | `MUL` |
| `position_no` | `int unsigned` | NO | `0` | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_feo_placement_request_id` (`zayavka_id`)
- **UNIQUE** `uk_feo_placement_block_request` (`block_id`, `zayavka_id`)

### Physical foreign keys

- `fk_feo_placement_block_requests_block`: (`block_id`) → `feo_placement_blocks`.`id` ON UPDATE NO ACTION ON DELETE CASCADE

## `feo_placement_blocks`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `name` | `varchar(255)` | YES | NULL | — | — |
| `block_type` | `enum('addition','exclusion')` | NO | NULL | — | `MUL` |
| `status` | `enum('applied','disabled')` | NO | `applied` | — | `MUL` |
| `source_type` | `enum('manual','xls','xlsx')` | NO | `manual` | — | — |
| `source_filename` | `varchar(255)` | YES | NULL | — | — |
| `source_file_key` | `varchar(128)` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_feo_placement_blocks_status_id` (`status`, `id`)
- **INDEX** `idx_feo_placement_blocks_type_id` (`block_type`, `id`)

### Physical foreign keys

- None enforced by MySQL.

## `feo_placement_current`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `zayavka_id` | `varchar(64)` | NO | NULL | — | `PRI` |
| `source_block_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`zayavka_id`)
- **INDEX** `idx_feo_placement_current_source_block` (`source_block_id`)

### Physical foreign keys

- `fk_feo_placement_current_source_block`: (`source_block_id`) → `feo_placement_blocks`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT

## `feo_request_cost_block_requests`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `block_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `zayavka_id` | `varchar(64)` | NO | NULL | — | `UNI` |
| `snapshot_mass_kg` | `decimal(18,3)` | NO | NULL | — | — |
| `position_no` | `int unsigned` | NO | `0` | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_feo_request_cost_block_position` (`block_id`, `position_no`, `id`)
- **UNIQUE** `uk_feo_request_cost_block_request` (`block_id`, `zayavka_id`)
- **UNIQUE** `uk_feo_request_cost_one_price_per_request` (`zayavka_id`)

### Physical foreign keys

- `fk_feo_request_cost_block_requests_block`: (`block_id`) → `feo_request_cost_blocks`.`id` ON UPDATE NO ACTION ON DELETE CASCADE

## `feo_request_cost_blocks`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `pricing_type` | `enum('rate_34_5','rate_38','agreement')` | NO | NULL | — | `MUL` |
| `rate_per_kg` | `decimal(10,2)` | YES | NULL | — | — |
| `snapshot_request_count` | `int unsigned` | NO | NULL | — | — |
| `snapshot_total_mass_kg` | `decimal(18,3)` | NO | NULL | — | — |
| `total_cost` | `decimal(18,2)` | NO | NULL | — | — |
| `comment` | `text` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | `MUL` |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_feo_request_cost_blocks_created` (`created_at`, `id`)
- **INDEX** `idx_feo_request_cost_blocks_type` (`pricing_type`, `id`)

### Physical foreign keys

- None enforced by MySQL.

## `feo_request_utilizer_overrides`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `zayavka_id` | `varchar(64)` | NO | NULL | — | `PRI` |
| `utilizer_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`zayavka_id`)
- **INDEX** `idx_feo_request_utilizer_override_utilizer` (`utilizer_id`, `zayavka_id`)

### Physical foreign keys

- `fk_feo_request_utilizer_override_request`: (`zayavka_id`) → `feo_requests`.`zayavka_id` ON UPDATE NO ACTION ON DELETE CASCADE
- `fk_feo_request_utilizer_override_utilizer`: (`utilizer_id`) → `feo_utilizers`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
