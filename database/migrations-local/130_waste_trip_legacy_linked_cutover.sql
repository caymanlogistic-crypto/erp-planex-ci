ALTER TABLE `waste_trips`
  ADD COLUMN `business_id` BIGINT UNSIGNED NULL AFTER `id`,
  ADD COLUMN `planned_finish_date` DATE NULL AFTER `planned_start_date_to`,
  ADD COLUMN `legacy_sync_error` VARCHAR(1000) NULL AFTER `legacy_flight_id`,
  ADD COLUMN `legacy_last_checked_at` DATETIME NULL AFTER `legacy_sync_error`,
  ADD COLUMN `legacy_last_success_at` DATETIME NULL AFTER `legacy_last_checked_at`;

CREATE TABLE `waste_trip_namespace_state` (
  `singleton_id` TINYINT UNSIGNED NOT NULL,
  `legacy_high_water_at_cutover` BIGINT UNSIGNED NOT NULL,
  `last_issued_business_id` BIGINT UNSIGNED NOT NULL,
  `cutover_started_at` DATETIME NULL,
  `cutover_completed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`singleton_id`),
  CONSTRAINT `chk_waste_trip_namespace_singleton` CHECK (`singleton_id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
