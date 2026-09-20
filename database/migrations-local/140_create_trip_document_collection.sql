-- Tenant-owned workflow; production application is a separately authorized operation.
CREATE TABLE IF NOT EXISTS trip_document_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id INT UNSIGNED NOT NULL,
 request_token CHAR(32) NOT NULL,
 trip_type ENUM('waste') NOT NULL,
 trip_id BIGINT UNSIGNED NOT NULL,
 legal_entity_id INT UNSIGNED NOT NULL,
 initiator_membership_id BIGINT UNSIGNED NOT NULL,
 responsible_membership_id BIGINT UNSIGNED NOT NULL,
 assistant_membership_id BIGINT UNSIGNED NOT NULL,
 assistant_account_id BIGINT UNSIGNED NOT NULL,
 responsible_account_id BIGINT UNSIGNED NOT NULL,
 sender_profile_id BIGINT UNSIGNED NOT NULL,
 sender_email VARCHAR(254) NOT NULL,
 responsible_email VARCHAR(254) NOT NULL,
 contact_name VARCHAR(255) NOT NULL,
 contact_email VARCHAR(254) NOT NULL,
 contact_phone VARCHAR(64) NOT NULL,
 promised_at DATETIME NULL,
 next_check_at DATETIME NULL,
 next_action ENUM('send','wait','review','collect','remind','mail_error','complete') NOT NULL DEFAULT 'send',
 response_received_at DATETIME NULL,
 package_stage ENUM('none','minimum','full') NOT NULL DEFAULT 'none',
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_document_request_token(request_token),
 UNIQUE KEY uq_document_request_trip(trip_type,trip_id),
 KEY idx_document_request_due(next_action,next_check_at),
 KEY idx_document_request_owner(responsible_membership_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_document_mail (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL,
 operation_key VARCHAR(100) NOT NULL,
 kind ENUM('request','reminder','minimum','full') NOT NULL,
 message_id VARCHAR(254) NOT NULL,
 actor_membership_id BIGINT UNSIGNED NOT NULL,
 sender_profile_id BIGINT UNSIGNED NOT NULL,
 snapshot_json LONGTEXT NOT NULL,
 snapshot_hash CHAR(64) NOT NULL,
 status ENUM('pending','sending','accepted','failed','unknown','stale') NOT NULL DEFAULT 'pending',
 error_message VARCHAR(500) NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 attempted_at DATETIME NULL,
 accepted_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_document_mail_operation(request_id,operation_key),
 UNIQUE KEY uq_document_mail_message(message_id),
 KEY idx_document_mail_pending(status,id),
 CONSTRAINT fk_document_mail_request FOREIGN KEY(request_id) REFERENCES trip_document_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_document_incoming (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NULL,
 candidate_request_ids TEXT NOT NULL,
 identity_hash CHAR(64) NOT NULL,
 message_id VARCHAR(254) NOT NULL,
 sender VARCHAR(500) NOT NULL,
 subject VARCHAR(1000) NOT NULL,
 body_text MEDIUMTEXT NOT NULL,
 attachments_json LONGTEXT NOT NULL,
 received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reviewed_at DATETIME NULL,
 UNIQUE KEY uq_document_incoming_identity(identity_hash),
 KEY idx_document_incoming_request(request_id,id),
 CONSTRAINT fk_document_incoming_request FOREIGN KEY(request_id) REFERENCES trip_document_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_document_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL,
 event_key VARCHAR(120) NOT NULL,
 action VARCHAR(80) NOT NULL,
 description VARCHAR(1000) NOT NULL,
 context_json LONGTEXT NULL,
 actor_membership_id BIGINT UNSIGNED NULL,
 max_delivery_id BIGINT UNSIGNED NULL,
 max_error VARCHAR(500) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_document_event(request_id,event_key),
 CONSTRAINT fk_document_event_request FOREIGN KEY(request_id) REFERENCES trip_document_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_document_mailboxes (
 profile_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 account_id BIGINT UNSIGNED NOT NULL,
 legal_entity_id INT UNSIGNED NOT NULL,
 email VARCHAR(254) NOT NULL,
 uidvalidity BIGINT UNSIGNED NULL,
 last_uid BIGINT UNSIGNED NULL,
 last_checked_at DATETIME NULL,
 error_message VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_document_previews (
 token CHAR(48) NOT NULL PRIMARY KEY,
 membership_id BIGINT UNSIGNED NOT NULL,
 request_id BIGINT UNSIGNED NULL,
 kind ENUM('request','reminder','minimum','full') NOT NULL,
 input_json LONGTEXT NOT NULL,
 snapshot_json LONGTEXT NOT NULL,
 snapshot_hash CHAR(64) NOT NULL,
 expires_at DATETIME NOT NULL,
 consumed_mail_id BIGINT UNSIGNED NULL,
 KEY idx_document_preview_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
