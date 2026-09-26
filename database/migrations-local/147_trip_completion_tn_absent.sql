ALTER TABLE `waste_trip_completion_items`
  ADD COLUMN `tn_absent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `tn_datetime_status`;
