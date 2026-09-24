ALTER TABLE `waste_trip_completion_items`
  ADD COLUMN `sender_signature_status` VARCHAR(3) NULL AFTER `sender_signature_present`,
  ADD COLUMN `driver_signature_status` VARCHAR(3) NULL AFTER `driver_signature_present`,
  ADD COLUMN `tn_datetime_status` VARCHAR(7) NULL AFTER `tn_datetime_missing`,
  ADD COLUMN `recovery_source` VARCHAR(8) NULL AFTER `data_invented`;
