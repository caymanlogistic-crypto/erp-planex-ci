-- Migration 079: closing-document exceptions support INN + optional payment-purpose substring.
-- Existing rows remain broad INN-only rules because purpose_contains defaults to ''.
SET @dbname = DATABASE();

SET @has_col = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'finance_closing_document_exceptions'
      AND COLUMN_NAME = 'purpose_contains'
);
SET @s = IF(
    @has_col = 0,
    'ALTER TABLE `finance_closing_document_exceptions` ADD COLUMN `purpose_contains` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `inn`',
    'SELECT 1'
);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_old_unique = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'finance_closing_document_exceptions'
      AND INDEX_NAME = 'uk_fcde_inn'
);
SET @s = IF(
    @has_old_unique > 0,
    'ALTER TABLE `finance_closing_document_exceptions` DROP INDEX `uk_fcde_inn`',
    'SELECT 1'
);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_new_unique = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'finance_closing_document_exceptions'
      AND INDEX_NAME = 'uk_fcde_inn_purpose'
);
SET @s = IF(
    @has_new_unique = 0,
    'ALTER TABLE `finance_closing_document_exceptions` ADD UNIQUE KEY `uk_fcde_inn_purpose` (`inn`, `purpose_contains`)',
    'SELECT 1'
);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
