# ERP PLANEX — Tenant Physical Schema — Operations / Routes — Part 1

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `368b5cf1642f5ddd8c7f2d659445de03353b30370001e5a2113967358becc632`

## `cargo_types`

**Domain:** Operations / Routes<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `name` | `varchar(191)` | NO | NULL | — | — |
| `normalized_name` | `varchar(191)` | NO | NULL | — | `UNI` |
| `usage_count` | `int unsigned` | NO | `0` | — | — |
| `status` | `varchar(20)` | NO | `active` | — | `MUL` |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `deleted_at` | `datetime` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_cargo_types_created_by` (`created_by_user_id`)
- **INDEX** `idx_cargo_types_status` (`status`)
- **UNIQUE** `uk_cargo_types_normalized_name` (`normalized_name`)

### Physical foreign keys

- None enforced by MySQL.

## `linear_route_financial_terms`

**Domain:** Operations / Routes<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `linear_route_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `party_role` | `varchar(20)` | NO | NULL | — | `MUL` |
| `amount` | `decimal(12,2)` | NO | `0.00` | — | — |
| `payment_type` | `varchar(50)` | NO | NULL | — | — |
| `payment_due_type` | `varchar(100)` | YES | NULL | — | — |
| `payment_due_days` | `int` | YES | NULL | — | — |
| `payment_due_days_kind` | `varchar(20)` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `deleted_at` | `datetime` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_linear_route_financial_terms_party` (`party_role`)
- **INDEX** `idx_linear_route_financial_terms_route` (`linear_route_id`)
- **UNIQUE** `uk_linear_route_party_role` (`linear_route_id`, `party_role`)

### Physical foreign keys

- None enforced by MySQL.

## `linear_route_payments`

**Domain:** Operations / Routes<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `linear_route_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `party_role` | `varchar(20)` | NO | NULL | — | `MUL` |
| `linear_route_principal_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `sort_order` | `int unsigned` | NO | `1` | — | — |
| `legacy_financial_term_id` | `int unsigned` | YES | NULL | — | `UNI` |
| `amount` | `decimal(12,2)` | NO | `0.00` | — | — |
| `payment_type` | `varchar(50)` | NO | NULL | — | — |
| `payment_method` | `varchar(20)` | YES | NULL | — | — |
| `vat_rate` | `decimal(5,2)` | YES | NULL | — | — |
| `paid_amount` | `decimal(12,2)` | NO | `0.00` | — | — |
| `payment_status` | `varchar(30)` | NO | `unpaid` | — | `MUL` |
| `calculated_due_date` | `date` | YES | NULL | — | `MUL` |
| `paid_at` | `date` | YES | NULL | — | — |
| `status_updated_at` | `datetime` | YES | NULL | — | — |
| `payment_due_type` | `varchar(100)` | YES | NULL | — | — |
| `payment_due_days` | `int` | YES | NULL | — | — |
| `payment_due_days_kind` | `varchar(20)` | YES | NULL | — | — |
| `condition_type` | `varchar(50)` | YES | NULL | — | `MUL` |
| `side` | `varchar(10)` | YES | NULL | — | `MUL` |
| `days_count` | `int` | YES | NULL | — | — |
| `days_kind` | `varchar(10)` | YES | `calendar` | — | — |
| `specific_due_date` | `date` | YES | NULL | — | — |
| `condition_comment` | `text` | YES | NULL | — | — |
| `forecast_due_date` | `date` | YES | NULL | — | `MUL` |
| `first_paid_at` | `datetime` | YES | NULL | — | — |
| `fully_paid_at` | `datetime` | YES | NULL | — | — |
| `cancelled_at` | `datetime` | YES | NULL | — | — |
| `cancellation_reason` | `text` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `deleted_at` | `datetime` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_linear_route_payments_party` (`party_role`)
- **INDEX** `idx_linear_route_payments_principal` (`linear_route_principal_id`)
- **INDEX** `idx_linear_route_payments_route` (`linear_route_id`)
- **INDEX** `idx_lrp_calculated_due_date` (`calculated_due_date`)
- **INDEX** `idx_lrp_condition_type` (`condition_type`)
- **INDEX** `idx_lrp_forecast_due_date` (`forecast_due_date`)
- **INDEX** `idx_lrp_payment_status` (`payment_status`)
- **INDEX** `idx_lrp_side` (`side`)
- **UNIQUE** `uk_linear_route_payments_legacy_term` (`legacy_financial_term_id`)

### Physical foreign keys

- None enforced by MySQL.

## `linear_route_points`

**Domain:** Operations / Routes<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `linear_route_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `point_type` | `enum('loading','unloading')` | NO | NULL | — | — |
| `sort_order` | `int unsigned` | NO | `1` | — | — |
| `address_text` | `varchar(500)` | NO | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |
| `deleted_at` | `timestamp` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_linear_route_points_route` (`linear_route_id`, `point_type`, `sort_order`)

### Physical foreign keys

- `fk_linear_route_points_route`: (`linear_route_id`) → `linear_routes`.`id` ON UPDATE NO ACTION ON DELETE CASCADE

## `linear_route_principals`

**Domain:** Operations / Routes<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `linear_route_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `principal_type` | `varchar(20)` | NO | NULL | — | `MUL` |
| `principal_id` | `int unsigned` | NO | NULL | — | — |
| `sort_order` | `int unsigned` | NO | `1` | — | — |
| `status` | `varchar(20)` | NO | `active` | — | `MUL` |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `deleted_at` | `datetime` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_linear_route_principals_entity` (`principal_type`, `principal_id`)
- **INDEX** `idx_linear_route_principals_route` (`linear_route_id`)
- **INDEX** `idx_linear_route_principals_status` (`status`)

### Physical foreign keys

- None enforced by MySQL.

## `linear_route_service_payments`

**Domain:** Operations / Routes<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `int unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `linear_route_service_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `amount` | `decimal(15,2)` | NO | NULL | — | — |
| `payment_type` | `varchar(100)` | YES | NULL | — | — |
| `payment_method` | `varchar(20)` | NO | `cashless` | — | — |
| `vat_rate` | `varchar(10)` | YES | NULL | — | — |
| `condition_type` | `varchar(100)` | NO | NULL | — | — |
| `days_count` | `int unsigned` | YES | NULL | — | — |
| `days_kind` | `varchar(20)` | YES | NULL | — | — |
| `specific_due_date` | `date` | YES | NULL | — | — |
| `condition_comment` | `varchar(500)` | YES | NULL | — | — |
| `calculated_due_date` | `date` | YES | NULL | — | — |
| `paid_amount` | `decimal(15,2)` | NO | `0.00` | — | — |
| `status` | `varchar(40)` | NO | `planned` | — | — |
| `sort_order` | `int unsigned` | NO | `0` | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(40)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(40)` | YES | NULL | — | — |
| `created_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |
| `deleted_at` | `datetime` | YES | NULL | — | — |
| `deleted_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `deleted_by_role` | `varchar(40)` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_linear_route_service_payments_service` (`linear_route_service_id`, `deleted_at`, `sort_order`, `id`)

### Physical foreign keys

- None enforced by MySQL.
