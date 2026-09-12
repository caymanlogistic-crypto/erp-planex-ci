# ERP PLANEX — Tenant Physical Schema — Other tenant domain — Part 3

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `e0d0db4a0de8ca865a5c2d470df95f32173f403c15ed9f679ca37117edcb6c6b`

## `max_message_logs`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `bot_id` | `bigint unsigned` | YES | NULL | — | `MUL` |
| `chat_config_id` | `bigint unsigned` | YES | NULL | — | `MUL` |
| `external_chat_id` | `varchar(32)` | YES | NULL | — | — |
| `source` | `enum('test','manual','event')` | NO | NULL | — | — |
| `message_text` | `text` | NO | NULL | — | — |
| `message_format` | `enum('plain','markdown','html')` | NO | `plain` | — | — |
| `notify` | `tinyint(1)` | NO | `1` | — | — |
| `status` | `enum('sent','failed')` | NO | NULL | — | `MUL` |
| `max_message_id` | `varchar(128)` | YES | NULL | — | — |
| `error_message` | `varchar(2000)` | YES | NULL | — | — |
| `initiated_by_user_id` | `bigint unsigned` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | `MUL` |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `fk_max_message_logs_bot` (`bot_id`)
- **INDEX** `fk_max_message_logs_chat` (`chat_config_id`)
- **INDEX** `idx_max_message_logs_created` (`created_at`, `id`)
- **INDEX** `idx_max_message_logs_status` (`status`, `created_at`)

### Physical foreign keys

- `fk_max_message_logs_bot`: (`bot_id`) → `max_bots`.`id` ON UPDATE NO ACTION ON DELETE SET NULL
- `fk_max_message_logs_chat`: (`chat_config_id`) → `max_chats`.`id` ON UPDATE NO ACTION ON DELETE SET NULL

## `waste_trip_audit_log`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `waste_trip_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `action` | `enum('create','update','composition_correction','cancel')` | NO | NULL | — | — |
| `before_json` | `longtext` | YES | NULL | — | — |
| `after_json` | `longtext` | YES | NULL | — | — |
| `actor_user_id` | `int unsigned` | YES | NULL | — | — |
| `actor_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_waste_trip_audit_trip` (`waste_trip_id`, `id`)

### Physical foreign keys

- `fk_waste_trip_audit_trip`: (`waste_trip_id`) → `waste_trips`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT

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

## `waste_trips`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `business_id` | `bigint unsigned` | NO | NULL | — | `UNI` |
| `client_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `route_executor_id` | `int unsigned` | YES | NULL | — | `MUL` |
| `responsible_user_id` | `int unsigned` | NO | NULL | — | `MUL` |
| `destination_type` | `enum('utilizer','warehouse')` | NO | NULL | — | — |
| `utilizer_id` | `bigint unsigned` | YES | NULL | — | `MUL` |
| `status` | `enum('searching_executor','executor_assigned','in_progress','completed','cancelled')` | NO | `searching_executor` | — | `MUL` |
| `planned_start_mode` | `enum('exact','range','unspecified')` | NO | `unspecified` | — | — |
| `planned_start_date` | `date` | YES | NULL | — | `MUL` |
| `planned_start_date_from` | `date` | YES | NULL | — | — |
| `planned_start_date_to` | `date` | YES | NULL | — | — |
| `planned_finish_date` | `date` | YES | NULL | — | — |
| `actual_start_date` | `date` | YES | NULL | — | — |
| `actual_end_date` | `date` | YES | NULL | — | — |
| `comment` | `varchar(500)` | YES | NULL | — | — |
| `cancellation_reason` | `varchar(500)` | YES | NULL | — | — |
| `cancelled_at` | `datetime` | YES | NULL | — | — |
| `cancelled_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `cancelled_by_role` | `varchar(20)` | YES | NULL | — | — |
| `source_system` | `varchar(50)` | YES | NULL | — | `MUL` |
| `legacy_flight_id` | `bigint unsigned` | YES | NULL | — | — |
| `legacy_sync_error` | `varchar(1000)` | YES | NULL | — | — |
| `legacy_last_checked_at` | `datetime` | YES | NULL | — | — |
| `legacy_last_success_at` | `datetime` | YES | NULL | — | — |
| `lock_version` | `int unsigned` | NO | `1` | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_membership_id` | `bigint unsigned` | YES | NULL | — | `MUL` |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_waste_trips_client` (`client_id`, `id`)
- **INDEX** `idx_waste_trips_creator_membership` (`created_by_membership_id`)
- **INDEX** `idx_waste_trips_dates` (`planned_start_date`, `planned_start_date_from`, `planned_start_date_to`)
- **INDEX** `idx_waste_trips_executor` (`route_executor_id`, `id`)
- **INDEX** `idx_waste_trips_responsible` (`responsible_user_id`, `id`)
- **INDEX** `idx_waste_trips_status` (`status`, `id`)
- **INDEX** `idx_waste_trips_utilizer` (`utilizer_id`, `id`)
- **UNIQUE** `uk_waste_trip_business_id` (`business_id`)
- **UNIQUE** `uk_waste_trip_legacy_identity` (`source_system`, `legacy_flight_id`)

### Physical foreign keys

- `fk_waste_trips_client`: (`client_id`) → `clients`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
- `fk_waste_trips_executor`: (`route_executor_id`) → `crews`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
- `fk_waste_trips_responsible`: (`responsible_user_id`) → `users`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
- `fk_waste_trips_utilizer`: (`utilizer_id`) → `feo_utilizers`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT
