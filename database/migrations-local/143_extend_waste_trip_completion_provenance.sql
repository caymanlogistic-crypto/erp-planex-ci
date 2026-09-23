ALTER TABLE `waste_trip_completion_items`
  ADD COLUMN `tn_datetime_missing` TINYINT(1) NOT NULL DEFAULT 0 AFTER `sender_signature_present`,
  ADD COLUMN `data_from_tracker` TINYINT(1) NOT NULL DEFAULT 0 AFTER `tn_datetime_missing`,
  ADD COLUMN `data_from_driver` TINYINT(1) NOT NULL DEFAULT 0 AFTER `data_from_tracker`,
  ADD COLUMN `data_invented` TINYINT(1) NOT NULL DEFAULT 0 AFTER `data_from_driver`;
