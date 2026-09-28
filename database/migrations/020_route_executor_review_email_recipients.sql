CREATE TABLE IF NOT EXISTS company_route_executor_review_email_settings (
    company_id INT UNSIGNED NOT NULL,
    send_responsible_logist TINYINT(1) NOT NULL DEFAULT 0,
    updated_by_membership_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id),
    CONSTRAINT fk_route_executor_review_email_settings_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_route_executor_review_email_recipients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NULL,
    display_name VARCHAR(255) NOT NULL,
    email VARCHAR(254) NOT NULL,
    position_no INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_route_executor_review_email_company_address (company_id,email),
    KEY idx_route_executor_review_email_company_position (company_id,position_no,id),
    CONSTRAINT fk_route_executor_review_email_recipient_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_route_executor_review_email_recipient_account FOREIGN KEY (account_id) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
