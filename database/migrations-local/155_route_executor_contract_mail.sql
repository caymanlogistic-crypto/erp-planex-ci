CREATE TABLE route_executor_contract_mails (
  card_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_account_id BIGINT UNSIGNED NOT NULL,
  trip_business_id BIGINT UNSIGNED NOT NULL,
  recipient_email VARCHAR(254) NOT NULL,
  message_id VARCHAR(255) NOT NULL,
  mail_status VARCHAR(16) NOT NULL,
  sent_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (card_id),
  KEY idx_route_executor_contract_mail_status (mail_status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
