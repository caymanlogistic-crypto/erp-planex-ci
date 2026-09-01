# ERP PLANEX — Tenant Physical Schema — CRM

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `a839e09922efd5a37e40b38d2298d46e3f87110e962be10e151acec54a552477`

## `client_contacts`

**Domain:** CRM  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `client_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `contact_person` | `varchar(255)` | YES | NULL | — | — |
| `phone` | `varchar(50)` | YES | NULL | — | — |
| `email` | `varchar(255)` | YES | NULL | — | — |
| `is_primary` | `tinyint(1)` | YES | `0` | — | — |
| `is_document_email` | `tinyint(1)` | YES | `0` | — | — |
| `comment` | `text` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_client_id` (`client_id`)

### Physical foreign keys

- None enforced by MySQL.

## `clients`

**Domain:** CRM  
**Purpose:** Customer/client master records.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `name` | `varchar(255)` | NO | NULL | — | — |
| `inn` | `varchar(20)` | NO | NULL | — | `MUL` |
| `kpp` | `varchar(20)` | YES | NULL | — | — |
| `ogrn` | `varchar(20)` | YES | NULL | — | — |
| `legal_address` | `varchar(500)` | YES | NULL | — | — |
| `physical_address` | `varchar(500)` | YES | NULL | — | — |
| `status` | `varchar(20)` | NO | `active` | — | — |
| `comments` | `text` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `entity_type` | `varchar(20)` | YES | NULL | — | — |
| `director_full_name` | `varchar(255)` | YES | NULL | — | — |
| `director_position` | `varchar(255)` | YES | NULL | — | — |
| `bank_account` | `varchar(50)` | YES | NULL | — | — |
| `bank_name` | `varchar(255)` | YES | NULL | — | — |
| `bank_bik` | `varchar(20)` | YES | NULL | — | — |
| `bank_corr_account` | `varchar(50)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `deleted_at` | `timestamp` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(32)` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_inn` (`inn`)

### Physical foreign keys

- None enforced by MySQL.

## `contractor_assignment_history`

**Domain:** CRM  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `contractor_id` | `int unsigned` | NO | NULL | — | — |
| `old_logist_id` | `int unsigned` | YES | NULL | — | — |
| `new_logist_id` | `int unsigned` | NO | NULL | — | — |
| `changed_by_user_id` | `int unsigned` | NO | NULL | — | — |
| `changed_by_role` | `varchar(20)` | NO | `company_owner` | — | — |
| `changed_at` | `timestamp` | YES | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `summary_json` | `text` | YES | NULL | — | — |
| `comment` | `text` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)

### Physical foreign keys

- None enforced by MySQL.

## `contractor_contacts`

**Domain:** CRM  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `contractor_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `contact_person` | `varchar(255)` | YES | NULL | — | — |
| `phone` | `varchar(50)` | YES | NULL | — | — |
| `email` | `varchar(255)` | YES | NULL | — | — |
| `is_primary` | `tinyint(1)` | YES | `0` | — | — |
| `is_document_email` | `tinyint(1)` | YES | `0` | — | — |
| `comment` | `text` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_contractor_id` (`contractor_id`)

### Physical foreign keys

- None enforced by MySQL.

## `contractor_tax_history`

**Domain:** CRM  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `contractor_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `tax_system` | `varchar(50)` | YES | NULL | — | — |
| `vat_mode` | `varchar(50)` | YES | NULL | — | — |
| `effective_from` | `date` | YES | NULL | — | `MUL` |
| `comment` | `text` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_contractor_id` (`contractor_id`)
- **INDEX** `idx_effective_from` (`effective_from`)

### Physical foreign keys

- None enforced by MySQL.

## `contractors`

**Domain:** CRM  
**Purpose:** Carrier/contractor master records.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `name` | `varchar(255)` | NO | NULL | — | — |
| `inn` | `varchar(20)` | NO | NULL | — | `MUL` |
| `kpp` | `varchar(20)` | YES | NULL | — | — |
| `ogrn` | `varchar(20)` | YES | NULL | — | — |
| `legal_address` | `varchar(500)` | YES | NULL | — | — |
| `physical_address` | `varchar(500)` | YES | NULL | — | — |
| `status` | `varchar(20)` | NO | `active` | — | — |
| `comments` | `text` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `contractor_type` | `varchar(20)` | YES | NULL | — | — |
| `bank_account` | `varchar(50)` | YES | NULL | — | — |
| `bank_name` | `varchar(255)` | YES | NULL | — | — |
| `bank_bik` | `varchar(20)` | YES | NULL | — | — |
| `bank_corr_account` | `varchar(50)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `director_full_name` | `varchar(255)` | YES | NULL | — | — |
| `director_position` | `varchar(255)` | YES | NULL | — | — |
| `deleted_at` | `timestamp` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(32)` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_inn` (`inn`)

### Physical foreign keys

- None enforced by MySQL.

## `responsible_assignment_history`

**Domain:** CRM  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `entity_type` | `varchar(30)` | NO | NULL | — | `MUL` |
| `entity_id` | `int unsigned` | NO | NULL | — | — |
| `old_logist_id` | `int unsigned` | YES | NULL | — | — |
| `new_logist_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `changed_by_user_id` | `int unsigned` | NO | NULL | — | — |
| `changed_by_role` | `varchar(20)` | NO | `company_owner` | — | — |
| `changed_at` | `timestamp` | YES | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | `MUL` |
| `summary_json` | `text` | YES | NULL | — | — |
| `comment` | `text` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_changed_at` (`changed_at`)
- **INDEX** `idx_entity` (`entity_type`, `entity_id`)
- **INDEX** `idx_new_logist` (`new_logist_id`)

### Physical foreign keys

- None enforced by MySQL.
