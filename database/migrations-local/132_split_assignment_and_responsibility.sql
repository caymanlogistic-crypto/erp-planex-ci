SET @dbname = DATABASE();

-- Existing rows predate provenance tracking. They must remain explicitly
-- unclassified: membership/creator equality is not proof of origin.
SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='entity_assignments' AND COLUMN_NAME='assignment_kind'),
    "ALTER TABLE `entity_assignments` MODIFY COLUMN `assignment_kind` ENUM('legacy_unclassified','creator_auto','manual') NOT NULL DEFAULT 'legacy_unclassified'",
    "ALTER TABLE `entity_assignments` ADD COLUMN `assignment_kind` ENUM('legacy_unclassified','creator_auto','manual') NOT NULL DEFAULT 'legacy_unclassified' AFTER `assigned_by_membership_id`"
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- A single membership may legitimately have both creator_auto and manual
-- provenance for the same object. Preserve that distinction physically.
SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='entity_assignments' AND INDEX_NAME='uk_entity_assignment'),
    'ALTER TABLE `entity_assignments` DROP INDEX `uk_entity_assignment`',
    'SELECT 1'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='entity_assignments' AND INDEX_NAME='uk_entity_assignment_kind'),
    'SELECT 1',
    'ALTER TABLE `entity_assignments` ADD UNIQUE KEY `uk_entity_assignment_kind` (`entity_type`,`entity_id`,`membership_id`,`assignment_kind`)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='entity_assignments' AND INDEX_NAME='idx_assignment_kind'),
    'SELECT 1',
    'ALTER TABLE `entity_assignments` ADD KEY `idx_assignment_kind` (`entity_type`,`assignment_kind`,`membership_id`,`entity_id`)'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS `entity_responsibilities` (
    `entity_type` ENUM('client','contractor','vehicle_unit','driver','vehicle_set','route_executor') NOT NULL,
    `entity_id` BIGINT UNSIGNED NOT NULL,
    `membership_id` BIGINT UNSIGNED NOT NULL,
    `assigned_by_membership_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`entity_type`,`entity_id`),
    KEY `idx_entity_responsibility_member` (`membership_id`,`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
