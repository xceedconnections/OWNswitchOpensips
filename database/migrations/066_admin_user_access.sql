-- Admin Access (vendor global toggles) + User Access (per-admin sub-users with menu grants).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'managed_by_user_id') = 0,
    'ALTER TABLE users ADD COLUMN managed_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER customer_id, ADD KEY idx_users_managed_by (managed_by_user_id)',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS user_feature_access (
    user_id     BIGINT UNSIGNED NOT NULL,
    feature_key VARCHAR(96) NOT NULL,
    enabled     TINYINT(1) NOT NULL DEFAULT 1,
    updated_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (user_id, feature_key),
    KEY ix_user_feature_key (feature_key),
    CONSTRAINT fk_user_feature_access_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
