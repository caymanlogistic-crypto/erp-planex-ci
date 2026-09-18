CREATE TABLE IF NOT EXISTS feo_transfer_batches (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(180) NOT NULL,
 mode VARCHAR(20) NOT NULL,
 source_request_id VARCHAR(64) NULL,
 destination_json LONGTEXT NOT NULL,
 comment TEXT NULL,
 request_count INT UNSIGNED NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'applied',
 operation_key CHAR(64) NOT NULL,
 created_by_user_id BIGINT UNSIGNED NOT NULL,
 created_by_role VARCHAR(30) NULL,
 created_by_name VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reverted_by_user_id BIGINT UNSIGNED NULL,
 reverted_by_name VARCHAR(255) NULL,
 reverted_at DATETIME NULL,
 UNIQUE KEY uk_feo_transfer_operation (operation_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feo_transfer_items (
 batch_id BIGINT UNSIGNED NOT NULL,
 zayavka_id VARCHAR(64) NOT NULL,
 request_row_id BIGINT UNSIGNED NOT NULL,
 before_json LONGTEXT NOT NULL,
 after_json LONGTEXT NOT NULL,
 previous_batch_id BIGINT UNSIGNED NULL,
 PRIMARY KEY (batch_id,zayavka_id),
 KEY idx_feo_transfer_request (zayavka_id,batch_id),
 CONSTRAINT fk_feo_transfer_batch FOREIGN KEY (batch_id) REFERENCES feo_transfer_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feo_location_overrides (
 zayavka_id VARCHAR(64) NOT NULL PRIMARY KEY,
 batch_id BIGINT UNSIGNED NOT NULL,
 CONSTRAINT fk_feo_location_batch FOREIGN KEY (batch_id) REFERENCES feo_transfer_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable transport payloads. A future legacy adapter consumes these in ID order,
-- with compare-before-write and idempotency; no legacy connection is enabled here.
CREATE TABLE IF NOT EXISTS feo_transfer_outbox (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 batch_id BIGINT UNSIGNED NOT NULL,
 operation VARCHAR(20) NOT NULL,
 payload_json LONGTEXT NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'awaiting_connector',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 sent_at DATETIME NULL,
 last_error TEXT NULL,
 UNIQUE KEY uk_feo_transfer_outbox (batch_id,operation),
 CONSTRAINT fk_feo_transfer_outbox_batch FOREIGN KEY (batch_id) REFERENCES feo_transfer_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS geocoding_settings (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 api_key_encrypted TEXT NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
