# ERP PLANEX — Tenant Physical Schema — Finance — Part 1

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `368b5cf1642f5ddd8c7f2d659445de03353b30370001e5a2113967358becc632`

## `bank_accounts`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `account_number` | `varchar(34)` | NO | NULL | — | `UNI` |
| `bank_name` | `varchar(255)` | YES | NULL | — | — |
| `currency` | `varchar(10)` | NO | `RUR` | — | — |
| `company_inn` | `varchar(20)` | YES | NULL | — | — |
| `company_name` | `varchar(255)` | YES | NULL | — | — |
| `opening_balance` | `decimal(15,2)` | YES | NULL | — | — |
| `closing_balance` | `decimal(15,2)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **UNIQUE** `uk_bank_accounts_number` (`account_number`)

### Physical foreign keys

- None enforced by MySQL.

## `bank_daily_balances`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `account_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `statement_date` | `date` | NO | NULL | — | `MUL` |
| `currency` | `varchar(10)` | NO | `RUR` | — | — |
| `opening_balance` | `decimal(15,2)` | YES | NULL | — | — |
| `debit_turnover` | `decimal(15,2)` | YES | NULL | — | — |
| `credit_turnover` | `decimal(15,2)` | YES | NULL | — | — |
| `closing_balance` | `decimal(15,2)` | YES | NULL | — | — |
| `dedupe_hash` | `varchar(64)` | NO | NULL | — | `UNI` |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_bank_balances_account` (`account_id`)
- **INDEX** `idx_bank_balances_date` (`statement_date`)
- **UNIQUE** `uk_bank_balances_dedupe` (`dedupe_hash`)

### Physical foreign keys

- None enforced by MySQL.

## `bank_import_balances`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `import_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `balance_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_imp_bal_bal` (`balance_id`)
- **INDEX** `idx_imp_bal_imp` (`import_id`)
- **UNIQUE** `uk_imp_bal_link` (`import_id`, `balance_id`)

### Physical foreign keys

- None enforced by MySQL.

## `bank_import_transactions`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `import_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `transaction_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_imp_tx_imp` (`import_id`)
- **INDEX** `idx_imp_tx_tx` (`transaction_id`)
- **UNIQUE** `uk_imp_tx_link` (`import_id`, `transaction_id`)

### Physical foreign keys

- None enforced by MySQL.

## `bank_statement_imports`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `source` | `varchar(10)` | NO | `manual` | — | `MUL` |
| `source_uid` | `varchar(255)` | YES | NULL | — | — |
| `filename` | `varchar(255)` | YES | NULL | — | — |
| `file_hash` | `varchar(64)` | NO | NULL | — | `UNI` |
| `account_number` | `varchar(34)` | YES | NULL | — | `MUL` |
| `period_from` | `date` | YES | NULL | — | — |
| `period_to` | `date` | YES | NULL | — | — |
| `imported_transactions` | `int unsigned` | NO | `0` | — | — |
| `imported_balances` | `int unsigned` | NO | `0` | — | — |
| `status` | `varchar(20)` | NO | `pending` | — | `MUL` |
| `error_message` | `text` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_bank_imports_account` (`account_number`)
- **INDEX** `idx_bank_imports_source` (`source`)
- **INDEX** `idx_bank_imports_status` (`status`)
- **UNIQUE** `uk_bank_imports_hash` (`file_hash`)

### Physical foreign keys

- None enforced by MySQL.

## `bank_statement_settings`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `bank_code` | `varchar(20)` | NO | `vtb` | — | `MUL` |
| `imap_host` | `varchar(255)` | NO | NULL | — | — |
| `imap_port` | `int unsigned` | NO | `993` | — | — |
| `imap_ssl` | `tinyint(1)` | NO | `1` | — | — |
| `username` | `varchar(255)` | NO | NULL | — | — |
| `password_encrypted` | `varchar(512)` | NO | `` | — | — |
| `sender_filter` | `varchar(255)` | NO | `vtb-inform@vtb.ru` | — | — |
| `subject_filter` | `varchar(255)` | NO | `Регулярная выписка` | — | — |
| `is_active` | `tinyint(1)` | NO | `1` | — | `MUL` |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_bank_code` (`bank_code`)
- **INDEX** `idx_is_active` (`is_active`)

