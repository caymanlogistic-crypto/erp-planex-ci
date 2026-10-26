# ERP PLANEX — Tenant Physical Schema — Access / Security

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `dc181daddd43c45a02a9c0ae080db7163780e4bad54d6fc805222d2db1a1b57a`

## `entity_access_grants`

**Domain:** Access / Security<br>
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

## `legacy_access_projections`

**Domain:** Access / Security<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `legacy_grant_id` | `bigint unsigned` | NO | NULL | — | `UNI` |
| `entity_type` | `enum('linear_trip','vehicle_set')` | NO | NULL | — | `MUL` |
| `entity_id` | `bigint unsigned` | NO | NULL | — | — |
| `membership_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `access_level` | `enum('view','edit')` | NO | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_legacy_access_projection_membership` (`membership_id`, `entity_type`, `entity_id`)
- **UNIQUE** `uk_legacy_access_projection_grant` (`legacy_grant_id`)
- **UNIQUE** `uk_legacy_access_projection_target` (`entity_type`, `entity_id`, `membership_id`)

### Physical foreign keys

- None enforced by MySQL.

## `users`

**Domain:** Access / Security<br>
**Purpose:** Tenant company users.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `membership_id` | `bigint unsigned` | YES | NULL | — | `UNI` |
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
- **UNIQUE** `uk_users_membership_id` (`membership_id`)

### Physical foreign keys

- None enforced by MySQL.
