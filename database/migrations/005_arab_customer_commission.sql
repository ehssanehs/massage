-- 005: Arab customer flag and per-session commission snapshot.
-- NULL snapshot means ordinary commission; 0 means Arab commission at 0%.
-- Existing sessions remain ordinary; no historical rate is inferred or rewritten.
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'is_arab_customer'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE customers ADD COLUMN is_arab_customer TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
  'SELECT ''customers.is_arab_customer already exists'' AS note');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'massage_sessions' AND COLUMN_NAME = 'arab_commission_percent'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE massage_sessions ADD COLUMN arab_commission_percent DECIMAL(5,2) NULL AFTER final_amount',
  'SELECT ''massage_sessions.arab_commission_percent already exists'' AS note');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
