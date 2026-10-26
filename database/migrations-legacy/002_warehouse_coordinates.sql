-- Apply to the existing legacy warehouse database only after task-specific OWNER authorization.
ALTER TABLE warehouse_contacts
    ADD COLUMN latitude DECIMAL(10,7) NULL,
    ADD COLUMN longitude DECIMAL(10,7) NULL,
    ADD CONSTRAINT chk_warehouse_contacts_coordinates CHECK (
        (latitude IS NULL AND longitude IS NULL)
        OR (latitude IS NOT NULL AND longitude IS NOT NULL
            AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)
    );
