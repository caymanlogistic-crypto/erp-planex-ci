CREATE TABLE IF NOT EXISTS `waste_trip_start_statistics` (
  `waste_trip_id` BIGINT UNSIGNED NOT NULL,
  `actual_start_date` DATE NOT NULL,
  `responsible_user_id` INT UNSIGNED NOT NULL,
  `responsible_membership_id` BIGINT UNSIGNED NULL,
  `responsible_name_snapshot` VARCHAR(255) NOT NULL,
  `request_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_mass_kg` DECIMAL(18,3) NOT NULL DEFAULT 0.000,
  `is_backfilled` TINYINT(1) NOT NULL DEFAULT 0,
  `captured_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`waste_trip_id`),
  KEY `idx_waste_trip_start_stats_date` (`actual_start_date`,`waste_trip_id`),
  KEY `idx_waste_trip_start_stats_manager` (`responsible_membership_id`,`actual_start_date`,`waste_trip_id`),
  CONSTRAINT `fk_waste_trip_start_stats_trip`
    FOREIGN KEY (`waste_trip_id`) REFERENCES `waste_trips` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_waste_trip_start_stats_user`
    FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `waste_trip_start_statistics`
  (`waste_trip_id`,`actual_start_date`,`responsible_user_id`,`responsible_membership_id`,
   `responsible_name_snapshot`,`request_count`,`total_mass_kg`,`is_backfilled`)
SELECT wt.id,
       wt.actual_start_date,
       wt.responsible_user_id,
       u.membership_id,
       u.full_name,
       COUNT(wtr.id),
       COALESCE(SUM(wtr.snapshot_mass_kg),0),
       1
  FROM waste_trips wt
  JOIN users u ON u.id=wt.responsible_user_id
  LEFT JOIN waste_trip_requests wtr ON wtr.waste_trip_id=wt.id
 WHERE wt.actual_start_date IS NOT NULL
   AND wt.status IN ('in_progress','completed','cancelled')
 GROUP BY wt.id,wt.actual_start_date,wt.responsible_user_id,u.membership_id,u.full_name
ON DUPLICATE KEY UPDATE
  actual_start_date=VALUES(actual_start_date),
  request_count=VALUES(request_count),
  total_mass_kg=VALUES(total_mass_kg),
  updated_at=CURRENT_TIMESTAMP;
