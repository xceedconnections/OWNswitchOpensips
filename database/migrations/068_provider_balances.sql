-- Provider account balances (refill + buy-side call charges). Never blocks calls when empty.

CREATE TABLE IF NOT EXISTS carrier_balances (
    carrier_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    balance    DECIMAL(18,6) NOT NULL DEFAULT 0,
    currency   CHAR(3) NOT NULL DEFAULT 'USD',
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_carrier_balances_carrier FOREIGN KEY (carrier_id) REFERENCES carriers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS carrier_ledger_entries (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    carrier_id         BIGINT UNSIGNED NOT NULL,
    entry_type         VARCHAR(24) NOT NULL,
    amount             DECIMAL(18,6) NOT NULL,
    currency           CHAR(3) NOT NULL DEFAULT 'USD',
    affects_balance    TINYINT(1) NOT NULL DEFAULT 1,
    cdr_id             BIGINT UNSIGNED NULL,
    source_system      VARCHAR(32) NULL,
    external_ref       VARCHAR(191) NULL,
    idempotency_key    VARCHAR(191) NOT NULL,
    description        VARCHAR(255) NULL,
    created_by         BIGINT UNSIGNED NULL,
    created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_carrier_ledger_idem (idempotency_key),
    KEY ix_carrier_ledger_carrier (carrier_id, created_at),
    KEY ix_carrier_ledger_type (entry_type, created_at),
    CONSTRAINT fk_carrier_ledger_carrier FOREIGN KEY (carrier_id) REFERENCES carriers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed a zero balance row for every existing provider.
INSERT IGNORE INTO carrier_balances (carrier_id, balance, currency)
SELECT id, 0, 'USD' FROM carriers;
