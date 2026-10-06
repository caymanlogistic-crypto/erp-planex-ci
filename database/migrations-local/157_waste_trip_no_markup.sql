ALTER TABLE waste_trips
  ADD COLUMN no_markup TINYINT(1) NOT NULL DEFAULT 0 AFTER financial_model_version;
