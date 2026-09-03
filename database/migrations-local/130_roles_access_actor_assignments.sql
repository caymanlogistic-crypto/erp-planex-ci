-- Migration 130: roles/access tenant actor bridge + regulated object assignments.
-- Central membership ids are external identifiers here; no cross-database FK is possible.
SET @dbname = DATABASE();

SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='users' AND COLUMN_NAME='global_user_id') > 0,
    'SELECT 1 AS global_user_id_already_exists',
    'ALTER TABLE `users` ADD COLUMN `global_user_id` BIGINT UNSIGNED DEFAULT NULL COMMENT ''central company_users.id; auth authority is central'''
));
PREPARE addGlobalUserId FROM @preparedStatement;
EXECUTE addGlobalUserId;
DEALLOCATE PREPARE addGlobalUserId;

SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='users' AND COLUMN_NAME='membership_id') > 0,
    'SELECT 1 AS membership_id_already_exists',
    'ALTER TABLE `users` ADD COLUMN `membership_id` BIGINT UNSIGNED DEFAULT NULL COMMENT ''central company_memberships.id'''
));
PREPARE addMembershipId FROM @preparedStatement;
EXECUTE addMembershipId;
DEALLOCATE PREPARE addMembershipId;

SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='users' AND INDEX_NAME='uk_users_membership_id') > 0,
    'SELECT 1 AS users_membership_unique_already_exists',
    'ALTER TABLE `users` ADD UNIQUE KEY `uk_users_membership_id` (`membership_id`)'
));
PREPARE addMembershipUnique FROM @preparedStatement;
EXECUTE addMembershipUnique;
DEALLOCATE PREPARE addMembershipUnique;

SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='users' AND INDEX_NAME='idx_users_global_user_id') > 0,
    'SELECT 1 AS users_global_user_index_already_exists',
    'ALTER TABLE `users` ADD KEY `idx_users_global_user_id` (`global_user_id`)'
));
PREPARE addGlobalUserIndex FROM @preparedStatement;
EXECUTE addGlobalUserIndex;
DEALLOCATE PREPARE addGlobalUserIndex;

CREATE TABLE IF NOT EXISTS `company_user_assignments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entity_type` VARCHAR(32) NOT NULL COMMENT 'client|contractor|vehicle_unit|driver',
    `entity_id` BIGINT UNSIGNED NOT NULL,
    `membership_id` BIGINT UNSIGNED NOT NULL COMMENT 'central company_memberships.id',
    `assigned_by_membership_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_company_user_assignment` (`entity_type`, `entity_id`, `membership_id`),
    KEY `idx_company_user_assignment_membership` (`membership_id`, `entity_type`, `entity_id`),
    KEY `idx_company_user_assignment_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
