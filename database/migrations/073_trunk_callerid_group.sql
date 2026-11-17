-- 073: Trunk can force a CallerID group (vendor + CDR), ignoring SIP user / customer CLI.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'callerid_group_id') = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN callerid_group_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER dnc_group_id, ADD KEY idx_carrier_trunks_callerid_group (callerid_group_id)',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND CONSTRAINT_NAME = 'fk_carrier_trunks_callerid_group') = 0,
    'ALTER TABLE carrier_trunks ADD CONSTRAINT fk_carrier_trunks_callerid_group FOREIGN KEY (callerid_group_id) REFERENCES callerid_groups (id) ON DELETE SET NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'callerid_group_numbers' AND INDEX_NAME = 'idx_cgn_cli') = 0,
    'ALTER TABLE callerid_group_numbers ADD KEY idx_cgn_cli (cli_number)',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
