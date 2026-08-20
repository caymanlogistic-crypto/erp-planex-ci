# ERP PLANEX — Tenant Physical Schema — Finance — Part 2

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `5a11731243c253a3b263a05621d2f811d7aa9438f658137356dbc913d830917c`

## `finance_cash_resolutions`

**Domain:** Finance  
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

**Domain:** Finance  
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

## `finance_dds_categories`

**Domain:** Finance  
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

## `finance_employee_invoice_payments`

**Domain:** Finance  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `event_group_id` | `varchar(64)` | NO | NULL | — | `UNI` |
| `employee_identity_type` | `varchar(32)` | NO | NULL | — | `MUL` |
| `employee_identity_id` | `int unsigned` | NO | NULL | — | — |
| `employee_name_snapshot` | `varchar(255)` | NO | NULL | — | — |
| `employee_role_snapshot` | `varchar(64)` | YES | NULL | — | — |
| `invoice_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `invoice_number_snapshot` | `varchar(100)` | YES | NULL | — | — |
| `counterparty_name_snapshot` | `varchar(255)` | YES | NULL | — | — |
| `operation_date` | `date` | NO | NULL | — | — |
| `amount` | `decimal(14,2)` | NO | NULL | — | — |
| `currency` | `char(3)` | NO | `RUR` | — | — |
| `employee_movement_id` | `int unsigned` | NO | NULL | — | `UNI` |
| `receipt_finance_operation_id` | `int unsigned` | NO | NULL | — | `UNI` |
| `expense_finance_operation_id` | `int unsigned` | NO | NULL | — | `UNI` |
| `comment` | `text` | YES | NULL | — | — |
| `status` | `varchar(20)` | NO | `POSTED` | — | `MUL` |
| `cancelled_at` | `datetime` | YES | NULL | — | — |
| `cancelled_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `cancelled_by_role` | `varchar(20)` | YES | NULL | — | — |
| `cancellation_reason` | `varchar(500)` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_feip_employee_date` (`employee_identity_type`, `employee_identity_id`, `operation_date`)
- **INDEX** `idx_feip_invoice` (`invoice_id`, `status`)
- **INDEX** `idx_feip_status_date` (`status`, `operation_date`)
- **UNIQUE** `uk_feip_employee_movement` (`employee_movement_id`)
- **UNIQUE** `uk_feip_event_group` (`event_group_id`)
- **UNIQUE** `uk_feip_expense_operation` (`expense_finance_operation_id`)
- **UNIQUE** `uk_feip_receipt_operation` (`receipt_finance_operation_id`)

### Physical foreign keys

- `fk_feip_employee_movement`: (`employee_movement_id`) → `finance_employee_movements`.`id` ON UPDATE CASCADE ON DELETE RESTRICT
- `fk_feip_expense_operation`: (`expense_finance_operation_id`) → `finance_operations`.`id` ON UPDATE CASCADE ON DELETE RESTRICT
- `fk_feip_invoice`: (`invoice_id`) → `finance_invoices`.`id` ON UPDATE CASCADE ON DELETE RESTRICT
- `fk_feip_receipt_operation`: (`receipt_finance_operation_id`) → `finance_operations`.`id` ON UPDATE CASCADE ON DELETE RESTRICT

## `finance_employee_money_accounts`

**Domain:** Finance  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `money_account_id` | `int unsigned` | NO | NULL | — | `UNI` |
| `employee_identity_type` | `varchar(20)` | NO | NULL | — | `MUL` |
| `employee_identity_id` | `int unsigned` | NO | NULL | — | — |
| `employee_name_snapshot` | `varchar(255)` | NO | NULL | — | — |
| `employee_role_snapshot` | `varchar(50)` | YES | NULL | — | — |
| `is_active` | `tinyint(1)` | NO | `1` | — | `MUL` |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_finance_employee_money_accounts_active` (`is_active`)
- **UNIQUE** `uq_finance_employee_money_accounts_account` (`money_account_id`)
- **UNIQUE** `uq_finance_employee_money_accounts_identity` (`employee_identity_type`, `employee_identity_id`)

### Physical foreign keys

- None enforced by MySQL.

## `finance_employee_movements`

**Domain:** Finance  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `employee_user_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `employee_identity_type` | `varchar(20)` | NO | `TENANT_USER` | — | `MUL` |
| `employee_identity_id` | `int unsigned` | YES | NULL | — | — |
| `employee_name_snapshot` | `varchar(255)` | YES | NULL | — | — |
| `employee_role_snapshot` | `varchar(50)` | YES | NULL | — | — |
| `movement_type` | `varchar(20)` | NO | NULL | — | `MUL` |
| `source_type` | `varchar(20)` | NO | NULL | — | — |
| `finance_operation_id` | `int unsigned` | NO | NULL | — | `UNI` |
| `bank_transaction_id` | `int unsigned` | YES | NULL | — | `UNI` |
| `note` | `varchar(1000)` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fem_employee_created` (`employee_user_id`, `created_at`)
- **INDEX** `idx_fem_employee_identity` (`employee_identity_type`, `employee_identity_id`, `created_at`)
- **INDEX** `idx_fem_type_source` (`movement_type`, `source_type`)
- **UNIQUE** `uk_fem_bank_transaction` (`bank_transaction_id`)
- **UNIQUE** `uk_fem_finance_operation` (`finance_operation_id`)

### Physical foreign keys

- `fk_fem_bank_tx`: (`bank_transaction_id`) → `bank_transactions`.`id` ON UPDATE CASCADE ON DELETE RESTRICT
- `fk_fem_employee`: (`employee_user_id`) → `users`.`id` ON UPDATE CASCADE ON DELETE RESTRICT
- `fk_fem_operation`: (`finance_operation_id`) → `finance_operations`.`id` ON UPDATE CASCADE ON DELETE RESTRICT

