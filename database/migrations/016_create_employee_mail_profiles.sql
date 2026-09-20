-- Two independent working-mail company profiles within the existing tenant.
-- These are not clients, contractors or new tenant companies.
CREATE TABLE IF NOT EXISTS employee_mail_settings (
    company_id INT UNSIGNED NOT NULL,
    imap_host VARCHAR(253) NOT NULL DEFAULT 'imap.spaceweb.ru',
    imap_port SMALLINT UNSIGNED NOT NULL DEFAULT 993,
    smtp_host VARCHAR(253) NOT NULL DEFAULT 'smtp.spaceweb.ru',
    smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 465,
    encryption VARCHAR(10) NOT NULL DEFAULT 'SSL',
    PRIMARY KEY (company_id),
    CONSTRAINT fk_employee_mail_settings_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    CONSTRAINT chk_employee_mail_provider CHECK (imap_host='imap.spaceweb.ru' AND imap_port=993 AND smtp_host='smtp.spaceweb.ru' AND smtp_port=465 AND encryption='SSL')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_mail_legal_entities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    code ENUM('planex','mg_log') NOT NULL,
    name VARCHAR(100) NOT NULL,
    email_domain VARCHAR(253) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_mail_legal_company_code (company_id,code),
    UNIQUE KEY uk_mail_legal_tenant (id,company_id),
    CONSTRAINT fk_mail_legal_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_mail_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    legal_entity_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_encrypted TEXT NOT NULL,
    connection_status ENUM('incomplete','unverified','connected','failed') NOT NULL DEFAULT 'incomplete',
    checked_at DATETIME NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_employee_mail_account_entity (account_id,company_id,legal_entity_id),
    UNIQUE KEY uk_employee_mail_email (email),
    CONSTRAINT fk_employee_mail_membership FOREIGN KEY (account_id,company_id)
        REFERENCES company_memberships (account_id,company_id) ON DELETE RESTRICT,
    CONSTRAINT fk_employee_mail_legal_entity FOREIGN KEY (legal_entity_id,company_id)
        REFERENCES employee_mail_legal_entities (id,company_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
