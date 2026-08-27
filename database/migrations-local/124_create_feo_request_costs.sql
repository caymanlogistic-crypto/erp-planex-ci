CREATE TABLE IF NOT EXISTS `feo_request_cost_blocks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pricing_type` ENUM('rate_34_5','rate_38','agreement') NOT NULL,
  `rate_per_kg` DECIMAL(10,2) NULL,
  `snapshot_request_count` INT UNSIGNED NOT NULL,
  `snapshot_total_mass_kg` DECIMAL(18,3) NOT NULL,
  `total_cost` DECIMAL(18,2) NOT NULL,
  `comment` TEXT NULL,
  `created_by_user_id` INT UNSIGNED NULL,
  `created_by_role` VARCHAR(20) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_feo_request_cost_blocks_created` (`created_at`,`id`),
  KEY `idx_feo_request_cost_blocks_type` (`pricing_type`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `feo_request_cost_block_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `block_id` BIGINT UNSIGNED NOT NULL,
  `zayavka_id` VARCHAR(64) NOT NULL,
  `snapshot_mass_kg` DECIMAL(18,3) NOT NULL,
  `position_no` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_feo_request_cost_one_price_per_request` (`zayavka_id`),
  UNIQUE KEY `uk_feo_request_cost_block_request` (`block_id`,`zayavka_id`),
  KEY `idx_feo_request_cost_block_position` (`block_id`,`position_no`,`id`),
  CONSTRAINT `fk_feo_request_cost_block_requests_block` FOREIGN KEY (`block_id`) REFERENCES `feo_request_cost_blocks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
