-- Apply only to the configured legacy warehouse database after separate OWNER authorization.
CREATE TABLE IF NOT EXISTS warehouse_contacts (
    warehouse_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    contacts_json JSON NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CHECK (JSON_TYPE(contacts_json) = 'ARRAY')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
