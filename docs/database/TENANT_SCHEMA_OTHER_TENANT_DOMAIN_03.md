# ERP PLANEX — Tenant Physical Schema — Other tenant domain — Part 3

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `368b5cf1642f5ddd8c7f2d659445de03353b30370001e5a2113967358becc632`

## `feo_utilizers`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `full_name` | `varchar(255)` | NO | NULL | — | — |
| `short_name` | `varchar(80)` | NO | NULL | — | `UNI` |
| `address` | `varchar(500)` | YES | NULL | — | — |
| `latitude` | `decimal(10,7)` | YES | NULL | — | — |
| `longitude` | `decimal(10,7)` | YES | NULL | — | — |
| `fkko_classification` | `varchar(500)` | YES | NULL | — | — |
| `adr` | `tinyint(1)` | NO | `0` | — | — |
| `color_hex` | `char(7)` | NO | `#2563EB` | — | — |
| `is_active` | `tinyint(1)` | NO | `1` | — | `MUL` |
| `sort_order` | `int` | NO | `100` | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_feo_utilizers_active_order` (`is_active`, `sort_order`, `id`)
- **UNIQUE** `uk_feo_utilizers_short_name` (`short_name`)

### Physical foreign keys

- None enforced by MySQL.

## `feo_weight_batches`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `name` | `varchar(180)` | NO | NULL | — | — |
| `comment` | `text` | YES | NULL | — | — |
| `request_count` | `int unsigned` | NO | NULL | — | — |
| `operation_key` | `char(64)` | NO | NULL | — | `UNI` |
| `status` | `enum('applied','reverted')` | NO | `applied` | — | — |
| `created_by_user_id` | `int unsigned` | NO | NULL | — | — |
| `created_by_name` | `varchar(255)` | NO | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `reverted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `reverted_by_name` | `varchar(255)` | YES | NULL | — | — |
| `reverted_at` | `datetime` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **UNIQUE** `uk_feo_weight_operation` (`operation_key`)

### Physical foreign keys

- None enforced by MySQL.

## `feo_weight_items`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `batch_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `zayavka_id` | `varchar(64)` | NO | NULL | — | `MUL` |
| `request_row_id` | `bigint unsigned` | NO | NULL | — | — |
| `before_netto` | `decimal(14,4)` | YES | NULL | — | — |
| `before_brutto` | `decimal(14,4)` | YES | NULL | — | — |
| `after_netto` | `decimal(14,4)` | YES | NULL | — | — |
| `after_brutto` | `decimal(14,4)` | YES | NULL | — | — |
| `legacy_snapshot_json` | `json` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_feo_weight_request` (`zayavka_id`, `batch_id`)
- **UNIQUE** `uk_feo_weight_item` (`batch_id`, `zayavka_id`)

### Physical foreign keys

- `fk_feo_weight_item_batch`: (`batch_id`) → `feo_weight_batches`.`id` ON UPDATE NO ACTION ON DELETE NO ACTION

## `geocoding_settings`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `tinyint unsigned` | NO | NULL | — | `PRI` |
| `api_key_encrypted` | `text` | NO | NULL | — | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)

### Physical foreign keys

- None enforced by MySQL.

## `max_bots`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `name` | `varchar(120)` | NO | NULL | — | — |
| `token_encrypted` | `mediumtext` | NO | NULL | — | — |
| `bot_user_id` | `varchar(32)` | YES | NULL | — | — |
| `bot_first_name` | `varchar(255)` | YES | NULL | — | — |
| `bot_username` | `varchar(255)` | YES | NULL | — | — |
| `webhook_secret_encrypted` | `mediumtext` | NO | NULL | — | — |
| `webhook_status` | `enum('not_configured','active','error')` | NO | `not_configured` | — | — |
| `webhook_last_error` | `varchar(1000)` | YES | NULL | — | — |
| `verified_at` | `datetime` | YES | NULL | — | — |
| `is_active` | `tinyint(1)` | NO | `1` | — | `MUL` |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_max_bots_active` (`is_active`, `id`)

### Physical foreign keys

- None enforced by MySQL.

## `max_chats`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `bot_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `chat_id` | `varchar(32)` | NO | NULL | — | — |
| `name` | `varchar(160)` | NO | NULL | — | — |
| `max_title` | `varchar(255)` | YES | NULL | — | — |
| `chat_type` | `enum('chat','channel','dialog','unknown')` | NO | `unknown` | — | — |
| `max_status` | `enum('active','removed','left','closed','unknown')` | NO | `unknown` | — | — |
| `purpose` | `varchar(500)` | YES | NULL | — | — |
| `is_active` | `tinyint(1)` | NO | `1` | — | `MUL` |
| `discovered_via` | `enum('manual','webhook')` | NO | `manual` | — | — |
| `last_verified_at` | `datetime` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_max_chats_active` (`is_active`, `bot_id`, `id`)
- **UNIQUE** `uk_max_chat_per_bot` (`bot_id`, `chat_id`)

### Physical foreign keys

- `fk_max_chats_bot`: (`bot_id`) → `max_bots`.`id` ON UPDATE NO ACTION ON DELETE CASCADE

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
