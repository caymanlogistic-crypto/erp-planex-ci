ALTER TABLE `finance_closing_document_links`
    ADD COLUMN `is_edo` TINYINT(1) NOT NULL DEFAULT 0 AFTER `requirement_code`;

CREATE TABLE IF NOT EXISTS `finance_closing_document_manual_statuses` (
    `bank_transaction_id` INT UNSIGNED NOT NULL,
    `requirement_key` VARCHAR(64) NOT NULL,
    `updated_by_user_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`bank_transaction_id`, `requirement_key`),
    CONSTRAINT `fk_fcdms_bank_transaction`
        FOREIGN KEY (`bank_transaction_id`) REFERENCES `bank_transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `finance_closing_document_requirement_exceptions` (
    `inn` VARCHAR(12) NOT NULL,
    `requirement_key` VARCHAR(64) NOT NULL,
    `created_by_user_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`inn`, `requirement_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
