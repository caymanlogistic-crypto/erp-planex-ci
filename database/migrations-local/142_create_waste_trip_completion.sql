CREATE TABLE IF NOT EXISTS `waste_trip_completion_items` (
  `waste_trip_request_id` BIGINT UNSIGNED NOT NULL,
  `arrived_at` DATETIME NULL,
  `departed_at` DATETIME NULL,
  `driver_signature_present` TINYINT(1) NOT NULL DEFAULT 0,
  `sender_signature_present` TINYINT(1) NOT NULL DEFAULT 0,
  `comment` VARCHAR(500) NULL,
  `updated_by_membership_id` BIGINT UNSIGNED NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`waste_trip_request_id`),
  KEY `idx_waste_trip_completion_updated` (`updated_at`,`waste_trip_request_id`),
  CONSTRAINT `fk_waste_trip_completion_request`
    FOREIGN KEY (`waste_trip_request_id`) REFERENCES `waste_trip_requests` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `waste_trip_completion_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `waste_trip_request_id` BIGINT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `download_name` VARCHAR(255) NOT NULL,
  `stored_path` VARCHAR(500) NOT NULL,
  `mime_type` VARCHAR(127) NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL,
  `uploaded_by_membership_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  `deleted_by_membership_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_waste_trip_completion_stored_path` (`stored_path`),
  KEY `idx_waste_trip_completion_documents_request` (`waste_trip_request_id`,`deleted_at`,`id`),
  CONSTRAINT `fk_waste_trip_completion_document_request`
    FOREIGN KEY (`waste_trip_request_id`) REFERENCES `waste_trip_requests` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
