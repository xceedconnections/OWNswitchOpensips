-- Trunk CPS limit (-1 = unlimited). Channels already exist on carrier_trunks.
SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'max_cps') = 0,
  'ALTER TABLE carrier_trunks ADD COLUMN max_cps INT NOT NULL DEFAULT -1 AFTER max_channels',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Allow -1 (unlimited) on trunk channel cap as well (was UNSIGNED).
SET @sql := (
  SELECT IF(
    (SELECT DATA_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'max_channels') = 'int'
    AND (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'max_channels') LIKE '%unsigned%',
    'ALTER TABLE carrier_trunks MODIFY COLUMN max_channels INT NOT NULL DEFAULT 100',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
