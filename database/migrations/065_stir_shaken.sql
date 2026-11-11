-- STIR/SHAKEN certificates (System Settings) and per-SIP-user selection / attestation.

CREATE TABLE IF NOT EXISTS stir_certificates (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(120) NOT NULL,
    public_token     CHAR(32) NOT NULL,
    cert_pem         MEDIUMTEXT NOT NULL,
    private_key_enc  MEDIUMTEXT NOT NULL,
    x5u_url          VARCHAR(512) NULL,
    origid           VARCHAR(64) NOT NULL,
    issuer           VARCHAR(255) NULL,
    subject          VARCHAR(255) NULL,
    valid_from       DATETIME(3) NULL,
    valid_to         DATETIME(3) NULL,
    status           VARCHAR(16) NOT NULL DEFAULT 'active',
    notes            VARCHAR(255) NULL,
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_stir_certificates_name (name),
    UNIQUE KEY uq_stir_certificates_token (public_token),
    KEY ix_stir_certificates_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'stir_certificate_id') = 0,
    'ALTER TABLE sip_accounts ADD COLUMN stir_certificate_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER callerid_group_id, ADD KEY idx_sip_accounts_stir_cert (stir_certificate_id)',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'stir_attestation') = 0,
    'ALTER TABLE sip_accounts ADD COLUMN stir_attestation VARCHAR(1) NULL DEFAULT NULL AFTER stir_certificate_id',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
