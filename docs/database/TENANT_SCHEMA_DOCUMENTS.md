# ERP PLANEX — Tenant Physical Schema — Documents

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `bd1fc59161646ee1cfca98392e922d017ffd14833b0a73769641f6b66fc18989`

## `document_types`

**Domain:** Documents  
**Purpose:** Document type catalog.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `name` | `varchar(200)` | NO | NULL | — | `MUL` |
| `code` | `varchar(50)` | YES | NULL | — | — |
| `entity_type` | `varchar(20)` | YES | NULL | — | `MUL` |
| `category` | `varchar(20)` | NO | `custom` | — | `MUL` |
| `sort_order` | `int` | NO | `0` | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |
| `deleted_at` | `timestamp` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(32)` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_category` (`category`)
- **INDEX** `idx_entity_type` (`entity_type`)
- **UNIQUE** `uk_name_entity_type` (`name`, `entity_type`)

### Physical foreign keys

- None enforced by MySQL.

## `documents`

**Domain:** Documents  
**Purpose:** Tenant document metadata.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `entity_type` | `varchar(20)` | NO | NULL | — | `MUL` |
| `entity_id` | `int unsigned` | NO | NULL | — | — |
| `document_type` | `varchar(100)` | YES | NULL | — | — |
| `document_type_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `original_name` | `varchar(500)` | NO | NULL | — | — |
| `stored_name` | `varchar(255)` | NO | NULL | — | — |
| `relative_path` | `varchar(1000)` | NO | NULL | — | — |
| `mime_type` | `varchar(100)` | NO | NULL | — | — |
| `file_size` | `bigint unsigned` | NO | NULL | — | — |
| `status` | `varchar(20)` | NO | `uploaded` | — | `MUL` |
| `uploaded_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `uploaded_by_role` | `varchar(50)` | YES | NULL | — | — |
| `comments` | `text` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | `MUL` |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `document_name` | `varchar(500)` | YES | NULL | — | — |
| `document_number` | `varchar(100)` | YES | NULL | — | — |
| `document_date` | `date` | YES | NULL | — | — |
| `document_expire` | `date` | YES | NULL | — | — |
| `deleted_at` | `timestamp` | YES | NULL | — | `MUL` |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `delete_comment` | `text` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(32)` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_created` (`created_at`)
- **INDEX** `idx_deleted` (`deleted_at`)
- **INDEX** `idx_document_type_id` (`document_type_id`)
- **INDEX** `idx_entity` (`entity_type`, `entity_id`)
- **INDEX** `idx_status` (`status`)

### Physical foreign keys

- None enforced by MySQL.
