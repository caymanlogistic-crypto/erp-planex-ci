SET @dbname = DATABASE();

CREATE TABLE IF NOT EXISTS `entity_assignments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entity_type` ENUM('client','contractor','vehicle_unit','driver') NOT NULL,
    `entity_id` BIGINT UNSIGNED NOT NULL,
    `membership_id` BIGINT UNSIGNED NOT NULL,
    `assigned_by_membership_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_entity_assignment` (`entity_type`,`entity_id`,`membership_id`),
    KEY `idx_assignment_membership_entity` (`membership_id`,`entity_type`,`entity_id`),
    KEY `idx_assignment_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `legacy_access_projections` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `legacy_grant_id` BIGINT UNSIGNED NOT NULL,
    `entity_type` ENUM('linear_trip','vehicle_set') NOT NULL,
    `entity_id` BIGINT UNSIGNED NOT NULL,
    `membership_id` BIGINT UNSIGNED NOT NULL,
    `access_level` ENUM('view','edit') NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_legacy_access_projection_grant` (`legacy_grant_id`),
    UNIQUE KEY `uk_legacy_access_projection_target` (`entity_type`,`entity_id`,`membership_id`),
    KEY `idx_legacy_access_projection_membership` (`membership_id`,`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='users' AND COLUMN_NAME='membership_id'),
    'SELECT 1',
    'ALTER TABLE `users` ADD COLUMN `membership_id` BIGINT UNSIGNED NULL AFTER `id`'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='users' AND INDEX_NAME='uk_users_membership_id'),
    'SELECT 1',
    'ALTER TABLE `users` ADD UNIQUE KEY `uk_users_membership_id` (`membership_id`)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='clients' AND COLUMN_NAME='created_by_membership_id'),
    'SELECT 1',
    'ALTER TABLE `clients` ADD COLUMN `created_by_membership_id` BIGINT UNSIGNED NULL AFTER `created_by_user_id`, ADD KEY `idx_clients_creator_membership` (`created_by_membership_id`)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='contractors' AND COLUMN_NAME='created_by_membership_id'),
    'SELECT 1',
    'ALTER TABLE `contractors` ADD COLUMN `created_by_membership_id` BIGINT UNSIGNED NULL AFTER `created_by_user_id`, ADD KEY `idx_contractors_creator_membership` (`created_by_membership_id`)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='vehicle_units' AND COLUMN_NAME='created_by_membership_id'),
    'SELECT 1',
    'ALTER TABLE `vehicle_units` ADD COLUMN `created_by_membership_id` BIGINT UNSIGNED NULL AFTER `created_by_user_id`, ADD KEY `idx_vehicle_units_creator_membership` (`created_by_membership_id`)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='drivers' AND COLUMN_NAME='created_by_membership_id'),
    'SELECT 1',
    'ALTER TABLE `drivers` ADD COLUMN `created_by_membership_id` BIGINT UNSIGNED NULL AFTER `created_by_user_id`, ADD KEY `idx_drivers_creator_membership` (`created_by_membership_id`)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='linear_routes' AND COLUMN_NAME='created_by_membership_id'),
    'SELECT 1',
    'ALTER TABLE `linear_routes` ADD COLUMN `created_by_membership_id` BIGINT UNSIGNED NULL AFTER `created_by_user_id`, ADD KEY `idx_linear_routes_creator_membership` (`created_by_membership_id`)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='vehicle_sets' AND COLUMN_NAME='created_by_membership_id'),
    'SELECT 1',
    'ALTER TABLE `vehicle_sets` ADD COLUMN `created_by_membership_id` BIGINT UNSIGNED NULL AFTER `created_by_user_id`, ADD KEY `idx_vehicle_sets_creator_membership` (`created_by_membership_id`)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='waste_trips' AND COLUMN_NAME='created_by_membership_id'),
    'SELECT 1',
    'ALTER TABLE `waste_trips` ADD COLUMN `created_by_membership_id` BIGINT UNSIGNED NULL AFTER `created_by_user_id`, ADD KEY `idx_waste_trips_creator_membership` (`created_by_membership_id`)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- Repairable index gates are separate from column gates so an interrupted or
-- partially pre-existing schema resumes safely.
SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='clients' AND INDEX_NAME='idx_clients_creator_membership'),
    'SELECT 1',
    'ALTER TABLE clients ADD KEY idx_clients_creator_membership (created_by_membership_id)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='contractors' AND INDEX_NAME='idx_contractors_creator_membership'),
    'SELECT 1',
    'ALTER TABLE contractors ADD KEY idx_contractors_creator_membership (created_by_membership_id)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='vehicle_units' AND INDEX_NAME='idx_vehicle_units_creator_membership'),
    'SELECT 1',
    'ALTER TABLE vehicle_units ADD KEY idx_vehicle_units_creator_membership (created_by_membership_id)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='drivers' AND INDEX_NAME='idx_drivers_creator_membership'),
    'SELECT 1',
    'ALTER TABLE drivers ADD KEY idx_drivers_creator_membership (created_by_membership_id)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='linear_routes' AND INDEX_NAME='idx_linear_routes_creator_membership'),
    'SELECT 1',
    'ALTER TABLE linear_routes ADD KEY idx_linear_routes_creator_membership (created_by_membership_id)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='vehicle_sets' AND INDEX_NAME='idx_vehicle_sets_creator_membership'),
    'SELECT 1',
    'ALTER TABLE vehicle_sets ADD KEY idx_vehicle_sets_creator_membership (created_by_membership_id)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='waste_trips' AND INDEX_NAME='idx_waste_trips_creator_membership'),
    'SELECT 1',
    'ALTER TABLE waste_trips ADD KEY idx_waste_trips_creator_membership (created_by_membership_id)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
