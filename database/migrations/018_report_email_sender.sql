ALTER TABLE company_report_email_settings
  ADD COLUMN sender_profile_id BIGINT UNSIGNED NULL,
  ADD KEY idx_report_email_sender_profile (sender_profile_id),
  ADD CONSTRAINT fk_report_email_sender_profile FOREIGN KEY (sender_profile_id) REFERENCES employee_mail_profiles(id) ON DELETE SET NULL;
