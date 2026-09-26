# ERP PLANEX — Tenant Physical Schema — Other tenant domain — Part 4

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `1344ab2773eb6f5736e153c18e3ffc83532742b75fb5e0dba2336dafa48948d8`

## `waste_trip_completion_items`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `waste_trip_request_id` | `bigint unsigned` | NO | NULL | — | `PRI` |
| `arrived_at` | `datetime` | YES | NULL | — | — |
| `departed_at` | `datetime` | YES | NULL | — | — |
| `driver_signature_present` | `tinyint(1)` | NO | `0` | — | — |
| `driver_signature_status` | `varchar(3)` | YES | NULL | — | — |
| `sender_signature_present` | `tinyint(1)` | NO | `0` | — | — |
| `sender_signature_status` | `varchar(3)` | YES | NULL | — | — |
| `tn_datetime_missing` | `tinyint(1)` | NO | `0` | — | — |
| `tn_datetime_status` | `varchar(7)` | YES | NULL | — | — |
| `tn_absent` | `tinyint(1)` | NO | `0` | — | — |
| `data_from_tracker` | `tinyint(1)` | NO | `0` | — | — |
| `data_from_driver` | `tinyint(1)` | NO | `0` | — | — |
| `data_invented` | `tinyint(1)` | NO | `0` | — | — |
| `recovery_source` | `varchar(8)` | YES | NULL | — | — |
| `comment` | `varchar(500)` | YES | NULL | — | — |
| `updated_by_membership_id` | `bigint unsigned` | YES | NULL | — | — |
| `lock_version` | `int unsigned` | NO | `1` | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | `MUL` |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`waste_trip_request_id`)
- **INDEX** `idx_waste_trip_completion_updated` (`updated_at`, `waste_trip_request_id`)

### Physical foreign keys

- `fk_waste_trip_completion_request`: (`waste_trip_request_id`) → `waste_trip_requests`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT

