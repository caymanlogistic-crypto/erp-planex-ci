CREATE TABLE IF NOT EXISTS `user_accounts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name` VARCHAR(255) NOT NULL,
    `login` VARCHAR(100) NOT NULL,
    `normalized_login` VARCHAR(100) NOT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `status` ENUM('active','blocked') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_accounts_normalized_login` (`normalized_login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_roles` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `normalized_name` VARCHAR(100) NOT NULL,
    `system_key` VARCHAR(50) DEFAULT NULL,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_company_roles_id_company` (`id`,`company_id`),
    UNIQUE KEY `uk_company_roles_name` (`company_id`,`normalized_name`),
    UNIQUE KEY `uk_company_roles_system` (`company_id`,`system_key`),
    CONSTRAINT `fk_company_roles_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `chk_company_roles_system` CHECK ((`is_system` = 0 AND `system_key` IS NULL) OR (`is_system` = 1 AND `system_key` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_memberships` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_id` BIGINT UNSIGNED NOT NULL,
    `company_id` INT UNSIGNED NOT NULL,
    `role_id` BIGINT UNSIGNED NOT NULL,
    `status` ENUM('active','blocked') NOT NULL DEFAULT 'active',
    `legacy_source` ENUM('central_company_user','tenant_user') DEFAULT NULL,
    `legacy_source_id` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_membership_account_company` (`account_id`,`company_id`),
    UNIQUE KEY `uk_membership_legacy_source` (`company_id`,`legacy_source`,`legacy_source_id`),
    CONSTRAINT `chk_membership_legacy_source` CHECK ((`legacy_source` IS NULL AND `legacy_source_id` IS NULL) OR (`legacy_source` IS NOT NULL AND `legacy_source_id` IS NOT NULL)),
    KEY `idx_membership_company_role_status` (`company_id`,`role_id`,`status`),
    CONSTRAINT `fk_membership_account` FOREIGN KEY (`account_id`) REFERENCES `user_accounts` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_membership_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_membership_role_company` FOREIGN KEY (`role_id`,`company_id`) REFERENCES `company_roles` (`id`,`company_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_page_permissions` (
    `role_id` BIGINT UNSIGNED NOT NULL,
    `page_key` VARCHAR(191) NOT NULL,
    `is_allowed` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`role_id`,`page_key`),
    CONSTRAINT `fk_role_page_role` FOREIGN KEY (`role_id`) REFERENCES `company_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_data_scopes` (
    `role_id` BIGINT UNSIGNED NOT NULL,
    `entity_type` ENUM('client','contractor','vehicle_unit','driver','linear_trip','waste_trip') NOT NULL,
    `scope` ENUM('all','own','none') NOT NULL DEFAULT 'none',
    PRIMARY KEY (`role_id`,`entity_type`),
    CONSTRAINT `fk_role_scope_role` FOREIGN KEY (`role_id`) REFERENCES `company_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `membership_page_overrides` (
    `membership_id` BIGINT UNSIGNED NOT NULL,
    `page_key` VARCHAR(191) NOT NULL,
    `effect` ENUM('allow','deny') NOT NULL,
    PRIMARY KEY (`membership_id`,`page_key`),
    CONSTRAINT `fk_membership_page_membership` FOREIGN KEY (`membership_id`) REFERENCES `company_memberships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `membership_data_scope_overrides` (
    `membership_id` BIGINT UNSIGNED NOT NULL,
    `entity_type` ENUM('client','contractor','vehicle_unit','driver','linear_trip','waste_trip') NOT NULL,
    `scope` ENUM('all','own','none') NOT NULL,
    PRIMARY KEY (`membership_id`,`entity_type`),
    CONSTRAINT `fk_membership_scope_membership` FOREIGN KEY (`membership_id`) REFERENCES `company_memberships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_access_readiness` (
    `company_id` INT UNSIGNED NOT NULL,
    `state` ENUM('blocked','snapshotted','migrated','verified','ready') NOT NULL DEFAULT 'blocked',
    `before_sha256` CHAR(64) DEFAULT NULL,
    `after_sha256` CHAR(64) DEFAULT NULL,
    `comparison_sha256` CHAR(64) DEFAULT NULL,
    `migration_key` VARCHAR(100) DEFAULT NULL,
    `verified_at` DATETIME DEFAULT NULL,
    `activated_at` DATETIME DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`company_id`),
    CONSTRAINT `fk_access_readiness_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `chk_access_ready_proof` CHECK (`state` <> 'ready' OR (`before_sha256` IS NOT NULL AND `after_sha256` IS NOT NULL AND `comparison_sha256` IS NOT NULL AND `verified_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
