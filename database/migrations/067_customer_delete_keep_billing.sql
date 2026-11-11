-- Allow deleting a customer while keeping ledger / invoice / payment history.
-- Billing rows keep their amounts; customer_id becomes NULL (ON DELETE SET NULL).
-- Ledger UPDATE trigger is relaxed in app code so orphaning customer_id is allowed.

SET @db := DATABASE();

-- ledger_entries.customer_id → NULL + ON DELETE SET NULL
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ledger_entries' AND COLUMN_NAME = 'customer_id'
        AND IS_NULLABLE = 'NO'
    ),
    'ALTER TABLE ledger_entries MODIFY customer_id BIGINT UNSIGNED NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (
  SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ledger_entries' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_ledger_customer'
  LIMIT 1
);
SET @sql := IF(@fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE ledger_entries DROP FOREIGN KEY `', @fk, '`'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ledger_entries' AND CONSTRAINT_NAME = 'fk_ledger_customer'
    ),
    'SELECT 1',
    'ALTER TABLE ledger_entries ADD CONSTRAINT fk_ledger_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- invoices
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'customer_id'
        AND IS_NULLABLE = 'NO'
    ),
    'ALTER TABLE invoices MODIFY customer_id BIGINT UNSIGNED NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (
  SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'invoices' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_invoice_customer'
  LIMIT 1
);
SET @sql := IF(@fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE invoices DROP FOREIGN KEY `', @fk, '`'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'invoices' AND CONSTRAINT_NAME = 'fk_invoice_customer'
    ),
    'SELECT 1',
    'ALTER TABLE invoices ADD CONSTRAINT fk_invoice_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- payments
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'customer_id'
        AND IS_NULLABLE = 'NO'
    ),
    'ALTER TABLE payments MODIFY customer_id BIGINT UNSIGNED NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (
  SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payments' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_payment_customer'
  LIMIT 1
);
SET @sql := IF(@fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE payments DROP FOREIGN KEY `', @fk, '`'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payments' AND CONSTRAINT_NAME = 'fk_payment_customer'
    ),
    'SELECT 1',
    'ALTER TABLE payments ADD CONSTRAINT fk_payment_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- voucher_redemptions
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'voucher_redemptions' AND COLUMN_NAME = 'customer_id'
        AND IS_NULLABLE = 'NO'
    ),
    'ALTER TABLE voucher_redemptions MODIFY customer_id BIGINT UNSIGNED NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (
  SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'voucher_redemptions' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_voucher_redemption_customer'
  LIMIT 1
);
SET @sql := IF(@fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE voucher_redemptions DROP FOREIGN KEY `', @fk, '`'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'voucher_redemptions'
        AND CONSTRAINT_NAME = 'fk_voucher_redemption_customer'
    ),
    'SELECT 1',
    'ALTER TABLE voucher_redemptions ADD CONSTRAINT fk_voucher_redemption_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
