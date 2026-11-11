-- 060: Trunk Delayed Bye / MCD (carrier leg hold + optional buy-cost duration)

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'delayed_bye') = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN delayed_bye INT UNSIGNED NOT NULL DEFAULT 0 AFTER max_call_duration',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'delayed_bye_mcd') = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN delayed_bye_mcd INT UNSIGNED NOT NULL DEFAULT 0 AFTER delayed_bye',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'delayed_bye_count_buying') = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN delayed_bye_count_buying TINYINT(1) NOT NULL DEFAULT 0 AFTER delayed_bye_mcd',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
