CREATE TABLE IF NOT EXISTS `finance_closing_counterparties` (
    `bank_transaction_id` INT UNSIGNED NOT NULL,
    `entity_type` VARCHAR(20) NOT NULL,
    `entity_id` INT UNSIGNED NOT NULL,
    `assigned_by_user_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`bank_transaction_id`),
    KEY `idx_fcc_entity` (`entity_type`, `entity_id`),
    CONSTRAINT `fk_fcc_bank_transaction`
        FOREIGN KEY (`bank_transaction_id`) REFERENCES `bank_transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `finance_closing_document_links` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bank_transaction_id` INT UNSIGNED NOT NULL,
    `document_id` INT UNSIGNED NOT NULL,
    `requirement_code` VARCHAR(32) NOT NULL,
    `created_by_user_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fcdl_transaction_document_requirement` (`bank_transaction_id`, `document_id`, `requirement_code`),
    KEY `idx_fcdl_transaction` (`bank_transaction_id`),
    KEY `idx_fcdl_document` (`document_id`),
    CONSTRAINT `fk_fcdl_bank_transaction`
        FOREIGN KEY (`bank_transaction_id`) REFERENCES `bank_transactions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fcdl_document`
        FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;