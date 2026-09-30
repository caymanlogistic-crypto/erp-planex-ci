CREATE TABLE IF NOT EXISTS waste_trip_closing_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  waste_trip_id BIGINT UNSIGNED NOT NULL,
  document_id INT UNSIGNED NOT NULL,
  slot_code VARCHAR(24) NOT NULL,
  requirement_code VARCHAR(32) NOT NULL,
  custom_title VARCHAR(255) NULL,
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_wtcd_trip (waste_trip_id, deleted_at, id),
  UNIQUE KEY uq_wtcd_document (document_id),
  CONSTRAINT fk_wtcd_trip FOREIGN KEY (waste_trip_id) REFERENCES waste_trips(id) ON DELETE RESTRICT,
  CONSTRAINT fk_wtcd_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waste_trip_closing_reviews (
  waste_trip_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  state ENUM('work','sending','review','failed','unknown') NOT NULL DEFAULT 'work',
  version INT UNSIGNED NOT NULL DEFAULT 0,
  recipient_email VARCHAR(254) NULL,
  archive_token CHAR(64) NULL,
  message_id VARCHAR(254) NULL,
  mail_status VARCHAR(24) NULL,
  submitted_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_wtcr_trip FOREIGN KEY (waste_trip_id) REFERENCES waste_trips(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waste_trip_closing_finance_links (
  finance_link_id INT UNSIGNED NOT NULL PRIMARY KEY,
  closing_document_id BIGINT UNSIGNED NOT NULL,
  bank_transaction_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_wtcfl_document_payment (closing_document_id, bank_transaction_id),
  KEY idx_wtcfl_payment (bank_transaction_id),
  CONSTRAINT fk_wtcfl_finance_link FOREIGN KEY (finance_link_id) REFERENCES finance_closing_document_links(id) ON DELETE CASCADE,
  CONSTRAINT fk_wtcfl_document FOREIGN KEY (closing_document_id) REFERENCES waste_trip_closing_documents(id) ON DELETE RESTRICT,
  CONSTRAINT fk_wtcfl_bank_transaction FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
