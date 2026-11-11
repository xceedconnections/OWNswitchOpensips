-- Last IP seen from the web dialer, so a direct-media CDR can name the user
-- instead of the nginx loopback FreeSWITCH sees on /sip-ws.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'web_client_ip'
    ),
    'SELECT 1',
    'ALTER TABLE sip_accounts ADD COLUMN web_client_ip VARCHAR(64) NULL AFTER media_policy, ADD COLUMN web_client_ip_at DATETIME(3) NULL AFTER web_client_ip'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
