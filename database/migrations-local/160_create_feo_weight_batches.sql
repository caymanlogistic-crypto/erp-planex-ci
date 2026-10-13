CREATE TABLE IF NOT EXISTS feo_weight_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  comment TEXT NULL,
  request_count INT UNSIGNED NOT NULL,
  operation_key CHAR(64) NOT NULL,
  status ENUM('applied','reverted') NOT NULL DEFAULT 'applied',
  created_by_user_id INT UNSIGNED NOT NULL,
  created_by_name VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reverted_by_user_id INT UNSIGNED NULL,
  reverted_by_name VARCHAR(255) NULL,
  reverted_at DATETIME NULL,
  UNIQUE KEY uk_feo_weight_operation (operation_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feo_weight_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id BIGINT UNSIGNED NOT NULL,
  zayavka_id VARCHAR(64) NOT NULL,
  request_row_id BIGINT UNSIGNED NOT NULL,
  before_netto DECIMAL(14,4) NULL,
  before_brutto DECIMAL(14,4) NULL,
  after_netto DECIMAL(14,4) NULL,
  after_brutto DECIMAL(14,4) NULL,
  UNIQUE KEY uk_feo_weight_item (batch_id,zayavka_id),
  KEY idx_feo_weight_request (zayavka_id,batch_id),
  CONSTRAINT fk_feo_weight_item_batch FOREIGN KEY (batch_id) REFERENCES feo_weight_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
