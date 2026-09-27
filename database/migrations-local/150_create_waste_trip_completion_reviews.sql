ALTER TABLE waste_trip_registration_journal MODIFY waste_trip_request_id BIGINT UNSIGNED NULL;

CREATE TABLE waste_trip_completion_reviews (
  waste_trip_id BIGINT UNSIGNED NOT NULL,
  review_status VARCHAR(16) NOT NULL DEFAULT 'work',
  status_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_by_membership_id BIGINT UNSIGNED NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  submitted_at DATETIME(6) NULL,
  mail_status VARCHAR(16) NULL,
  mail_message_id VARCHAR(255) NULL,
  mail_error VARCHAR(255) NULL,
  PRIMARY KEY (waste_trip_id),
  KEY idx_completion_review_status (review_status,waste_trip_id),
  CONSTRAINT fk_completion_review_trip FOREIGN KEY (waste_trip_id) REFERENCES waste_trips(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
