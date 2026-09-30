CREATE TABLE route_executor_review_sends (
  card_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_account_id BIGINT UNSIGNED NOT NULL,
  responsible_membership_id BIGINT UNSIGNED NULL,
  stage VARCHAR(16) NOT NULL,
  partial_message_id VARCHAR(255) NULL,
  partial_mail_status VARCHAR(16) NULL,
  final_message_id VARCHAR(255) NULL,
  final_mail_status VARCHAR(16) NULL,
  partial_at DATETIME(6) NULL,
  final_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (card_id),
  KEY idx_route_executor_review_actor (actor_account_id,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
