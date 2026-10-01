CREATE TABLE IF NOT EXISTS warehouse_stock_issue_exceptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    flight_id BIGINT NOT NULL,
    message_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    message_text TEXT NOT NULL,
    trip_name VARCHAR(255) NOT NULL DEFAULT '',
    warehouse_name VARCHAR(255) NOT NULL DEFAULT '',
    approved_by_user_id BIGINT NOT NULL,
    approved_at DATETIME(6) NOT NULL,
    revoked_by_user_id BIGINT NULL,
    revoked_at DATETIME(6) NULL,
    UNIQUE KEY ux_warehouse_stock_issue_exception (flight_id, message_hash),
    KEY ix_warehouse_stock_issue_exception_date (approved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
