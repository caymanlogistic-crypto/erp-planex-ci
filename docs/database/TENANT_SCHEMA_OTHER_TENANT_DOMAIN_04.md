# ERP PLANEX — Tenant Physical Schema — Other tenant domain — Part 4

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `9ebbb42ee7f9a17f3a6c94a390435863424b3b6f40f818fe72719a6cefd32d28`

## `max_message_logs`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `bot_id` | `bigint unsigned` | YES | NULL | — | `MUL` |
| `chat_config_id` | `bigint unsigned` | YES | NULL | — | `MUL` |
| `external_chat_id` | `varchar(32)` | YES | NULL | — | — |
| `source` | `enum('test','manual','event')` | NO | NULL | — | — |
| `message_text` | `text` | NO | NULL | — | — |
| `message_format` | `enum('plain','markdown','html')` | NO | `plain` | — | — |
| `notify` | `tinyint(1)` | NO | `1` | — | — |
| `status` | `enum('sent','failed')` | NO | NULL | — | `MUL` |
| `max_message_id` | `varchar(128)` | YES | NULL | — | — |
| `error_message` | `varchar(2000)` | YES | NULL | — | — |
| `initiated_by_user_id` | `bigint unsigned` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | `MUL` |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `fk_max_message_logs_bot` (`bot_id`)
- **INDEX** `fk_max_message_logs_chat` (`chat_config_id`)
- **INDEX** `idx_max_message_logs_created` (`created_at`, `id`)
- **INDEX** `idx_max_message_logs_status` (`status`, `created_at`)

### Physical foreign keys

- `fk_max_message_logs_bot`: (`bot_id`) → `max_bots`.`id` ON UPDATE NO ACTION ON DELETE SET NULL
- `fk_max_message_logs_chat`: (`chat_config_id`) → `max_chats`.`id` ON UPDATE NO ACTION ON DELETE SET NULL

## `route_executor_contract_mails`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `card_id` | `char(36)` | NO | NULL | — | `PRI` |
| `actor_account_id` | `bigint unsigned` | NO | NULL | — | — |
| `trip_business_id` | `bigint unsigned` | NO | NULL | — | — |
| `recipient_email` | `varchar(254)` | NO | NULL | — | — |
| `message_id` | `varchar(255)` | NO | NULL | — | — |
| `mail_status` | `varchar(16)` | NO | NULL | — | `MUL` |
| `sent_at` | `datetime(6)` | YES | NULL | — | — |
| `created_at` | `datetime(6)` | NO | `CURRENT_TIMESTAMP(6)` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime(6)` | NO | `CURRENT_TIMESTAMP(6)` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP(6)` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`card_id`)
- **INDEX** `idx_route_executor_contract_mail_status` (`mail_status`, `updated_at`)

### Physical foreign keys

- None enforced by MySQL.

## `route_executor_review_cards`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `card_id` | `char(36)` | NO | NULL | — | `PRI` |
| `actor_account_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `last_editor_account_id` | `bigint unsigned` | NO | NULL | — | — |
| `revision` | `int unsigned` | NO | `1` | — | — |
| `snapshot_json` | `mediumtext` | NO | NULL | — | — |
| `files_json` | `mediumtext` | NO | NULL | — | — |
| `responsible_membership_id` | `bigint unsigned` | YES | NULL | — | — |
| `trip_business_id` | `bigint unsigned` | YES | NULL | — | — |
| `contractor_name` | `varchar(255)` | NO | `` | — | — |
| `driver_name` | `varchar(255)` | NO | `` | — | — |
| `driver_phone` | `varchar(64)` | NO | `` | — | — |
| `vehicle_type` | `varchar(40)` | NO | `` | — | — |
| `plate` | `varchar(40)` | NO | `` | — | — |
| `deleted_at` | `datetime(6)` | YES | NULL | — | `MUL` |
| `created_at` | `datetime(6)` | NO | `CURRENT_TIMESTAMP(6)` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime(6)` | NO | `CURRENT_TIMESTAMP(6)` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP(6)` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`card_id`)
- **INDEX** `idx_route_executor_review_cards_active` (`deleted_at`, `updated_at`)
- **INDEX** `idx_route_executor_review_cards_actor` (`actor_account_id`, `updated_at`)

### Physical foreign keys

- None enforced by MySQL.

## `route_executor_review_sends`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `card_id` | `char(36)` | NO | NULL | — | `PRI` |
| `actor_account_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `responsible_membership_id` | `bigint unsigned` | YES | NULL | — | — |
| `stage` | `varchar(16)` | NO | NULL | — | — |
| `partial_message_id` | `varchar(255)` | YES | NULL | — | — |
| `partial_mail_status` | `varchar(16)` | YES | NULL | — | — |
| `final_message_id` | `varchar(255)` | YES | NULL | — | — |
| `final_mail_status` | `varchar(16)` | YES | NULL | — | — |
| `partial_at` | `datetime(6)` | YES | NULL | — | — |
| `final_at` | `datetime(6)` | YES | NULL | — | — |
| `created_at` | `datetime(6)` | NO | `CURRENT_TIMESTAMP(6)` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime(6)` | NO | `CURRENT_TIMESTAMP(6)` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP(6)` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`card_id`)
- **INDEX** `idx_route_executor_review_actor` (`actor_account_id`, `updated_at`)

