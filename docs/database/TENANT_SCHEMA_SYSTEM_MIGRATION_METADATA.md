# ERP PLANEX — Tenant Physical Schema — System / Migration metadata

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `4b335615e0bc52b81307d13ce2556802709e6f311dd9e3826a68da8dff62422b`

## `schema_migrations`

**Domain:** System / Migration metadata  
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
