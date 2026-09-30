-- Keep archived crews for history while reserving a contractor/driver/vehicle
-- combination only for a crew whose deleted_at is still NULL.
SET @planex_schema = DATABASE();
SET @planex_has_active_key = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @planex_schema AND TABLE_NAME = 'crews' AND COLUMN_NAME = 'active_crew_key'
);
SET @planex_sql = IF(
  @planex_has_active_key > 0,
  'SELECT 1',
  'ALTER TABLE crews ADD COLUMN active_crew_key TINYINT GENERATED ALWAYS AS (IF(deleted_at IS NULL, 1, NULL)) STORED'
);
PREPARE planex_stmt FROM @planex_sql; EXECUTE planex_stmt; DEALLOCATE PREPARE planex_stmt;

SET @planex_crew_index = (
  SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @planex_schema AND TABLE_NAME = 'crews' AND INDEX_NAME = 'uk_crew'
);
SET @planex_sql = CASE
  WHEN @planex_crew_index = 'contractor_id,driver_vehicle_block_id,active_crew_key' THEN 'SELECT 1'
  WHEN @planex_crew_index IS NULL THEN
    'ALTER TABLE crews ADD UNIQUE KEY uk_crew (contractor_id, driver_vehicle_block_id, active_crew_key)'
  ELSE
    'ALTER TABLE crews DROP INDEX uk_crew, ADD UNIQUE KEY uk_crew (contractor_id, driver_vehicle_block_id, active_crew_key)'
END;
PREPARE planex_stmt FROM @planex_sql; EXECUTE planex_stmt; DEALLOCATE PREPARE planex_stmt;
