-- One preferred working mailbox per employee and company for trip reports.
-- Existing profiles remain unchanged. A single connected mailbox is used implicitly.
ALTER TABLE employee_mail_profiles
  ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER connection_status,
  ADD CONSTRAINT chk_employee_mail_default_sender CHECK (is_default IN (0,1));
