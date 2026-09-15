ALTER TABLE `waste_trips`
  MODIFY COLUMN `status` ENUM('planned_route','searching_executor','executor_assigned','in_progress','completed','cancelled') NOT NULL DEFAULT 'searching_executor',
  ADD COLUMN `deleted_at` DATETIME NULL,
  ADD KEY `idx_waste_trips_deleted` (`deleted_at`,`business_id`);
