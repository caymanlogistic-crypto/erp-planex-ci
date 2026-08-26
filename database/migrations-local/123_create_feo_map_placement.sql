CREATE TABLE IF NOT EXISTS `feo_placement_blocks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NULL,
  `block_type` ENUM('addition','exclusion') NOT NULL,
  `status` ENUM('applied','disabled') NOT NULL DEFAULT 'applied',
  `source_type` ENUM('manual','xls','xlsx') NOT NULL DEFAULT 'manual',
  `source_filename` VARCHAR(255) NULL,
  `source_file_key` VARCHAR(128) NULL,
  `created_by_user_id` INT UNSIGNED NULL,
  `created_by_role` VARCHAR(20) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_feo_placement_blocks_status_id` (`status`,`id`),
  KEY `idx_feo_placement_blocks_type_id` (`block_type`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `feo_placement_block_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `block_id` BIGINT UNSIGNED NOT NULL,
  `zayavka_id` VARCHAR(64) NOT NULL,
  `position_no` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_feo_placement_block_request` (`block_id`,`zayavka_id`),
  KEY `idx_feo_placement_request_id` (`zayavka_id`),
  CONSTRAINT `fk_feo_placement_block_requests_block` FOREIGN KEY (`block_id`) REFERENCES `feo_placement_blocks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `feo_placement_current` (
  `zayavka_id` VARCHAR(64) NOT NULL,
  `source_block_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`zayavka_id`),
  KEY `idx_feo_placement_current_source_block` (`source_block_id`),
  CONSTRAINT `fk_feo_placement_current_source_block` FOREIGN KEY (`source_block_id`) REFERENCES `feo_placement_blocks` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
