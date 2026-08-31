-- Migration 012: normalize the existing Superadmin company bank requisites.
-- The UI/save flow already uses these columns via company_bank_guard.php; this migration
-- makes that canonical storage explicit instead of relying on lazy ALTER TABLE writes.

DROP PROCEDURE IF EXISTS add_company_bank_column_if_not_exists;

CREATE PROCEDURE add_company_bank_column_if_not_exists(
    IN columnName VARCHAR(128),
    IN columnDefinition VARCHAR(1024)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'companies'
           AND COLUMN_NAME = columnName
    ) THEN
        SET @company_bank_sql = CONCAT(
            'ALTER TABLE `companies` ADD COLUMN `', columnName, '` ', columnDefinition
        );
        PREPARE company_bank_stmt FROM @company_bank_sql;
        EXECUTE company_bank_stmt;
        DEALLOCATE PREPARE company_bank_stmt;
    END IF;
END;

CALL add_company_bank_column_if_not_exists('bank_account', 'VARCHAR(32) NULL DEFAULT NULL COMMENT ''Расчётный счёт''');
CALL add_company_bank_column_if_not_exists('bank_name', 'VARCHAR(255) NULL DEFAULT NULL COMMENT ''Наименование банка''');
CALL add_company_bank_column_if_not_exists('bank_bik', 'VARCHAR(16) NULL DEFAULT NULL COMMENT ''БИК''');
CALL add_company_bank_column_if_not_exists('bank_corr_account', 'VARCHAR(32) NULL DEFAULT NULL COMMENT ''Корр. счёт''');

DROP PROCEDURE IF EXISTS add_company_bank_column_if_not_exists;
