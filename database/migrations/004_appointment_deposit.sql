-- 004: appointment deposit (بیعانه / پیش‌پرداخت)
-- Optional decimal amount recorded when a deposit is taken for an appointment.
-- 0 = no deposit; positive = deposit taken. All ALTERs are idempotent so re-running
-- `bin/console migrate` never fails on an already-upgraded database.
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'deposit_amount'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE appointments ADD COLUMN deposit_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER status',
  'SELECT ''appointments.deposit_amount already exists'' AS note');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