### Physical foreign keys

- None enforced by MySQL.

## `warehouse_stock_issue_exceptions`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `flight_id` | `bigint` | NO | NULL | — | `MUL` |
| `message_hash` | `char(64)` | NO | NULL | — | — |
| `message_text` | `text` | NO | NULL | — | — |
| `trip_name` | `varchar(255)` | NO | `` | — | — |
| `warehouse_name` | `varchar(255)` | NO | `` | — | — |
| `approved_by_user_id` | `bigint` | NO | NULL | — | — |
| `approved_at` | `datetime(6)` | NO | NULL | — | `MUL` |
| `revoked_by_user_id` | `bigint` | YES | NULL | — | — |
| `revoked_at` | `datetime(6)` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `ix_warehouse_stock_issue_exception_date` (`approved_at`)
- **UNIQUE** `ux_warehouse_stock_issue_exception` (`flight_id`, `message_hash`)

### Physical foreign keys

- None enforced by MySQL.

## `waste_trip_audit_log`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `waste_trip_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `action` | `enum('create','update','composition_correction','cancel')` | NO | NULL | — | — |
| `before_json` | `longtext` | YES | NULL | — | — |
| `after_json` | `longtext` | YES | NULL | — | — |
| `actor_user_id` | `int unsigned` | YES | NULL | — | — |
| `actor_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_waste_trip_audit_trip` (`waste_trip_id`, `id`)

### Physical foreign keys

- `fk_waste_trip_audit_trip`: (`waste_trip_id`) → `waste_trips`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT

## `waste_trip_carrier_payments`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `waste_trip_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `sort_order` | `int unsigned` | NO | `1` | — | — |
| `amount` | `decimal(12,2)` | NO | NULL | — | — |
| `payment_type` | `varchar(50)` | NO | NULL | — | — |
| `payment_method` | `varchar(20)` | NO | `cashless` | — | — |
| `vat_rate` | `decimal(5,2)` | YES | NULL | — | — |
| `condition_type` | `varchar(50)` | NO | NULL | — | — |
| `days_count` | `int` | YES | NULL | — | — |
| `days_kind` | `varchar(10)` | YES | NULL | — | — |
| `specific_due_date` | `date` | YES | NULL | — | — |
| `condition_comment` | `text` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_waste_trip_carrier_payments_trip` (`waste_trip_id`, `sort_order`, `id`)

### Physical foreign keys

- `fk_waste_trip_carrier_payments_trip`: (`waste_trip_id`) → `waste_trips`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT

## `waste_trip_closing_finance_links`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `finance_link_id` | `int unsigned` | NO | NULL | — | `PRI` |
| `closing_document_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `bank_transaction_id` | `int unsigned` | NO | NULL | — | `MUL` |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`finance_link_id`)
- **INDEX** `idx_wtcfl_payment` (`bank_transaction_id`)
- **UNIQUE** `uq_wtcfl_document_payment` (`closing_document_id`, `bank_transaction_id`)

### Physical foreign keys

- `fk_wtcfl_bank_transaction`: (`bank_transaction_id`) → `bank_transactions`.`id` ON UPDATE NO ACTION ON DELETE CASCADE
- `fk_wtcfl_document`: (`closing_document_id`) → `waste_trip_closing_documents`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
- `fk_wtcfl_finance_link`: (`finance_link_id`) → `finance_closing_document_links`.`id` ON UPDATE NO ACTION ON DELETE CASCADE

## `waste_trip_closing_reviews`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `waste_trip_id` | `bigint unsigned` | NO | NULL | — | `PRI` |
| `state` | `enum('work','sending','review','failed','unknown','payment')` | NO | `work` | — | — |
| `version` | `int unsigned` | NO | `0` | — | — |
| `recipient_email` | `varchar(254)` | YES | NULL | — | — |
| `archive_token` | `char(64)` | YES | NULL | — | — |
| `message_id` | `varchar(254)` | YES | NULL | — | — |
| `mail_status` | `varchar(24)` | YES | NULL | — | — |
| `submitted_at` | `datetime` | YES | NULL | — | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`waste_trip_id`)

### Physical foreign keys

- `fk_wtcr_trip`: (`waste_trip_id`) → `waste_trips`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
