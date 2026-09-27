ALTER TABLE waste_trips
  ADD COLUMN legal_entity_code VARCHAR(32) NULL,
  ADD COLUMN legacy_route_type VARCHAR(64) NULL,
  ADD COLUMN legacy_unload_type VARCHAR(32) NULL,
  ADD COLUMN legacy_source_warehouse_id BIGINT NULL,
  ADD COLUMN legacy_destination_warehouse_id BIGINT NULL,
  ADD COLUMN legacy_source_warehouse_name VARCHAR(255) NULL,
  ADD COLUMN legacy_destination_warehouse_name VARCHAR(255) NULL;
