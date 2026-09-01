# ERP PLANEX — Tenant Physical Schema — Documents

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `a839e09922efd5a37e40b38d2298d46e3f87110e962be10e151acec54a552477`

## `document_template_versions`

**Domain:** Documents  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `template_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `version_no` | `int unsigned` | NO | NULL | — | — |
| `original_name` | `varchar(255)` | NO | NULL | — | — |
| `stored_path` | `varchar(1000)` | NO | NULL | — | — |
| `file_size` | `bigint unsigned` | NO | NULL | — | — |
| `sha256` | `char(64)` | NO | NULL | — | — |
| `placeholders_json` | `longtext` | NO | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(32)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_document_template_versions_template` (`template_id`, `id`)
- **UNIQUE** `uk_document_template_versions` (`template_id`, `version_no`)

### Physical foreign keys

- `fk_document_template_versions_template`: (`template_id`) → `document_templates`.`id` ON UPDATE CASCADE ON DELETE RESTRICT

## `document_templates`

**Domain:** Documents  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `name` | `varchar(255)` | NO | NULL | — | — |
| `document_type` | `varchar(64)` | NO | NULL | — | `MUL` |
| `status` | `enum('active','inactive')` | NO | `active` | — | — |
| `active_version_id` | `bigint unsigned` | YES | NULL | — | `MUL` |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(32)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(32)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_document_templates_active_version` (`active_version_id`)
- **INDEX** `idx_document_templates_type_status` (`document_type`, `status`)

### Physical foreign keys

- `fk_document_templates_active_version`: (`active_version_id`) → `document_template_versions`.`id` ON UPDATE CASCADE ON DELETE SET NULL

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

## `generated_document_requests`

**Domain:** Documents  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `generated_document_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `request_id` | `varchar(64)` | NO | NULL | — | `MUL` |
| `position` | `int unsigned` | NO | NULL | — | — |
| `snapshot_json` | `longtext` | NO | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_generated_document_requests_request` (`request_id`, `generated_document_id`)
- **UNIQUE** `uk_generated_document_request_position` (`generated_document_id`, `position`)

### Physical foreign keys

- `fk_generated_document_requests_document`: (`generated_document_id`) → `generated_documents`.`id` ON UPDATE CASCADE ON DELETE CASCADE

## `generated_documents`

**Domain:** Documents  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `document_type` | `varchar(64)` | NO | NULL | — | `MUL` |
| `document_number` | `varchar(255)` | YES | NULL | — | — |
| `document_date` | `date` | YES | NULL | — | — |
| `template_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `template_version_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `route_executor_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `request_ids_json` | `longtext` | NO | NULL | — | — |
| `total_mass_kg` | `decimal(16,3)` | NO | `0.000` | — | — |
| `unload_address` | `text` | YES | NULL | — | — |
| `parameters_json` | `longtext` | NO | NULL | — | — |
| `snapshot_json` | `longtext` | NO | NULL | — | — |
| `stored_path` | `varchar(1000)` | NO | NULL | — | — |
| `original_name` | `varchar(255)` | NO | NULL | — | — |
| `sha256` | `char(64)` | NO | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(32)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | `MUL` |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `fk_generated_documents_template` (`template_id`)
- **INDEX** `idx_generated_documents_created` (`created_at`, `id`)
- **INDEX** `idx_generated_documents_executor` (`route_executor_id`, `id`)
- **INDEX** `idx_generated_documents_template_version` (`template_version_id`, `id`)
- **INDEX** `idx_generated_documents_type_date` (`document_type`, `document_date`)

### Physical foreign keys

- `fk_generated_documents_route_executor`: (`route_executor_id`) → `crews`.`id` ON UPDATE CASCADE ON DELETE RESTRICT
- `fk_generated_documents_template`: (`template_id`) → `document_templates`.`id` ON UPDATE CASCADE ON DELETE RESTRICT
- `fk_generated_documents_template_version`: (`template_version_id`) → `document_template_versions`.`id` ON UPDATE CASCADE ON DELETE RESTRICT
