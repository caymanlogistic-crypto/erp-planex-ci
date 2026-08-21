# ERP PLANEX — Tenant Physical Schema — Finance — Part 4

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `7299653b569db5d58c8c4c76138dc6f09ec3661886c8ab9ccac1f25ca6783e53`

## `finance_matching_rules`

**Domain:** Finance  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `name` | `varchar(255)` | NO | NULL | — | — |
| `active` | `tinyint(1)` | YES | `1` | — | `MUL` |
| `priority` | `int` | YES | `100` | — | — |
| `direction` | `varchar(20)` | YES | NULL | — | `MUL` |
| `bank_account_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `counterparty_inn` | `varchar(20)` | YES | NULL | — | — |
| `counterparty_id` | `int unsigned` | YES | NULL | — | — |
| `counterparty_type` | `varchar(20)` | YES | NULL | — | — |
| `invoice_number_pattern` | `varchar(255)` | YES | NULL | — | — |
| `purpose_contains` | `varchar(255)` | YES | NULL | — | — |
| `purpose_regex` | `varchar(500)` | YES | NULL | — | — |
| `amount_from` | `decimal(15,2)` | YES | NULL | — | — |
| `amount_to` | `decimal(15,2)` | YES | NULL | — | — |
| `action_type` | `varchar(30)` | NO | `categorize` | — | — |
| `target_dds_category_id` | `int unsigned` | YES | NULL | — | — |
| `target_cash_flow_center_id` | `int unsigned` | YES | NULL | — | — |
| `target_cash_account_id` | `int unsigned` | YES | NULL | — | — |
| `target_employee_identity_type` | `varchar(20)` | YES | NULL | — | `MUL` |
| `target_employee_identity_id` | `int unsigned` | YES | NULL | — | — |
| `target_employee_name_snapshot` | `varchar(255)` | YES | NULL | — | — |
| `target_employee_role_snapshot` | `varchar(50)` | YES | NULL | — | — |
| `target_counterparty_id` | `int unsigned` | YES | NULL | — | — |
| `target_counterparty_type` | `varchar(20)` | YES | NULL | — | — |
| `auto_apply` | `tinyint(1)` | YES | `0` | — | `MUL` |
| `deleted_at` | `datetime` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | YES | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | YES | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fmr_active_priority` (`active`, `priority`)
- **INDEX** `idx_fmr_auto_apply` (`auto_apply`)
- **INDEX** `idx_fmr_bank_account` (`bank_account_id`)
- **INDEX** `idx_fmr_direction` (`direction`)
- **INDEX** `idx_fmr_employee_target` (`target_employee_identity_type`, `target_employee_identity_id`)

### Physical foreign keys

- None enforced by MySQL.

## `finance_money_accounts`

**Domain:** Finance  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `type` | `varchar(20)` | NO | NULL | — | `MUL` |
| `name` | `varchar(255)` | NO | NULL | — | — |
| `bank_account_id` | `int unsigned` | YES | NULL | — | `UNI` |
| `currency` | `varchar(10)` | NO | `RUR` | — | — |
| `opening_balance` | `decimal(15,2)` | NO | `0.00` | — | — |
| `opening_balance_date` | `date` | YES | NULL | — | — |
| `is_active` | `tinyint(1)` | NO | `1` | — | `MUL` |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fma_active` (`is_active`)
- **INDEX** `idx_fma_bank_account` (`bank_account_id`)
- **INDEX** `idx_fma_type` (`type`)
- **UNIQUE** `uk_fma_bank_account` (`bank_account_id`)

### Physical foreign keys

- None enforced by MySQL.

## `finance_obligations`

**Domain:** Finance  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `source_type` | `varchar(50)` | NO | NULL | — | `MUL` |
| `source_key` | `varchar(255)` | NO | NULL | — | `UNI` |
| `source_id` | `int unsigned` | YES | NULL | — | — |
| `source_parent_type` | `varchar(50)` | NO | NULL | — | `MUL` |
| `source_parent_id` | `int unsigned` | NO | NULL | — | — |
| `direction` | `varchar(20)` | NO | NULL | — | `MUL` |
| `party_role` | `varchar(20)` | YES | NULL | — | — |
| `counterparty_entity_type` | `varchar(20)` | YES | NULL | — | `MUL` |
| `counterparty_entity_id` | `int unsigned` | YES | NULL | — | — |
| `counterparty_name` | `varchar(500)` | YES | NULL | — | — |
| `counterparty_inn` | `varchar(20)` | YES | NULL | — | `MUL` |
| `amount` | `decimal(12,2)` | NO | `0.00` | — | — |
| `condition_type` | `varchar(50)` | YES | NULL | — | — |
| `days_count` | `int` | YES | NULL | — | — |
| `days_kind` | `varchar(20)` | YES | NULL | — | — |
| `specific_due_date` | `date` | YES | NULL | — | — |
| `event_date` | `date` | YES | NULL | — | — |
| `forecast_due_date` | `date` | YES | NULL | — | — |
| `due_date` | `date` | YES | NULL | — | — |
| `paid_amount` | `decimal(12,2)` | NO | `0.00` | — | — |
| `status` | `varchar(30)` | NO | `planned` | — | — |
| `cancelled_at` | `datetime` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fo_counterparty` (`counterparty_entity_type`, `counterparty_entity_id`)
- **INDEX** `idx_fo_counterparty_inn` (`counterparty_inn`)
- **INDEX** `idx_fo_direction_status_due` (`direction`, `status`, `due_date`)
- **INDEX** `idx_fo_parent` (`source_parent_type`, `source_parent_id`)
- **INDEX** `idx_fo_source` (`source_type`, `source_id`)
- **UNIQUE** `uk_finance_obligation_source_key` (`source_key`)

### Physical foreign keys

- None enforced by MySQL.

## `finance_operation_allocations`

**Domain:** Finance  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `operation_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `invoice_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `obligation_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `linear_route_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `linear_route_payment_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `cash_flow_category_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `amount` | `decimal(12,2)` | NO | NULL | — | — |
| `allocation_date` | `date` | NO | NULL | — | `MUL` |
| `method` | `varchar(30)` | NO | `manual` | — | — |
| `rule_code` | `varchar(100)` | YES | NULL | — | — |
| `comment` | `text` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(50)` | YES | NULL | — | — |
| `cancelled_at` | `datetime` | YES | NULL | — | `MUL` |
| `cancelled_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `cancelled_by_role` | `varchar(50)` | YES | NULL | — | — |
| `cancel_reason` | `text` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_foa_cancelled` (`cancelled_at`)
- **INDEX** `idx_foa_cash_flow_category` (`cash_flow_category_id`)
- **INDEX** `idx_foa_date` (`allocation_date`)
- **INDEX** `idx_foa_invoice` (`invoice_id`)
- **INDEX** `idx_foa_obligation` (`obligation_id`)
- **INDEX** `idx_foa_operation` (`operation_id`)
- **INDEX** `idx_foa_payment_cancelled` (`linear_route_payment_id`, `cancelled_at`)
- **INDEX** `idx_foa_route` (`linear_route_id`)
- **INDEX** `idx_foa_route_payment` (`linear_route_payment_id`)
- **INDEX** `idx_foa_route_payment_id` (`linear_route_payment_id`)

### Physical foreign keys

- `fk_foa_invoice`: (`invoice_id`) → `finance_invoices`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
- `fk_foa_operation`: (`operation_id`) → `finance_operations`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
- `fk_foa_route_payment`: (`linear_route_payment_id`) → `linear_route_payments`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT

