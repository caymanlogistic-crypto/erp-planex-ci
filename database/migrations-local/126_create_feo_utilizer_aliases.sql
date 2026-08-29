CREATE TABLE `feo_utilizer_aliases` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `utilizer_id` BIGINT UNSIGNED NOT NULL,
    `alias` VARCHAR(500) NOT NULL,
    `normalized_alias` VARCHAR(500) NOT NULL,
    `created_by_user_id` BIGINT UNSIGNED NULL,
    `updated_by_user_id` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_feo_utilizer_alias_normalized` (`normalized_alias`),
    KEY `idx_feo_utilizer_alias_utilizer` (`utilizer_id`),
    CONSTRAINT `fk_feo_utilizer_alias_utilizer`
        FOREIGN KEY (`utilizer_id`) REFERENCES `feo_utilizers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration 126 intentionally does not copy the legacy 38-rule engine into aliases.
-- Existing rule/override tables are preserved as archive data, but runtime matching after
-- this migration uses only utilizer full/short names plus owner-managed aliases.
