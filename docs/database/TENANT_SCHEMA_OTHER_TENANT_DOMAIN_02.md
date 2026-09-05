# ERP PLANEX — Tenant Physical Schema — Other tenant domain — Part 2

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `dc8329459731680881b7dbdcddb300b6943cf230bc8e17e98c5f1936aea85f41`

## `feo_requests`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `zayavka_id` | `varchar(64)` | NO | NULL | — | `UNI` |
| `data_zayavki` | `date` | YES | NULL | — | `MUL` |
| `polnoe_naim_zakazchika` | `text` | YES | NULL | — | — |
| `ur_adres_zakazchika` | `text` | YES | NULL | — | — |
| `inn_zakazchika` | `varchar(32)` | YES | NULL | — | — |
| `nomer_dogovora` | `varchar(100)` | YES | NULL | — | — |
| `data_dogovora` | `date` | YES | NULL | — | — |
| `gruzopoluchatel` | `text` | YES | NULL | — | — |
| `inn_op_gruzopoluchatelya` | `varchar(32)` | YES | NULL | — | — |
| `adres_dostavki_otkhodov` | `text` | YES | NULL | — | — |
| `ur_adres_op_gruzopoluchatelya` | `text` | YES | NULL | — | — |
| `inn_oo_gruzootpravitel` | `varchar(32)` | YES | NULL | — | — |
| `naim_oo_gruzootpravitel` | `text` | YES | NULL | — | — |
| `ur_adres_oo_gruzootpravitelya` | `text` | YES | NULL | — | — |
| `mno_region` | `varchar(255)` | YES | NULL | — | `MUL` |
| `mno_mo` | `varchar(255)` | YES | NULL | — | — |
| `mno_adres_pogruzki` | `text` | YES | NULL | — | — |
| `naim_otkhoda_fkko` | `text` | YES | NULL | — | — |
| `kol_vo_mest` | `int` | YES | NULL | — | — |
| `vid_tary` | `varchar(255)` | YES | NULL | — | — |
| `mass_netto` | `decimal(14,4)` | YES | NULL | — | — |
| `mass_brutto` | `decimal(14,4)` | YES | NULL | — | — |
| `dlina_mesta` | `decimal(14,4)` | YES | NULL | — | — |
| `shirina_mesta` | `decimal(14,4)` | YES | NULL | — | — |
| `vysota_mesta` | `decimal(14,4)` | YES | NULL | — | — |
| `summarnyy_obem` | `decimal(14,4)` | YES | NULL | — | — |
| `nomer_opasnogo_gr` | `varchar(64)` | YES | NULL | — | — |
| `adres_vygruzki_otkhodov` | `text` | YES | NULL | — | — |
| `naim_ot_gruzoperevozchik` | `text` | YES | NULL | — | — |
| `inn_ot` | `varchar(32)` | YES | NULL | — | — |
| `ur_adres_ot` | `text` | YES | NULL | — | — |
| `data_sozdaniya_porucheniya` | `date` | YES | NULL | — | — |
| `zakaz_s_ot_status` | `varchar(255)` | YES | NULL | — | — |
| `nalichie_transport_nakl` | `varchar(64)` | YES | NULL | — | — |
| `zakaz_s_op_status` | `varchar(255)` | YES | NULL | — | — |
| `id_zakaza_s_op` | `varchar(64)` | YES | NULL | — | — |
| `zakaz_s_ot_data_vygruzki` | `date` | YES | NULL | — | — |
| `zayavka_zhdv_po` | `date` | YES | NULL | — | `MUL` |
| `kontakt_tel` | `varchar(255)` | YES | NULL | — | — |
| `tel_dopolnitelnyy` | `varchar(255)` | YES | NULL | — | — |
| `kontakt_email` | `varchar(255)` | YES | NULL | — | — |
| `mno_sh` | `varchar(64)` | YES | NULL | — | — |
| `mno_d` | `varchar(64)` | YES | NULL | — | — |
| `zakaz_s_op_stoimost` | `decimal(14,2)` | YES | NULL | — | — |
| `current_import_id` | `bigint unsigned` | YES | NULL | — | `MUL` |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_feo_requests_current_import` (`current_import_id`)
- **INDEX** `idx_feo_requests_date` (`data_zayavki`)
- **INDEX** `idx_feo_requests_region` (`mno_region`)
- **INDEX** `idx_feo_requests_zhdv` (`zayavka_zhdv_po`)
- **UNIQUE** `uk_feo_requests_zayavka_id` (`zayavka_id`)

### Physical foreign keys

- `fk_feo_requests_current_import`: (`current_import_id`) → `feo_imports`.`id` ON UPDATE NO ACTION ON DELETE SET NULL

## `feo_utilizer_aliases`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `utilizer_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `alias` | `varchar(500)` | NO | NULL | — | — |
| `normalized_alias` | `varchar(500)` | NO | NULL | — | `UNI` |
| `created_by_user_id` | `bigint unsigned` | YES | NULL | — | — |
| `updated_by_user_id` | `bigint unsigned` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_feo_utilizer_alias_utilizer` (`utilizer_id`)
- **UNIQUE** `uk_feo_utilizer_alias_normalized` (`normalized_alias`)

### Physical foreign keys

- `fk_feo_utilizer_alias_utilizer`: (`utilizer_id`) → `feo_utilizers`.`id` ON UPDATE NO ACTION ON DELETE CASCADE

## `feo_utilizer_match_rules`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `utilizer_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `source_field` | `enum('recipient','delivery_address')` | NO | NULL | — | `MUL` |
| `match_type` | `enum('exact','contains')` | NO | NULL | — | — |
| `pattern` | `varchar(500)` | NO | NULL | — | — |
| `normalized_pattern` | `varchar(500)` | NO | NULL | — | — |
| `priority` | `int` | NO | `100` | — | — |
| `is_active` | `tinyint(1)` | NO | `1` | — | `MUL` |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_feo_utilizer_rule_match` (`is_active`, `source_field`, `priority`, `id`)
- **INDEX** `idx_feo_utilizer_rule_utilizer` (`utilizer_id`, `is_active`, `priority`, `id`)
- **UNIQUE** `uk_feo_utilizer_rule_canonical` (`source_field`, `match_type`, `normalized_pattern`)

### Physical foreign keys

- `fk_feo_utilizer_rule_utilizer`: (`utilizer_id`) → `feo_utilizers`.`id` ON UPDATE NO ACTION ON DELETE RESTRICT

## `feo_utilizers`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `full_name` | `varchar(255)` | NO | NULL | — | — |
| `short_name` | `varchar(80)` | NO | NULL | — | `UNI` |
| `address` | `varchar(500)` | YES | NULL | — | — |
| `latitude` | `decimal(10,7)` | YES | NULL | — | — |
| `longitude` | `decimal(10,7)` | YES | NULL | — | — |
| `fkko_classification` | `varchar(500)` | YES | NULL | — | — |
| `adr` | `tinyint(1)` | NO | `0` | — | — |
| `color_hex` | `char(7)` | NO | `#2563EB` | — | — |
| `is_active` | `tinyint(1)` | NO | `1` | — | `MUL` |
| `sort_order` | `int` | NO | `100` | — | — |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `updated_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_feo_utilizers_active_order` (`is_active`, `sort_order`, `id`)
- **UNIQUE** `uk_feo_utilizers_short_name` (`short_name`)

### Physical foreign keys

- None enforced by MySQL.

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
