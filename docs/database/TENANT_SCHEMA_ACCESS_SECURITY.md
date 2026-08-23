# ERP PLANEX — Tenant Physical Schema — Access / Security

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `ff6c294f140a67e18ef0bd111c5527cf60c6e892c2e7863c7e7edda32667d681`

## `entity_access_grants`

**Domain:** Access / Security  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `entity_type` | `varchar(50)` | NO | NULL | — | `MUL` |
| `entity_id` | `int unsigned` | NO | NULL | — | — |
| `granted_to_user_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `granted_by_user_id` | `int unsigned` | NO | NULL | — | — |
| `access_level` | `varchar(20)` | NO | `view` | — | — |
| `created_at` | `timestamp` | YES | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `comment` | `text` | YES | NULL | — | — |
| `revoked_at` | `timestamp` | YES | NULL | — | — |
| `revoked_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_at` | `timestamp` | YES | NULL | — | — |
| `grant_source` | `varchar(50)` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_granted_user` (`granted_to_user_id`)
- **UNIQUE** `uk_grant` (`entity_type`, `entity_id`, `granted_to_user_id`)

### Physical foreign keys

- None enforced by MySQL.

## `users`

**Domain:** Access / Security  
**Purpose:** Tenant company users.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `full_name` | `varchar(255)` | NO | NULL | — | — |
| `login` | `varchar(100)` | NO | NULL | — | `UNI` |
| `email` | `varchar(255)` | YES | NULL | — | — |
| `phone` | `varchar(50)` | YES | NULL | — | — |
| `position` | `varchar(255)` | YES | NULL | — | — |
| `password_hash` | `varchar(255)` | NO | NULL | — | — |
| `role_code` | `varchar(50)` | NO | `logist` | — | — |
| `status` | `varchar(20)` | NO | `active` | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `deleted_at` | `timestamp` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(32)` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **UNIQUE** `uk_login` (`login`)

### Physical foreign keys

- None enforced by MySQL.
