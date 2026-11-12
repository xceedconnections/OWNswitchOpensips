-- SIP user destination prefix rewrite (CLI tab): FROM=TO lines, e.g. 2=12 → 213… becomes 1213…
SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'dst_prefix_map') = 0,
  'ALTER TABLE sip_accounts ADD COLUMN dst_prefix_map TEXT NULL AFTER dst_format',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
