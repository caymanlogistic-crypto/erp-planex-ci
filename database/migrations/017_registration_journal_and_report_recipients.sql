CREATE TABLE IF NOT EXISTS company_report_email_settings (
    company_id INT UNSIGNED NOT NULL,
    send_responsible_logist TINYINT(1) NOT NULL DEFAULT 0,
    updated_by_membership_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id),
    CONSTRAINT fk_report_email_settings_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_report_email_recipients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NULL,
    display_name VARCHAR(255) NOT NULL,
    email VARCHAR(254) NOT NULL,
    position_no INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_report_email_company_address (company_id,email),
    KEY idx_report_email_company_position (company_id,position_no,id),
    CONSTRAINT fk_report_email_recipient_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_report_email_recipient_account FOREIGN KEY (account_id) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The journal has its own grantable page. Initially copy the existing registration
-- permission; future changes are independent in Roles & Access.
INSERT INTO role_page_permissions (role_id,page_key,is_allowed)
SELECT role_id,'company.registration_journal',is_allowed
FROM role_page_permissions WHERE page_key='company.documents.collection'
ON DUPLICATE KEY UPDATE is_allowed=VALUES(is_allowed);

INSERT INTO membership_page_overrides (membership_id,page_key,effect)
SELECT membership_id,'company.registration_journal',effect
FROM membership_page_overrides WHERE page_key='company.documents.collection'
ON DUPLICATE KEY UPDATE effect=VALUES(effect);

-- OWNER explicitly named enshina for the initial journal attribution and access.
INSERT INTO membership_page_overrides (membership_id,page_key,effect)
SELECT m.id,'company.registration_journal','allow'
FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id
WHERE a.normalized_login='enshina' AND m.status='active' AND a.status='active'
ON DUPLICATE KEY UPDATE effect=VALUES(effect);
