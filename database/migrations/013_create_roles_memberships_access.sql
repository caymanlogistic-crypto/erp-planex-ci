-- Migration 013: flexible company roles, memberships and access overrides.
-- CRITICAL: schema only. Legacy data conversion is performed by the controlled
-- roles/access migration runner after BEFORE snapshot validation.
--
-- company_users is the canonical global authentication identity after cutover.
-- Its legacy company_id remains only as an optional home-company reference;
-- membership is the authority for company access.

SET @dbname = DATABASE();

-- Global login must be unique. If historical duplicates exist this ALTER fails
-- closed and the migration must not guess how to merge credentials.
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='company_users' AND INDEX_NAME='uk_company_users_login') > 0,
    'SELECT 1 AS company_users_login_unique_already_exists',
    'ALTER TABLE `company_users` ADD UNIQUE KEY `uk_company_users_login` (`login`)'
));
PREPARE addCompanyUsersLoginUnique FROM @preparedStatement;
EXECUTE addCompanyUsersLoginUnique;
DEALLOCATE PREPARE addCompanyUsersLoginUnique;

-- A global account must not be deleted merely because its legacy/home company is deleted.
-- Drop the old CASCADE FK when present, make the reference nullable, then restore as SET NULL.
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='company_users'
        AND CONSTRAINT_NAME='fk_company_users_company' AND CONSTRAINT_TYPE='FOREIGN KEY') > 0,
    'ALTER TABLE `company_users` DROP FOREIGN KEY `fk_company_users_company`',
    'SELECT 1 AS company_users_company_fk_not_present'
));
PREPARE dropCompanyUsersCompanyFk FROM @preparedStatement;
EXECUTE dropCompanyUsersCompanyFk;
DEALLOCATE PREPARE dropCompanyUsersCompanyFk;

ALTER TABLE `company_users`
    MODIFY COLUMN `company_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Legacy/home company only; access authority is company_memberships';

SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='company_users'
        AND CONSTRAINT_NAME='fk_company_users_company' AND CONSTRAINT_TYPE='FOREIGN KEY') > 0,
    'SELECT 1 AS company_users_company_fk_already_exists',
    'ALTER TABLE `company_users` ADD CONSTRAINT `fk_company_users_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL'
));
PREPARE addCompanyUsersCompanyFk FROM @preparedStatement;
EXECUTE addCompanyUsersCompanyFk;
DEALLOCATE PREPARE addCompanyUsersCompanyFk;

CREATE TABLE IF NOT EXISTS `company_roles` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `name_normalized` VARCHAR(120) NOT NULL,
    `system_key` VARCHAR(64) DEFAULT NULL,
    `legacy_role_code` VARCHAR(64) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_company_roles_name` (`company_id`, `name_normalized`),
    UNIQUE KEY `uk_company_roles_system` (`company_id`, `system_key`),
    KEY `idx_company_roles_company` (`company_id`),
    CONSTRAINT `fk_company_roles_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_memberships` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL COMMENT 'Global identity: central company_users.id',
    `role_id` BIGINT UNSIGNED NOT NULL,
    `tenant_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Technical actor projection in tenant users.id',
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_company_membership_user` (`company_id`, `user_id`),
    UNIQUE KEY `uk_company_membership_tenant_user` (`company_id`, `tenant_user_id`),
    KEY `idx_company_membership_role` (`role_id`),
    KEY `idx_company_membership_status` (`company_id`, `status`),
    CONSTRAINT `fk_company_membership_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_company_membership_user`
        FOREIGN KEY (`user_id`) REFERENCES `company_users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_company_membership_role`
        FOREIGN KEY (`role_id`) REFERENCES `company_roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_role_page_permissions` (
    `role_id` BIGINT UNSIGNED NOT NULL,
    `page_key` VARCHAR(160) NOT NULL,
    `is_allowed` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`role_id`, `page_key`),
    KEY `idx_role_page_key` (`page_key`),
    CONSTRAINT `fk_role_page_permission_role`
        FOREIGN KEY (`role_id`) REFERENCES `company_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_membership_page_overrides` (
    `membership_id` BIGINT UNSIGNED NOT NULL,
    `page_key` VARCHAR(160) NOT NULL,
    `effect` VARCHAR(8) NOT NULL COMMENT 'allow|deny; absence means by role',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`membership_id`, `page_key`),
    KEY `idx_membership_page_key` (`page_key`),
    CONSTRAINT `fk_membership_page_override_membership`
        FOREIGN KEY (`membership_id`) REFERENCES `company_memberships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_role_data_scopes` (
    `role_id` BIGINT UNSIGNED NOT NULL,
    `entity_key` VARCHAR(32) NOT NULL,
    `scope` VARCHAR(16) NOT NULL DEFAULT 'none' COMMENT 'all|only_own|none',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`role_id`, `entity_key`),
    KEY `idx_role_data_entity` (`entity_key`),
    CONSTRAINT `fk_role_data_scope_role`
        FOREIGN KEY (`role_id`) REFERENCES `company_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_membership_data_overrides` (
    `membership_id` BIGINT UNSIGNED NOT NULL,
    `entity_key` VARCHAR(32) NOT NULL,
    `scope` VARCHAR(16) NOT NULL COMMENT 'all|only_own|none; absence means by role',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`membership_id`, `entity_key`),
    KEY `idx_membership_data_entity` (`entity_key`),
    CONSTRAINT `fk_membership_data_override_membership`
        FOREIGN KEY (`membership_id`) REFERENCES `company_memberships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
