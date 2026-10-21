# ERP PLANEX — Tenant Physical Schema — Finance — Part 2

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `368b5cf1642f5ddd8c7f2d659445de03353b30370001e5a2113967358becc632`

## `finance_cash_resolutions`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `source_finance_operation_id` | `int unsigned` | NO | NULL | — | `UNI` |
| `resolution_type` | `varchar(32)` | NO | NULL | — | `MUL` |
| `target_identity_type` | `varchar(32)` | YES | NULL | — | — |
| `target_identity_id` | `int unsigned` | YES | NULL | — | — |
| `target_name_snapshot` | `varchar(255)` | YES | NULL | — | — |
| `outflow_finance_operation_id` | `int unsigned` | YES | NULL | — | `UNI` |
| `employee_movement_id` | `int unsigned` | YES | NULL | — | `UNI` |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fcr_resolution_target` (`resolution_type`, `target_identity_type`, `target_identity_id`)
- **UNIQUE** `uk_fcr_employee_movement` (`employee_movement_id`)
- **UNIQUE** `uk_fcr_outflow_operation` (`outflow_finance_operation_id`)
- **UNIQUE** `uk_fcr_source_operation` (`source_finance_operation_id`)

### Physical foreign keys

- `fk_fcr_employee_movement`: (`employee_movement_id`) → `finance_employee_movements`.`id` ON UPDATE CASCADE ON DELETE RESTRICT
- `fk_fcr_outflow_operation`: (`outflow_finance_operation_id`) → `finance_operations`.`id` ON UPDATE CASCADE ON DELETE RESTRICT
- `fk_fcr_source_operation`: (`source_finance_operation_id`) → `finance_operations`.`id` ON UPDATE CASCADE ON DELETE RESTRICT

## `finance_cash_route_receipts`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `finance_operation_id` | `int unsigned` | NO | NULL | — | `UNI` |
| `finance_allocation_id` | `int unsigned` | NO | NULL | — | `UNI` |
| `linear_route_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `linear_route_payment_id` | `int unsigned` | NO | NULL | — | — |
| `client_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fcrr_client` (`client_id`)
- **INDEX** `idx_fcrr_route` (`linear_route_id`, `linear_route_payment_id`)
- **UNIQUE** `uk_fcrr_allocation` (`finance_allocation_id`)
- **UNIQUE** `uk_fcrr_operation` (`finance_operation_id`)

### Physical foreign keys

- `fk_fcrr_allocation`: (`finance_allocation_id`) → `finance_operation_allocations`.`id` ON UPDATE CASCADE ON DELETE RESTRICT
- `fk_fcrr_operation`: (`finance_operation_id`) → `finance_operations`.`id` ON UPDATE CASCADE ON DELETE RESTRICT

## `finance_closing_counterparties`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `bank_transaction_id` | `int unsigned` | NO | NULL | — | `PRI` |
| `entity_type` | `varchar(20)` | NO | NULL | — | `MUL` |
| `entity_id` | `int unsigned` | NO | NULL | — | — |
| `assigned_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`bank_transaction_id`)
- **INDEX** `idx_fcc_entity` (`entity_type`, `entity_id`)

### Physical foreign keys

- `fk_fcc_bank_transaction`: (`bank_transaction_id`) → `bank_transactions`.`id` ON UPDATE NO ACTION ON DELETE CASCADE

## `finance_closing_document_exceptions`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `inn` | `varchar(20)` | NO | NULL | — | `MUL` |
| `purpose_contains` | `varchar(255)` | NO | `` | — | — |
| `comment` | `varchar(255)` | YES | NULL | — | — |
| `active` | `tinyint(1)` | NO | `1` | — | `MUL` |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fcde_active` (`active`)
- **UNIQUE** `uk_fcde_inn_purpose` (`inn`, `purpose_contains`)

### Physical foreign keys

- None enforced by MySQL.

## `finance_closing_document_links`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `bank_transaction_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `document_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `requirement_code` | `varchar(32)` | NO | NULL | — | — |
| `is_edo` | `tinyint(1)` | NO | `0` | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fcdl_document` (`document_id`)
- **INDEX** `idx_fcdl_transaction` (`bank_transaction_id`)
- **UNIQUE** `uk_fcdl_transaction_document_requirement` (`bank_transaction_id`, `document_id`, `requirement_code`)

### Physical foreign keys

- `fk_fcdl_bank_transaction`: (`bank_transaction_id`) → `bank_transactions`.`id` ON UPDATE NO ACTION ON DELETE CASCADE
- `fk_fcdl_document`: (`document_id`) → `documents`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT

## `finance_closing_document_manual_statuses`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `bank_transaction_id` | `int unsigned` | NO | NULL | — | `PRI` |
| `requirement_key` | `varchar(64)` | NO | NULL | — | `PRI` |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`bank_transaction_id`, `requirement_key`)

### Physical foreign keys

- `fk_fcdms_bank_transaction`: (`bank_transaction_id`) → `bank_transactions`.`id` ON UPDATE NO ACTION ON DELETE CASCADE

## `finance_closing_document_requirement_exceptions`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `inn` | `varchar(12)` | NO | NULL | — | `PRI` |
| `requirement_key` | `varchar(64)` | NO | NULL | — | `PRI` |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`inn`, `requirement_key`)

### Physical foreign keys

- None enforced by MySQL.

## `finance_dds_categories`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `parent_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `code` | `varchar(64)` | NO | NULL | — | `UNI` |
| `name` | `varchar(255)` | NO | NULL | — | — |
| `direction` | `varchar(20)` | NO | NULL | — | `MUL` |
| `is_active` | `tinyint(1)` | NO | `1` | — | `MUL` |
| `sort_order` | `int` | NO | `100` | — | `MUL` |
| `is_system` | `tinyint(1)` | NO | `0` | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fdc_active` (`is_active`)
- **INDEX** `idx_fdc_direction` (`direction`)
- **INDEX** `idx_fdc_parent` (`parent_id`)
- **INDEX** `idx_fdc_sort` (`sort_order`)
- **UNIQUE** `uk_fdc_code` (`code`)

### Physical foreign keys

- None enforced by MySQL.
