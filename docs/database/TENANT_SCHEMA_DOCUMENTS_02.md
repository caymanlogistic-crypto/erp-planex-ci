# ERP PLANEX — Tenant Physical Schema — Documents — Part 2

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `368b5cf1642f5ddd8c7f2d659445de03353b30370001e5a2113967358becc632`

## `trip_document_incoming`

**Domain:** Documents<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `request_id` | `bigint unsigned` | YES | NULL | — | `MUL` |
| `candidate_request_ids` | `text` | NO | NULL | — | — |
| `identity_hash` | `char(64)` | NO | NULL | — | `UNI` |
| `message_id` | `varchar(254)` | NO | NULL | — | — |
| `sender` | `varchar(500)` | NO | NULL | — | — |
| `subject` | `varchar(1000)` | NO | NULL | — | — |
| `body_text` | `mediumtext` | NO | NULL | — | — |
| `attachments_json` | `longtext` | NO | NULL | — | — |
| `received_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `reviewed_at` | `datetime` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_document_incoming_request` (`request_id`, `id`)
- **UNIQUE** `uq_document_incoming_identity` (`identity_hash`)

### Physical foreign keys

- `fk_document_incoming_request`: (`request_id`) → `trip_document_requests`.`id` ON UPDATE NO ACTION ON DELETE NO ACTION

## `trip_document_mail`

**Domain:** Documents<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `request_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `operation_key` | `varchar(100)` | NO | NULL | — | — |
| `kind` | `enum('request','reminder','minimum','full')` | NO | NULL | — | — |
| `message_id` | `varchar(254)` | NO | NULL | — | `UNI` |
| `actor_membership_id` | `bigint unsigned` | NO | NULL | — | — |
| `sender_profile_id` | `bigint unsigned` | NO | NULL | — | — |
| `snapshot_json` | `longtext` | NO | NULL | — | — |
| `snapshot_hash` | `char(64)` | NO | NULL | — | — |
| `status` | `enum('pending','sending','accepted','failed','unknown','stale')` | NO | `pending` | — | `MUL` |
| `error_message` | `varchar(500)` | YES | NULL | — | — |
| `attempts` | `int unsigned` | NO | `0` | — | — |
| `attempted_at` | `datetime` | YES | NULL | — | — |
| `accepted_at` | `datetime` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_document_mail_pending` (`status`, `id`)
- **UNIQUE** `uq_document_mail_message` (`message_id`)
- **UNIQUE** `uq_document_mail_operation` (`request_id`, `operation_key`)

### Physical foreign keys

- `fk_document_mail_request`: (`request_id`) → `trip_document_requests`.`id` ON UPDATE NO ACTION ON DELETE NO ACTION

## `trip_document_mailboxes`

**Domain:** Documents<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `profile_id` | `bigint unsigned` | NO | NULL | — | `PRI` |
| `account_id` | `bigint unsigned` | NO | NULL | — | — |
| `legal_entity_id` | `int unsigned` | NO | NULL | — | — |
| `email` | `varchar(254)` | NO | NULL | — | — |
| `uidvalidity` | `bigint unsigned` | YES | NULL | — | — |
| `last_uid` | `bigint unsigned` | YES | NULL | — | — |
| `last_checked_at` | `datetime` | YES | NULL | — | — |
| `error_message` | `varchar(500)` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`profile_id`)

### Physical foreign keys

- None enforced by MySQL.

## `trip_document_previews`

**Domain:** Documents<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `token` | `char(48)` | NO | NULL | — | `PRI` |
| `membership_id` | `bigint unsigned` | NO | NULL | — | — |
| `request_id` | `bigint unsigned` | YES | NULL | — | — |
| `kind` | `enum('request','reminder','minimum','full')` | NO | NULL | — | — |
| `input_json` | `longtext` | NO | NULL | — | — |
| `snapshot_json` | `longtext` | NO | NULL | — | — |
| `snapshot_hash` | `char(64)` | NO | NULL | — | — |
| `expires_at` | `datetime` | NO | NULL | — | `MUL` |
| `consumed_mail_id` | `bigint unsigned` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`token`)
- **INDEX** `idx_document_preview_expiry` (`expires_at`)

