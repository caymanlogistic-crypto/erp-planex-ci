# ERP PLANEX — Tenant Physical Schema — Other tenant domain — Part 2

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `e8e789dd44ae480a649243a3480605b0a9d5f96983ef15883dad4e406731f1ee`

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

## `max_bots`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `name` | `varchar(120)` | NO | NULL | — | — |
| `token_encrypted` | `mediumtext` | NO | NULL | — | — |
| `bot_user_id` | `varchar(32)` | YES | NULL | — | — |
| `bot_first_name` | `varchar(255)` | YES | NULL | — | — |
| `bot_username` | `varchar(255)` | YES | NULL | — | — |
| `webhook_secret_encrypted` | `mediumtext` | NO | NULL | — | — |
| `webhook_status` | `enum('not_configured','active','error')` | NO | `not_configured` | — | — |
| `webhook_last_error` | `varchar(1000)` | YES | NULL | — | — |
| `verified_at` | `datetime` | YES | NULL | — | — |
| `is_active` | `tinyint(1)` | NO | `1` | — | `MUL` |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_max_bots_active` (`is_active`, `id`)

### Physical foreign keys

- None enforced by MySQL.

## `max_chats`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `bot_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `chat_id` | `varchar(32)` | NO | NULL | — | — |
| `name` | `varchar(160)` | NO | NULL | — | — |
| `max_title` | `varchar(255)` | YES | NULL | — | — |
| `chat_type` | `enum('chat','channel','dialog','unknown')` | NO | `unknown` | — | — |
| `max_status` | `enum('active','removed','left','closed','unknown')` | NO | `unknown` | — | — |
| `purpose` | `varchar(500)` | YES | NULL | — | — |
| `is_active` | `tinyint(1)` | NO | `1` | — | `MUL` |
| `discovered_via` | `enum('manual','webhook')` | NO | `manual` | — | — |
| `last_verified_at` | `datetime` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `updated_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_max_chats_active` (`is_active`, `bot_id`, `id`)
- **UNIQUE** `uk_max_chat_per_bot` (`bot_id`, `chat_id`)

### Physical foreign keys

- `fk_max_chats_bot`: (`bot_id`) → `max_bots`.`id` ON UPDATE NO ACTION ON DELETE CASCADE
