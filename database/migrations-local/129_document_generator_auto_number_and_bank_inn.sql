-- Migration 129: canonical contractor bank INN + document number sequence (idempotent)
SET @dbname = DATABASE();

-- contractors.bank_inn: optional master-data field; generator validates it when required by template.
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='contractors' AND COLUMN_NAME='bank_inn') > 0,
    'SELECT 1 AS bank_inn_already_exists',
    'ALTER TABLE `contractors` ADD COLUMN `bank_inn` VARCHAR(10) DEFAULT NULL AFTER `bank_corr_account`'
));
PREPARE addBankInnIfNotExists FROM @preparedStatement;
EXECUTE addBankInnIfNotExists;
DEALLOCATE PREPARE addBankInnIfNotExists;

CREATE TABLE IF NOT EXISTS `document_number_sequences` (
  `sequence_key` VARCHAR(64) NOT NULL,
  `prefix` VARCHAR(16) NOT NULL,
  `next_number` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`sequence_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `document_number_sequences` (`sequence_key`,`prefix`,`next_number`)
SELECT 'vehicle_lease_crew_contract','П ',105
WHERE NOT EXISTS (
  SELECT 1 FROM `document_number_sequences` WHERE `sequence_key`='vehicle_lease_crew_contract'
);

-- Independent database backstop against duplicate numbers of the same document type.
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='generated_documents' AND INDEX_NAME='uk_generated_documents_type_number') > 0,
    'SELECT 1 AS document_number_unique_already_exists',
    'ALTER TABLE `generated_documents` ADD UNIQUE KEY `uk_generated_documents_type_number` (`document_type`,`document_number`)'
));
PREPARE addDocumentNumberUniqueIfNotExists FROM @preparedStatement;
EXECUTE addDocumentNumberUniqueIfNotExists;
DEALLOCATE PREPARE addDocumentNumberUniqueIfNotExists;
