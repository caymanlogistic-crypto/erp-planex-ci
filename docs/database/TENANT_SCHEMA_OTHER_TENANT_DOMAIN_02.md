# ERP PLANEX — Tenant Physical Schema — Other tenant domain — Part 2

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `368b5cf1642f5ddd8c7f2d659445de03353b30370001e5a2113967358becc632`

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

## `feo_share_import_requests`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `import_id` | `bigint unsigned` | NO | NULL | — | `PRI` |
| `zayavka_id` | `varchar(64)` | NO | NULL | — | `PRI` |
| `mass_kg` | `decimal(18,3)` | NO | NULL | — | — |
| `mno_region` | `varchar(255)` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`import_id`, `zayavka_id`)
- **INDEX** `idx_feo_share_request_id` (`zayavka_id`)

### Physical foreign keys

- `fk_feo_share_requests_import`: (`import_id`) → `feo_imports`.`id` ON UPDATE NO ACTION ON DELETE CASCADE

## `feo_share_import_totals`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `import_id` | `bigint unsigned` | NO | NULL | — | `PRI` |
| `feo_count` | `int unsigned` | NO | NULL | — | — |
| `feo_kg` | `decimal(18,3)` | NO | NULL | — | — |
| `company_count` | `int unsigned` | NO | NULL | — | — |
| `company_kg` | `decimal(18,3)` | NO | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`import_id`)

### Physical foreign keys

- `fk_feo_share_totals_import`: (`import_id`) → `feo_imports`.`id` ON UPDATE NO ACTION ON DELETE CASCADE

## `feo_transfer_batches`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `name` | `varchar(180)` | NO | NULL | — | — |
| `mode` | `varchar(20)` | NO | NULL | — | — |
| `source_request_id` | `varchar(64)` | YES | NULL | — | — |
| `destination_json` | `longtext` | NO | NULL | — | — |
| `comment` | `text` | YES | NULL | — | — |
| `request_count` | `int unsigned` | NO | NULL | — | — |
| `status` | `varchar(20)` | NO | `applied` | — | — |
| `operation_key` | `char(64)` | NO | NULL | — | `UNI` |
| `created_by_user_id` | `bigint unsigned` | NO | NULL | — | — |
| `created_by_role` | `varchar(30)` | YES | NULL | — | — |
| `created_by_name` | `varchar(255)` | NO | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `reverted_by_user_id` | `bigint unsigned` | YES | NULL | — | — |
| `reverted_by_name` | `varchar(255)` | YES | NULL | — | — |
| `reverted_at` | `datetime` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **UNIQUE** `uk_feo_transfer_operation` (`operation_key`)

### Physical foreign keys

- None enforced by MySQL.

## `feo_transfer_items`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `batch_id` | `bigint unsigned` | NO | NULL | — | `PRI` |
| `zayavka_id` | `varchar(64)` | NO | NULL | — | `PRI` |
| `request_row_id` | `bigint unsigned` | NO | NULL | — | — |
| `before_json` | `longtext` | NO | NULL | — | — |
| `after_json` | `longtext` | NO | NULL | — | — |
| `previous_batch_id` | `bigint unsigned` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`batch_id`, `zayavka_id`)
- **INDEX** `idx_feo_transfer_request` (`zayavka_id`, `batch_id`)

### Physical foreign keys

- `fk_feo_transfer_batch`: (`batch_id`) → `feo_transfer_batches`.`id` ON UPDATE NO ACTION ON DELETE NO ACTION

## `feo_transfer_outbox`

**Domain:** Other tenant domain<br>
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `batch_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `operation` | `varchar(20)` | NO | NULL | — | — |
| `payload_json` | `longtext` | NO | NULL | — | — |
| `status` | `varchar(30)` | NO | `awaiting_connector` | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `sent_at` | `datetime` | YES | NULL | — | — |
| `last_error` | `text` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **UNIQUE** `uk_feo_transfer_outbox` (`batch_id`, `operation`)

### Physical foreign keys

- `fk_feo_transfer_outbox_batch`: (`batch_id`) → `feo_transfer_batches`.`id` ON UPDATE NO ACTION ON DELETE NO ACTION

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
