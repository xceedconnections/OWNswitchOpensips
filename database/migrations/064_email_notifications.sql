-- Customer notification email + portal user opt-in for service emails (refills, etc.).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'email_address'
    ),
    'SELECT 1',
    'ALTER TABLE customers ADD COLUMN email_address VARCHAR(190) NULL AFTER contact_email'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_notifications'
    ),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN email_notifications TINYINT(1) NOT NULL DEFAULT 1 AFTER timezone'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Seed SMTP / refill-mail settings when missing.
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
 ('smtp_provider', 'custom'),
 ('smtp_host', ''),
 ('smtp_port', '587'),
 ('smtp_encryption', 'tls'),
 ('smtp_username', ''),
 ('smtp_password', ''),
 ('smtp_from_email', ''),
 ('smtp_from_name', ''),
 ('email_on_refill_enabled', '0');
