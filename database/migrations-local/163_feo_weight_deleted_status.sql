ALTER TABLE feo_weight_batches
  MODIFY COLUMN status ENUM('applied','reverted','deleted') NOT NULL DEFAULT 'applied';
