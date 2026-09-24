CREATE TABLE IF NOT EXISTS `feo_share_import_totals` (
  `import_id` BIGINT UNSIGNED NOT NULL,
  `feo_count` INT UNSIGNED NOT NULL,
  `feo_kg` DECIMAL(18,3) NOT NULL,
  `company_count` INT UNSIGNED NOT NULL,
  `company_kg` DECIMAL(18,3) NOT NULL,
  PRIMARY KEY (`import_id`),
  CONSTRAINT `fk_feo_share_totals_import` FOREIGN KEY (`import_id`) REFERENCES `feo_imports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `feo_share_import_requests` (
  `import_id` BIGINT UNSIGNED NOT NULL,
  `zayavka_id` VARCHAR(64) NOT NULL,
  `mass_kg` DECIMAL(18,3) NOT NULL,
  `mno_region` VARCHAR(255) NULL,
  PRIMARY KEY (`import_id`,`zayavka_id`),
  KEY `idx_feo_share_request_id` (`zayavka_id`),
  CONSTRAINT `fk_feo_share_requests_import` FOREIGN KEY (`import_id`) REFERENCES `feo_imports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
