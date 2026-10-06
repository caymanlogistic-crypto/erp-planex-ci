ALTER TABLE waste_trip_requests
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER position_no;