## `waste_trip_customer_payments`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `waste_trip_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `sort_order` | `int unsigned` | NO | `1` | — | — |
| `amount` | `decimal(12,2)` | YES | NULL | — | — |
| `payment_type` | `varchar(50)` | NO | NULL | — | — |
| `payment_method` | `varchar(20)` | NO | `cashless` | — | — |
| `vat_rate` | `decimal(5,2)` | YES | NULL | — | — |
| `condition_type` | `varchar(50)` | NO | NULL | — | — |
| `days_count` | `int` | YES | NULL | — | — |
| `days_kind` | `varchar(10)` | YES | NULL | — | — |
| `specific_due_date` | `date` | YES | NULL | — | — |
| `condition_comment` | `text` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_waste_trip_customer_payments_trip` (`waste_trip_id`, `sort_order`, `id`)

### Physical foreign keys

- `fk_waste_trip_customer_payments_trip`: (`waste_trip_id`) → `waste_trips`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT

## `waste_trip_general_payments`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `waste_trip_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `sort_order` | `int unsigned` | NO | `1` | — | — |
| `amount` | `decimal(12,2)` | YES | NULL | — | — |
| `payment_type` | `varchar(50)` | NO | NULL | — | — |
| `payment_method` | `varchar(20)` | NO | `cashless` | — | — |
| `vat_rate` | `decimal(5,2)` | YES | NULL | — | — |
| `condition_type` | `varchar(50)` | NO | NULL | — | — |
| `days_count` | `int` | YES | NULL | — | — |
| `days_kind` | `varchar(10)` | YES | NULL | — | — |
| `specific_due_date` | `date` | YES | NULL | — | — |
| `condition_comment` | `text` | YES | NULL | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_waste_trip_general_payments_trip` (`waste_trip_id`, `sort_order`, `id`)

### Physical foreign keys

- `fk_waste_trip_general_payments_trip`: (`waste_trip_id`) → `waste_trips`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT

## `waste_trip_namespace_state`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `singleton_id` | `tinyint unsigned` | NO | NULL | — | `PRI` |
| `legacy_high_water_at_cutover` | `bigint unsigned` | NO | NULL | — | — |
| `last_issued_business_id` | `bigint unsigned` | NO | NULL | — | — |
| `cutover_started_at` | `datetime` | YES | NULL | — | — |
| `cutover_completed_at` | `datetime` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`singleton_id`)

### Physical foreign keys

- None enforced by MySQL.

## `waste_trip_registration_journal`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `waste_trip_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `waste_trip_request_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `document_id` | `bigint unsigned` | YES | NULL | — | — |
| `event_kind` | `varchar(32)` | NO | NULL | — | — |
| `action_text` | `varchar(255)` | NO | NULL | — | — |
| `actor_membership_id` | `bigint unsigned` | YES | NULL | — | — |
| `actor_login` | `varchar(100)` | NO | NULL | — | `MUL` |
| `before_json` | `json` | YES | NULL | — | — |
| `after_json` | `json` | YES | NULL | — | — |
| `initial_key` | `varchar(80)` | YES | NULL | — | `UNI` |
| `occurred_at` | `datetime(6)` | NO | `CURRENT_TIMESTAMP(6)` | `DEFAULT_GENERATED` | `MUL` |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `fk_registration_journal_request` (`waste_trip_request_id`)
- **INDEX** `idx_registration_journal_actor` (`actor_login`, `occurred_at`)
- **INDEX** `idx_registration_journal_time` (`occurred_at`, `id`)
- **INDEX** `idx_registration_journal_trip` (`waste_trip_id`, `waste_trip_request_id`, `id`)
- **UNIQUE** `uk_registration_journal_initial` (`initial_key`)

### Physical foreign keys

- `fk_registration_journal_request`: (`waste_trip_request_id`) → `waste_trip_requests`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
- `fk_registration_journal_trip`: (`waste_trip_id`) → `waste_trips`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT

## `waste_trip_requests`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `waste_trip_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `feo_request_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `snapshot_mass_kg` | `decimal(18,3)` | NO | NULL | — | — |
| `position_no` | `int unsigned` | NO | `0` | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_waste_trip_requests_position` (`waste_trip_id`, `position_no`, `id`)
- **INDEX** `idx_waste_trip_requests_request` (`feo_request_id`, `waste_trip_id`)
- **UNIQUE** `uk_waste_trip_request` (`waste_trip_id`, `feo_request_id`)

### Physical foreign keys

- `fk_waste_trip_requests_feo`: (`feo_request_id`) → `feo_requests`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
- `fk_waste_trip_requests_trip`: (`waste_trip_id`) → `waste_trips`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT

## `waste_trip_start_statistics`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `waste_trip_id` | `bigint unsigned` | NO | NULL | — | `PRI` |
| `actual_start_date` | `date` | NO | NULL | — | `MUL` |
| `responsible_user_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `responsible_membership_id` | `bigint unsigned` | YES | NULL | — | `MUL` |
| `responsible_name_snapshot` | `varchar(255)` | NO | NULL | — | — |
| `request_count` | `int unsigned` | NO | `0` | — | — |
| `total_mass_kg` | `decimal(18,3)` | NO | `0.000` | — | — |
| `is_backfilled` | `tinyint(1)` | NO | `0` | — | — |
| `captured_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`waste_trip_id`)
- **INDEX** `fk_waste_trip_start_stats_user` (`responsible_user_id`)
- **INDEX** `idx_waste_trip_start_stats_date` (`actual_start_date`, `waste_trip_id`)
- **INDEX** `idx_waste_trip_start_stats_manager` (`responsible_membership_id`, `actual_start_date`, `waste_trip_id`)

### Physical foreign keys

- `fk_waste_trip_start_stats_trip`: (`waste_trip_id`) → `waste_trips`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
- `fk_waste_trip_start_stats_user`: (`responsible_user_id`) → `users`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
