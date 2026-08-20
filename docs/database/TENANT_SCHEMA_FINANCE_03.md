# ERP PLANEX — Tenant Physical Schema — Finance — Part 3

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `5a11731243c253a3b263a05621d2f811d7aa9438f658137356dbc913d830917c`

## `finance_employee_personal_expenses`

**Domain:** Finance  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `event_group_id` | `varchar(64)` | YES | NULL | — | `UNI` |
| `employee_movement_id` | `int unsigned` | YES | NULL | — | `UNI` |
| `receipt_finance_operation_id` | `int unsigned` | YES | NULL | — | `UNI` |
| `expense_finance_operation_id` | `int unsigned` | YES | NULL | — | `UNI` |
| `cash_resolution_id` | `int unsigned` | YES | NULL | — | — |
| `operation_date` | `date` | NO | NULL | — | `MUL` |
| `employee_identity_type` | `varchar(30)` | NO | NULL | — | `MUL` |
| `employee_identity_id` | `int unsigned` | NO | NULL | — | — |
| `employee_name_snapshot` | `varchar(255)` | NO | NULL | — | — |
| `employee_role_snapshot` | `varchar(50)` | YES | NULL | — | — |
| `amount` | `decimal(12,2)` | NO | NULL | — | — |
| `currency` | `char(3)` | NO | `RUR` | — | — |
| `cash_flow_center_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `cash_flow_center_name_snapshot` | `varchar(255)` | NO | NULL | — | — |
| `dds_category_id` | `int unsigned` | NO | NULL | — | — |
| `dds_category_name_snapshot` | `varchar(255)` | NO | NULL | — | — |
| `linear_route_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `counterparty_name` | `varchar(500)` | YES | NULL | — | — |
| `purpose` | `varchar(1000)` | NO | NULL | — | — |
| `comment` | `text` | YES | NULL | — | — |
| `status` | `varchar(20)` | NO | `POSTED` | — | — |
| `cancelled_at` | `datetime` | YES | NULL | — | — |
| `cancelled_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `cancelled_by_role` | `varchar(20)` | YES | NULL | — | — |
| `cancel_reason` | `varchar(1000)` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fepe_cfu_dds` (`cash_flow_center_id`, `dds_category_id`, `operation_date`)
- **INDEX** `idx_fepe_date_status` (`operation_date`, `status`)
- **INDEX** `idx_fepe_employee` (`employee_identity_type`, `employee_identity_id`, `operation_date`)
- **INDEX** `idx_fepe_route` (`linear_route_id`, `operation_date`)
- **UNIQUE** `uk_fepe_employee_movement` (`employee_movement_id`)
- **UNIQUE** `uk_fepe_event_group` (`event_group_id`)
- **UNIQUE** `uk_fepe_expense_operation` (`expense_finance_operation_id`)
- **UNIQUE** `uk_fepe_receipt_operation` (`receipt_finance_operation_id`)

### Physical foreign keys

- None enforced by MySQL.

## `finance_invoice_links`

**Domain:** Finance  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `invoice_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `obligation_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `linear_route_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `linear_route_payment_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `amount` | `decimal(12,2)` | YES | NULL | — | — |
| `side` | `varchar(20)` | YES | NULL | — | `MUL` |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fil_invoice` (`invoice_id`)
- **INDEX** `idx_fil_obligation` (`obligation_id`)
- **INDEX** `idx_fil_payment` (`linear_route_payment_id`)
- **INDEX** `idx_fil_route` (`linear_route_id`)
- **INDEX** `idx_fil_side` (`side`)

### Physical foreign keys

- `fk_fil_invoice`: (`invoice_id`) → `finance_invoices`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT

## `finance_invoices`

**Domain:** Finance  
**Purpose:** Finance invoice records.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `direction` | `varchar(20)` | NO | NULL | — | `MUL` |
| `number` | `varchar(100)` | NO | NULL | — | `MUL` |
| `invoice_date` | `date` | NO | NULL | — | `MUL` |
| `counterparty_entity_type` | `varchar(20)` | YES | NULL | — | `MUL` |
| `counterparty_entity_id` | `int unsigned` | YES | NULL | — | — |
| `counterparty_name` | `varchar(500)` | YES | NULL | — | — |
| `counterparty_inn` | `varchar(20)` | YES | NULL | — | — |
| `amount` | `decimal(12,2)` | NO | `0.00` | — | — |
| `vat_rate` | `decimal(5,2)` | YES | NULL | — | — |
| `basis` | `text` | YES | NULL | — | — |
| `planned_payment_date` | `date` | YES | NULL | — | — |
| `comment` | `text` | YES | NULL | — | — |
| `status` | `varchar(30)` | NO | `issued` | — | `MUL` |
| `paid_amount` | `decimal(12,2)` | NO | `0.00` | — | — |
| `first_paid_at` | `date` | YES | NULL | — | — |
| `fully_paid_at` | `date` | YES | NULL | — | — |
| `cancelled_at` | `datetime` | YES | NULL | — | — |
| `cancellation_reason` | `text` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fi_counterparty` (`counterparty_entity_type`, `counterparty_entity_id`)
- **INDEX** `idx_fi_date` (`invoice_date`)
- **INDEX** `idx_fi_direction` (`direction`)
- **INDEX** `idx_fi_number` (`number`)
- **INDEX** `idx_fi_status` (`status`)

### Physical foreign keys

- None enforced by MySQL.

## `finance_matching_results`

**Domain:** Finance  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `finance_operation_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `rule_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `reason` | `varchar(500)` | YES | NULL | — | — |
| `confidence` | `varchar(20)` | YES | NULL | — | — |
| `result` | `varchar(30)` | YES | NULL | — | `MUL` |
| `applied_at` | `datetime` | YES | NULL | — | — |
| `source` | `varchar(30)` | YES | `system` | — | — |
| `created_at` | `datetime` | YES | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | `MUL` |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fmr_created` (`created_at`)
- **INDEX** `idx_fmr_operation` (`finance_operation_id`)
- **INDEX** `idx_fmr_result` (`result`)
- **INDEX** `idx_fmr_rule` (`rule_id`)

### Physical foreign keys

- None enforced by MySQL.

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

