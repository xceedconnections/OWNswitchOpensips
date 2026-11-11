-- Hidden vendor operator + feature visibility matrix (admin vs customer portal).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_vendor'
    ),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN is_vendor TINYINT(1) NOT NULL DEFAULT 0 AFTER status, ADD KEY ix_users_vendor (is_vendor)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS feature_visibility (
    feature_key   VARCHAR(96) NOT NULL PRIMARY KEY,
    audience      VARCHAR(16) NOT NULL,
    enabled       TINYINT(1) NOT NULL DEFAULT 1,
    updated_by    BIGINT UNSIGNED NULL,
    updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    KEY ix_feature_audience (audience, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
