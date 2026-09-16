CREATE TABLE IF NOT EXISTS `waste_trip_general_payments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `waste_trip_id` BIGINT UNSIGNED NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 1,
    `amount` DECIMAL(12,2) NULL,
    `payment_type` VARCHAR(50) NOT NULL,
    `payment_method` VARCHAR(20) NOT NULL DEFAULT 'cashless',
    `vat_rate` DECIMAL(5,2) NULL,
    `condition_type` VARCHAR(50) NOT NULL,
    `days_count` INT NULL,
    `days_kind` VARCHAR(10) NULL,
    `specific_due_date` DATE NULL,
    `condition_comment` TEXT NULL,
    `created_by_user_id` INT UNSIGNED NULL,
    `created_by_role` VARCHAR(20) NULL,
    `updated_by_user_id` INT UNSIGNED NULL,
    `updated_by_role` VARCHAR(20) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_waste_trip_general_payments_trip` (`waste_trip_id`,`sort_order`,`id`),
    CONSTRAINT `fk_waste_trip_general_payments_trip`
        FOREIGN KEY (`waste_trip_id`) REFERENCES `waste_trips` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE waste_trip_customer_payments MODIFY COLUMN amount DECIMAL(12,2) NULL;
-- Existing rows need the separately guarded financial transition; new rows use v2.
ALTER TABLE waste_trips ADD COLUMN financial_model_version TINYINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE waste_trips ALTER COLUMN financial_model_version SET DEFAULT 2;
