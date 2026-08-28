CREATE TABLE IF NOT EXISTS `feo_utilizers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(255) NOT NULL,
  `short_name` VARCHAR(80) NOT NULL,
  `address` VARCHAR(500) NULL,
  `latitude` DECIMAL(10,7) NULL,
  `longitude` DECIMAL(10,7) NULL,
  `fkko_classification` VARCHAR(500) NULL,
  `adr` TINYINT(1) NOT NULL DEFAULT 0,
  `color_hex` CHAR(7) NOT NULL DEFAULT '#2563EB',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 100,
  `created_by_user_id` INT UNSIGNED NULL,
  `updated_by_user_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_feo_utilizers_short_name` (`short_name`),
  KEY `idx_feo_utilizers_active_order` (`is_active`,`sort_order`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `feo_utilizer_match_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `utilizer_id` BIGINT UNSIGNED NOT NULL,
  `source_field` ENUM('recipient','delivery_address') NOT NULL,
  `match_type` ENUM('exact','contains') NOT NULL,
  `pattern` VARCHAR(500) NOT NULL,
  `normalized_pattern` VARCHAR(500) NOT NULL,
  `priority` INT NOT NULL DEFAULT 100,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by_user_id` INT UNSIGNED NULL,
  `updated_by_user_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_feo_utilizer_rule_canonical` (`source_field`,`match_type`,`normalized_pattern`),
  KEY `idx_feo_utilizer_rule_match` (`is_active`,`source_field`,`priority`,`id`),
  KEY `idx_feo_utilizer_rule_utilizer` (`utilizer_id`,`is_active`,`priority`,`id`),
  CONSTRAINT `fk_feo_utilizer_rule_utilizer` FOREIGN KEY (`utilizer_id`) REFERENCES `feo_utilizers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `feo_request_utilizer_overrides` (
  `zayavka_id` VARCHAR(64) NOT NULL,
  `utilizer_id` BIGINT UNSIGNED NOT NULL,
  `created_by_user_id` INT UNSIGNED NULL,
  `updated_by_user_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`zayavka_id`),
  KEY `idx_feo_request_utilizer_override_utilizer` (`utilizer_id`,`zayavka_id`),
  CONSTRAINT `fk_feo_request_utilizer_override_request` FOREIGN KEY (`zayavka_id`) REFERENCES `feo_requests` (`zayavka_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_feo_request_utilizer_override_utilizer` FOREIGN KEY (`utilizer_id`) REFERENCES `feo_utilizers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
