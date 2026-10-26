# ERP PLANEX — Tenant Physical Schema — Finance — Part 5

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `dc181daddd43c45a02a9c0ae080db7163780e4bad54d6fc805222d2db1a1b57a`

## `finance_operation_allocations`

**Domain:** Finance<br>
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

## `finance_operations`

**Domain:** Finance<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `operation_type` | `varchar(20)` | NO | NULL | — | `MUL` |
| `status` | `varchar(20)` | NO | `DRAFT` | — | `MUL` |
| `source` | `varchar(30)` | NO | NULL | — | `MUL` |
| `money_account_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `transfer_account_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `transfer_group_id` | `varchar(64)` | YES | NULL | — | `MUL` |
| `transfer_direction` | `varchar(10)` | YES | NULL | — | `MUL` |
| `operation_date` | `date` | NO | NULL | — | `MUL` |
| `amount` | `decimal(15,2)` | NO | NULL | — | — |
| `currency` | `varchar(10)` | NO | `RUR` | — | — |
| `dds_category_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `cash_flow_center_id` | `int unsigned` | YES | NULL | — | — |
| `classification_status` | `varchar(30)` | NO | `UNALLOCATED` | — | `MUL` |
| `classification_rule_id` | `int unsigned` | YES | NULL | — | — |
| `classification_locked` | `tinyint(1)` | NO | `0` | — | — |
| `classification_updated_at` | `datetime` | YES | NULL | — | — |
| `counterparty_entity_type` | `varchar(20)` | YES | NULL | — | — |
| `counterparty_entity_id` | `int unsigned` | YES | NULL | — | — |
| `counterparty_name` | `varchar(500)` | YES | NULL | — | `MUL` |
| `counterparty_inn` | `varchar(20)` | YES | NULL | — | — |
| `purpose` | `text` | YES | NULL | — | — |
| `comment` | `text` | YES | NULL | — | — |
| `bank_transaction_id` | `int unsigned` | YES | NULL | — | `UNI` |
| `invoice_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `linear_route_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `linear_route_payment_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `dedupe_hash` | `varchar(64)` | YES | NULL | — | `UNI` |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `posted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `posted_by_role` | `varchar(20)` | YES | NULL | — | — |
| `cancelled_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `cancelled_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |
| `posted_at` | `datetime` | YES | NULL | — | — |
| `cancelled_at` | `datetime` | YES | NULL | — | — |
| `cancellation_reason` | `text` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_fop_bank_tx` (`bank_transaction_id`)
- **INDEX** `idx_fop_classification` (`classification_status`, `operation_date`)
- **INDEX** `idx_fop_counterparty_name` (`counterparty_name(191)`)
- **INDEX** `idx_fop_date` (`operation_date`)
- **INDEX** `idx_fop_dds_category` (`dds_category_id`)
- **INDEX** `idx_fop_dedupe` (`dedupe_hash`)
- **INDEX** `idx_fop_invoice` (`invoice_id`)
- **INDEX** `idx_fop_money_account` (`money_account_id`)
- **INDEX** `idx_fop_posted_cancelled` (`status`, `cancelled_at`, `id`)
- **INDEX** `idx_fop_route` (`linear_route_id`)
- **INDEX** `idx_fop_route_payment` (`linear_route_payment_id`)
- **INDEX** `idx_fop_source` (`source`)
- **INDEX** `idx_fop_status` (`status`)
- **INDEX** `idx_fop_transfer_account` (`transfer_account_id`)
- **INDEX** `idx_fop_transfer_direction` (`transfer_direction`)
- **INDEX** `idx_fop_transfer_group` (`transfer_group_id`)
- **INDEX** `idx_fop_type` (`operation_type`)
- **UNIQUE** `uk_fop_bank_tx` (`bank_transaction_id`)
- **UNIQUE** `uk_fop_dedupe` (`dedupe_hash`)

### Physical foreign keys

- None enforced by MySQL.
