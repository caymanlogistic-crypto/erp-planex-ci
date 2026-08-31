CREATE TABLE IF NOT EXISTS `document_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `document_type` VARCHAR(64) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `active_version_id` BIGINT UNSIGNED NULL,
  `created_by_user_id` INT UNSIGNED NULL,
  `created_by_role` VARCHAR(32) NULL,
  `updated_by_user_id` INT UNSIGNED NULL,
  `updated_by_role` VARCHAR(32) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_document_templates_type_status` (`document_type`,`status`),
  KEY `idx_document_templates_active_version` (`active_version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_template_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` INT UNSIGNED NOT NULL,
  `version_no` INT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_path` VARCHAR(1000) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL,
  `sha256` CHAR(64) NOT NULL,
  `placeholders_json` LONGTEXT NOT NULL,
  `created_by_user_id` INT UNSIGNED NULL,
  `created_by_role` VARCHAR(32) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_template_versions` (`template_id`,`version_no`),
  KEY `idx_document_template_versions_template` (`template_id`,`id`),
  CONSTRAINT `fk_document_template_versions_template` FOREIGN KEY (`template_id`) REFERENCES `document_templates` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `generated_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_type` VARCHAR(64) NOT NULL,
  `document_number` VARCHAR(255) NULL,
  `document_date` DATE NULL,
  `template_id` INT UNSIGNED NOT NULL,
  `template_version_id` BIGINT UNSIGNED NOT NULL,
  `route_executor_id` INT UNSIGNED NOT NULL,
  `request_ids_json` LONGTEXT NOT NULL,
  `total_mass_kg` DECIMAL(16,3) NOT NULL DEFAULT 0,
  `unload_address` TEXT NULL,
  `parameters_json` LONGTEXT NOT NULL,
  `snapshot_json` LONGTEXT NOT NULL,
  `stored_path` VARCHAR(1000) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `sha256` CHAR(64) NOT NULL,
  `created_by_user_id` INT UNSIGNED NULL,
  `created_by_role` VARCHAR(32) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_generated_documents_type_date` (`document_type`,`document_date`),
  KEY `idx_generated_documents_executor` (`route_executor_id`,`id`),
  KEY `idx_generated_documents_template_version` (`template_version_id`,`id`),
  KEY `idx_generated_documents_created` (`created_at`,`id`),
  CONSTRAINT `fk_generated_documents_template` FOREIGN KEY (`template_id`) REFERENCES `document_templates` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_generated_documents_template_version` FOREIGN KEY (`template_version_id`) REFERENCES `document_template_versions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_generated_documents_route_executor` FOREIGN KEY (`route_executor_id`) REFERENCES `crews` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `generated_document_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `generated_document_id` BIGINT UNSIGNED NOT NULL,
  `request_id` VARCHAR(64) NOT NULL,
  `position` INT UNSIGNED NOT NULL,
  `snapshot_json` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_generated_document_request_position` (`generated_document_id`,`position`),
  KEY `idx_generated_document_requests_request` (`request_id`,`generated_document_id`),
  CONSTRAINT `fk_generated_document_requests_document` FOREIGN KEY (`generated_document_id`) REFERENCES `generated_documents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @docgen_fk_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'document_templates'
    AND CONSTRAINT_NAME = 'fk_document_templates_active_version'
);
SET @docgen_fk_sql = IF(
  @docgen_fk_exists = 0,
  'ALTER TABLE `document_templates` ADD CONSTRAINT `fk_document_templates_active_version` FOREIGN KEY (`active_version_id`) REFERENCES `document_template_versions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE docgen_fk_stmt FROM @docgen_fk_sql;
EXECUTE docgen_fk_stmt;
DEALLOCATE PREPARE docgen_fk_stmt;
