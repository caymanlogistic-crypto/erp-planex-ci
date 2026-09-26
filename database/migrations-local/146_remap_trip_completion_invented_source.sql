-- OWNER approved on 2026-09-26: former "invented" selections become
-- "from driver/manager". Preserve all other request fields and rows.
UPDATE waste_trip_completion_items
   SET recovery_source = 'driver',
       data_from_driver = 1,
       data_from_tracker = 0,
       data_invented = 0
 WHERE recovery_source = 'invented' OR data_invented = 1;
