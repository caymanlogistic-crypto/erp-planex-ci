CREATE TABLE IF NOT EXISTS `max_event_rules` (
    `event_key` VARCHAR(100) NOT NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `chat_config_id` BIGINT UNSIGNED DEFAULT NULL,
    `message_template` TEXT NOT NULL,
    `message_format` ENUM('plain','html','markdown') NOT NULL DEFAULT 'plain',
    `notify` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`event_key`),
    CONSTRAINT `fk_max_event_rule_chat` FOREIGN KEY (`chat_config_id`) REFERENCES `max_chats` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `max_event_deliveries` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_key` VARCHAR(100) NOT NULL,
    `entity_key` VARCHAR(100) NOT NULL,
    `chat_config_id` BIGINT UNSIGNED NOT NULL,
    `bot_id` BIGINT UNSIGNED NOT NULL,
    `external_chat_id` VARCHAR(32) NOT NULL,
    `message_text` TEXT NOT NULL,
    `message_format` ENUM('plain','html','markdown') NOT NULL DEFAULT 'plain',
    `notify` TINYINT(1) NOT NULL DEFAULT 1,
    `initiated_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
    `status` ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `error_message` VARCHAR(2000) DEFAULT NULL,
    `max_message_id` VARCHAR(128) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_max_event_entity` (`event_key`,`entity_key`),
    KEY `idx_max_event_delivery_status` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
