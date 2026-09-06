SET @dbname = DATABASE();

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='company_roles' AND COLUMN_NAME='default_page_key'),
    'SELECT 1',
    'ALTER TABLE `company_roles` ADD COLUMN `default_page_key` VARCHAR(191) NULL AFTER `is_system`'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='company_roles' AND INDEX_NAME='idx_company_roles_default_page'),
    'SELECT 1',
    'ALTER TABLE `company_roles` ADD KEY `idx_company_roles_default_page` (`company_id`,`default_page_key`)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- System owner ACL remains immutable. Its landing semantics are explicit and
-- safe: Руководитель always starts on the canonical company dashboard.
UPDATE company_roles
   SET default_page_key = 'company.dashboard'
 WHERE system_key = 'company_owner'
   AND is_system = 1
   AND (default_page_key IS NULL OR default_page_key <> 'company.dashboard');
