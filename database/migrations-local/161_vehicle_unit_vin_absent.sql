-- Distinguish a confirmed absence of VIN from an incomplete vehicle record.
ALTER TABLE vehicle_units
    ADD COLUMN vin_absent TINYINT(1) NOT NULL DEFAULT 0 AFTER vin,
    ADD CONSTRAINT chk_vehicle_unit_vin_absent CHECK (vin_absent IN (0,1) AND (vin_absent = 0 OR vin IS NULL));
