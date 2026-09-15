# ERP PLANEX — Tenant Physical Schema — Other tenant domain — Part 3

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `48f9938095ea9929bf6ff0af2e961aada5cef2adb1437f47af0957fa922165b5`

## `max_event_deliveries`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `event_key` | `varchar(100)` | NO | NULL | — | `MUL` |
| `entity_key` | `varchar(100)` | NO | NULL | — | — |
| `chat_config_id` | `bigint unsigned` | NO | NULL | — | — |
| `bot_id` | `bigint unsigned` | NO | NULL | — | — |
| `external_chat_id` | `varchar(32)` | NO | NULL | — | — |
| `message_text` | `text` | NO | NULL | — | — |
| `message_format` | `enum('plain','html','markdown')` | NO | `plain` | — | — |
| `notify` | `tinyint(1)` | NO | `1` | — | — |
| `initiated_by_user_id` | `bigint unsigned` | YES | NULL | — | — |
| `status` | `enum('pending','sending','sent','failed')` | NO | `pending` | — | `MUL` |
| `attempts` | `int unsigned` | NO | `0` | — | — |
| `error_message` | `varchar(2000)` | YES | NULL | — | — |
| `max_message_id` | `varchar(128)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_max_event_delivery_status` (`status`, `id`)
- **UNIQUE** `uk_max_event_entity` (`event_key`, `entity_key`)

### Physical foreign keys

- None enforced by MySQL.

## `max_event_rules`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `event_key` | `varchar(100)` | NO | NULL | — | `PRI` |
| `is_enabled` | `tinyint(1)` | NO | `0` | — | — |
| `chat_config_id` | `bigint unsigned` | YES | NULL | — | `MUL` |
| `message_template` | `text` | NO | NULL | — | — |
| `message_format` | `enum('plain','html','markdown')` | NO | `plain` | — | — |
| `notify` | `tinyint(1)` | NO | `1` | — | — |
| `updated_by_user_id` | `bigint unsigned` | YES | NULL | — | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`event_key`)
- **INDEX** `fk_max_event_rule_chat` (`chat_config_id`)

### Physical foreign keys

- `fk_max_event_rule_chat`: (`chat_config_id`) → `max_chats`.`id` ON UPDATE NO ACTION ON DELETE SET NULL

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

## `waste_trip_customer_payments`

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
- **INDEX** `idx_waste_trip_customer_payments_trip` (`waste_trip_id`, `sort_order`, `id`)

### Physical foreign keys

- `fk_waste_trip_customer_payments_trip`: (`waste_trip_id`) → `waste_trips`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT

## `waste_trip_namespace_state`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `singleton_id` | `tinyint unsigned` | NO | NULL | — | `PRI` |
| `legacy_high_water_at_cutover` | `bigint unsigned` | NO | NULL | — | — |
| `last_issued_business_id` | `bigint unsigned` | NO | NULL | — | — |
| `cutover_started_at` | `datetime` | YES | NULL | — | — |
| `cutover_completed_at` | `datetime` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`singleton_id`)

### Physical foreign keys

- None enforced by MySQL.

## `waste_trip_requests`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `waste_trip_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `feo_request_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `snapshot_mass_kg` | `decimal(18,3)` | NO | NULL | — | — |
| `position_no` | `int unsigned` | NO | `0` | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_waste_trip_requests_position` (`waste_trip_id`, `position_no`, `id`)
- **INDEX** `idx_waste_trip_requests_request` (`feo_request_id`, `waste_trip_id`)
- **UNIQUE** `uk_waste_trip_request` (`waste_trip_id`, `feo_request_id`)

### Physical foreign keys

- `fk_waste_trip_requests_feo`: (`feo_request_id`) → `feo_requests`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
- `fk_waste_trip_requests_trip`: (`waste_trip_id`) → `waste_trips`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
