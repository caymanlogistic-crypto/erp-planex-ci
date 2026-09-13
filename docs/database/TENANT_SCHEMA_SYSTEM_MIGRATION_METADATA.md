# ERP PLANEX — Tenant Physical Schema — System / Migration metadata

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `0bfa3a792a1876b1d6b00b02804f40432a97efe1be014414eea312c980d8e57a`

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
