CREATE TABLE IF NOT EXISTS `max_bots` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `token_encrypted` MEDIUMTEXT NOT NULL,
    `bot_user_id` VARCHAR(32) DEFAULT NULL,
    `bot_first_name` VARCHAR(255) DEFAULT NULL,
    `bot_username` VARCHAR(255) DEFAULT NULL,
    `webhook_secret_encrypted` MEDIUMTEXT NOT NULL,
    `webhook_status` ENUM('not_configured','active','error') NOT NULL DEFAULT 'not_configured',
    `webhook_last_error` VARCHAR(1000) DEFAULT NULL,
    `verified_at` DATETIME DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_max_bots_active` (`is_active`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `max_chats` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bot_id` BIGINT UNSIGNED NOT NULL,
    `chat_id` VARCHAR(32) NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `max_title` VARCHAR(255) DEFAULT NULL,
    `chat_type` ENUM('chat','channel','dialog','unknown') NOT NULL DEFAULT 'unknown',
    `max_status` ENUM('active','removed','left','closed','unknown') NOT NULL DEFAULT 'unknown',
    `purpose` VARCHAR(500) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `discovered_via` ENUM('manual','webhook') NOT NULL DEFAULT 'manual',
    `last_verified_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_max_chat_per_bot` (`bot_id`,`chat_id`),
    KEY `idx_max_chats_active` (`is_active`,`bot_id`,`id`),
    CONSTRAINT `fk_max_chats_bot` FOREIGN KEY (`bot_id`) REFERENCES `max_bots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `max_message_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bot_id` BIGINT UNSIGNED DEFAULT NULL,
    `chat_config_id` BIGINT UNSIGNED DEFAULT NULL,
    `external_chat_id` VARCHAR(32) DEFAULT NULL,
    `source` ENUM('test','manual','event') NOT NULL,
    `message_text` TEXT NOT NULL,
    `message_format` ENUM('plain','markdown','html') NOT NULL DEFAULT 'plain',
    `notify` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('sent','failed') NOT NULL,
    `max_message_id` VARCHAR(128) DEFAULT NULL,
    `error_message` VARCHAR(2000) DEFAULT NULL,
    `initiated_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_max_message_logs_created` (`created_at`,`id`),
    KEY `idx_max_message_logs_status` (`status`,`created_at`),
    CONSTRAINT `fk_max_message_logs_bot` FOREIGN KEY (`bot_id`) REFERENCES `max_bots` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_max_message_logs_chat` FOREIGN KEY (`chat_config_id`) REFERENCES `max_chats` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
