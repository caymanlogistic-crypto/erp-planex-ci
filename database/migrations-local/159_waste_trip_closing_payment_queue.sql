ALTER TABLE waste_trip_closing_reviews
  MODIFY COLUMN state ENUM('work','sending','review','failed','unknown','payment') NOT NULL DEFAULT 'work';
