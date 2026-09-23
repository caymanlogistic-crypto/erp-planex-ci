# ERP PLANEX — Tenant Physical Schema — System / Migration metadata

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `49cf1e05561a1ac2d4d207fd88851c5042464f75b2e88375c53da016cb83a9bf`

## `schema_migrations`

**Domain:** System / Migration metadata<br>
**Purpose:** Applied local-migration journal and checksums.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `migration` | `varchar(255)` | NO | NULL | — | `UNI` |
| `checksum` | `varchar(64)` | NO | NULL | — | — |
| `executed_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **UNIQUE** `uk_local_migration` (`migration`)

### Physical foreign keys

- None enforced by MySQL.
