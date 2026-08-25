# ERP PLANEX — Tenant Physical Schema — Other tenant domain

> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `39969bcca02b28ec05795796dceb7b5aee80b59ed98d0192a166deeaa653bee1`

## `feo_import_changes`

**Domain:** Other tenant domain  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `import_id` | `bigint unsigned` | NO | NULL | — | `MUL` |
| `zayavka_id` | `varchar(64)` | NO | NULL | — | `MUL` |
| `action` | `enum('insert','update')` | NO | NULL | — | — |
| `previous_import_id` | `bigint unsigned` | YES | NULL | — | — |
| `before_json` | `longtext` | YES | NULL | — | — |
| `after_json` | `longtext` | NO | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_feo_import_changes_import` (`import_id`, `id`)
- **INDEX** `idx_feo_import_changes_request` (`zayavka_id`)

### Physical foreign keys

- `fk_feo_import_changes_import`: (`import_id`) → `feo_imports`.`id` ON UPDATE NO ACTION ON DELETE CASCADE

## `feo_imports`

**Domain:** Other tenant domain  
**Purpose:** Physical tenant table; semantics follow owning application module.

| Column | Type | Null | Default | Extra | Key |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | NULL | `auto_increment` | `PRI` |
| `filename` | `varchar(255)` | NO | NULL | — | — |
| `file_hash` | `char(64)` | NO | NULL | — | `MUL` |
| `rows_total` | `int unsigned` | NO | `0` | — | — |
| `unique_requests` | `int unsigned` | NO | `0` | — | — |
| `new_count` | `int unsigned` | NO | `0` | — | — |
| `updated_count` | `int unsigned` | NO | `0` | — | — |
| `unchanged_count` | `int unsigned` | NO | `0` | — | — |
| `missing_count` | `int unsigned` | NO | `0` | — | — |
| `status` | `enum('applied','rolled_back')` | NO | `applied` | — | `MUL` |
| `created_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `created_by_role` | `varchar(20)` | YES | NULL | — | — |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `DEFAULT_GENERATED` | — |
| `rolled_back_by_user_id` | `int unsigned` | YES | NULL | — | — |
| `rolled_back_by_role` | `varchar(20)` | YES | NULL | — | — |
| `rolled_back_at` | `datetime` | YES | NULL | — | — |

### Indexes / constraints

- **PRIMARY** `PRIMARY` (`id`)
- **INDEX** `idx_feo_imports_file_hash` (`file_hash`)
- **INDEX** `idx_feo_imports_status_id` (`status`, `id`)

### Physical foreign keys

- None enforced by MySQL.

## `feo_requests`

**Domain:** Other tenant domain  
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
