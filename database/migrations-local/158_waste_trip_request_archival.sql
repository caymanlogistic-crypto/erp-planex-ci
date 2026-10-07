ALTER TABLE waste_trip_requests
  ADD COLUMN removed_at DATETIME NULL AFTER position_no,
  ADD KEY idx_waste_trip_requests_active (waste_trip_id, removed_at, position_no, id);
