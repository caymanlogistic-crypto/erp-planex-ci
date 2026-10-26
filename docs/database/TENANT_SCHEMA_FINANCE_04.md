# ERP PLANEX — Tenant Physical Schema — Finance — Part 4

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `dc181daddd43c45a02a9c0ae080db7163780e4bad54d6fc805222d2db1a1b57a`

## `finance_invoices`

**Domain:** Finance<br>
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

**Domain:** Finance<br>
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

**Domain:** Finance<br>
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

**Domain:** Finance<br>
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

**Domain:** Finance<br>
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
