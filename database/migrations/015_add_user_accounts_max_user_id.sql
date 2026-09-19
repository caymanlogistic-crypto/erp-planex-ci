ALTER TABLE `user_accounts`
    ADD COLUMN `max_user_id` VARCHAR(32) NULL AFTER `phone`,
    ADD UNIQUE KEY `uk_user_accounts_max_user_id` (`max_user_id`);