### Physical foreign keys

- None enforced by MySQL.

## `bank_transactions`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `account_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `operation_date` | `date` | NO | NULL | — | `MUL` |
| `document_number` | `varchar(50)` | YES | NULL | — | — |
| `operation_type` | `varchar(100)` | YES | NULL | — | — |
| `counterparty_name` | `varchar(255)` | YES | NULL | — | `MUL` |
| `counterparty_inn` | `varchar(20)` | YES | NULL | — | — |
| `counterparty_bank_bik` | `varchar(20)` | YES | NULL | — | — |
| `counterparty_account` | `varchar(34)` | YES | NULL | — | — |
| `debit_amount` | `decimal(15,2)` | NO | `0.00` | — | — |
| `credit_amount` | `decimal(15,2)` | NO | `0.00` | — | — |
| `purpose` | `text` | YES | NULL | — | — |
| `cash_flow_center_id` | `int unsigned` | YES | NULL | — | — |
| `dds_category_id` | `int unsigned` | YES | NULL | — | — |
| `classification_status` | `varchar(30)` | NO | `UNALLOCATED` | — | `MUL` |
| `classification_rule_id` | `int unsigned` | YES | NULL | — | — |
| `classification_locked` | `tinyint(1)` | NO | `0` | — | — |
| `classification_updated_at` | `datetime` | YES | NULL | — | — |
| `is_internal_transfer` | `tinyint(1)` | NO | `0` | — | — |
| `linked_cash_transaction_id` | `int unsigned` | YES | NULL | — | — |
| `dedupe_hash` | `varchar(64)` | NO | NULL | — | `UNI` |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_bank_tx_account` (`account_id`)
- **INDEX** `idx_bank_tx_classification` (`classification_status`, `operation_date`)
- **INDEX** `idx_bank_tx_counterparty` (`counterparty_name(191)`)
- **INDEX** `idx_bank_tx_date` (`operation_date`)
- **UNIQUE** `uk_bank_tx_dedupe` (`dedupe_hash`)

### Physical foreign keys

- None enforced by MySQL.

## `finance_audit_log`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `entity_type` | `varchar(50)` | NO | NULL | — | `MUL` |
| `entity_id` | `int unsigned` | NO | NULL | — | — |
| `action` | `varchar(50)` | NO | NULL | — | `MUL` |
| `old_values` | `text` | YES | NULL | — | — |
| `new_values` | `text` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | `MUL` |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fal_action` (`action`)
- **INDEX** `idx_fal_created` (`created_at`)
- **INDEX** `idx_fal_entity` (`entity_type`, `entity_id`)

### Physical foreign keys

- None enforced by MySQL.

## `finance_cash_flow_center_dds_categories`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `cash_flow_center_id` | `int unsigned` | NO | NULL | — | `PRI` |
| `dds_category_id` | `int unsigned` | NO | NULL | — | `PRI` |
| `sort_order` | `int` | NO | `100` | — | — |
| `is_active` | `tinyint(1)` | NO | `1` | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`cash_flow_center_id`, `dds_category_id`)
- **INDEX** `idx_fsc_active` (`cash_flow_center_id`, `is_active`, `sort_order`)
- **INDEX** `idx_fsc_dds` (`dds_category_id`)

### Physical foreign keys

- None enforced by MySQL.

## `finance_cash_flow_centers`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `name` | `varchar(255)` | NO | NULL | — | `MUL` |
| `is_active` | `tinyint(1)` | NO | `1` | — | `MUL` |
| `sort_order` | `int` | NO | `100` | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fcfc_active_sort` (`is_active`, `sort_order`)
- **INDEX** `idx_fcfc_name` (`name(191)`)

### Physical foreign keys

- None enforced by MySQL.