### Physical foreign keys

- None enforced by MySQL.

## `trip_document_requests`

**Domain:** Documents<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `company_id` | `int unsigned` | NO | NULL | — | — |
| `request_token` | `char(32)` | NO | NULL | — | `UNI` |
| `trip_type` | `enum('waste')` | NO | NULL | — | `MUL` |
| `trip_id` | `bigint unsigned` | NO | NULL | — | — |
| `legal_entity_id` | `int unsigned` | NO | NULL | — | — |
| `initiator_membership_id` | `bigint unsigned` | NO | NULL | — | — |
| `responsible_membership_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `assistant_membership_id` | `bigint unsigned` | NO | NULL | — | — |
| `assistant_account_id` | `bigint unsigned` | NO | NULL | — | — |
| `responsible_account_id` | `bigint unsigned` | NO | NULL | — | — |
| `sender_profile_id` | `bigint unsigned` | NO | NULL | — | — |
| `sender_email` | `varchar(254)` | NO | NULL | — | — |
| `responsible_email` | `varchar(254)` | NO | NULL | — | — |
| `contact_name` | `varchar(255)` | NO | NULL | — | — |
| `contact_email` | `varchar(254)` | NO | NULL | — | — |
| `contact_phone` | `varchar(64)` | NO | NULL | — | — |
| `promised_at` | `datetime` | YES | NULL | — | — |
| `next_check_at` | `datetime` | YES | NULL | — | — |
| `next_action` | `enum('send','wait','review','collect','remind','mail_error','complete')` | NO | `send` | — | `MUL` |
| `response_received_at` | `datetime` | YES | NULL | — | — |
| `package_stage` | `enum('none','minimum','full')` | NO | `none` | — | — |
| `version` | `int unsigned` | NO | `1` | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_document_request_due` (`next_action`, `next_check_at`)
- **INDEX** `idx_document_request_owner` (`responsible_membership_id`, `id`)
- **UNIQUE** `uq_document_request_token` (`request_token`)
- **UNIQUE** `uq_document_request_trip` (`trip_type`, `trip_id`)

### Physical foreign keys

- None enforced by MySQL.

## `waste_trip_closing_documents`

**Domain:** Documents<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `waste_trip_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `document_id` | `int unsigned` | NO | NULL | — | `UNI` |
| `slot_code` | `varchar(24)` | NO | NULL | — | — |
| `requirement_code` | `varchar(32)` | NO | NULL | — | — |
| `custom_title` | `varchar(255)` | YES | NULL | — | — |
| `deleted_at` | `datetime` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_wtcd_trip` (`waste_trip_id`, `deleted_at`, `id`)
- **UNIQUE** `uq_wtcd_document` (`document_id`)

### Physical foreign keys

- `fk_wtcd_document`: (`document_id`) → `documents`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
- `fk_wtcd_trip`: (`waste_trip_id`) → `waste_trips`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT

## `waste_trip_completion_documents`

**Domain:** Documents<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `waste_trip_request_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `original_name` | `varchar(255)` | NO | NULL | — | — |
| `download_name` | `varchar(255)` | NO | NULL | — | — |
| `stored_path` | `varchar(500)` | NO | NULL | — | `UNI` |
| `mime_type` | `varchar(127)` | NO | NULL | — | — |
| `size_bytes` | `bigint unsigned` | NO | NULL | — | — |
| `uploaded_by_membership_id` | `bigint unsigned` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `deleted_at` | `datetime` | YES | NULL | — | — |
| `deleted_by_membership_id` | `bigint unsigned` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_waste_trip_completion_documents_request` (`waste_trip_request_id`, `deleted_at`, `id`)
- **UNIQUE** `uk_waste_trip_completion_stored_path` (`stored_path`)

### Physical foreign keys

- `fk_waste_trip_completion_document_request`: (`waste_trip_request_id`) → `waste_trip_requests`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
