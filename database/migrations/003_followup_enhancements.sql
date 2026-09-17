-- 003: followup enhancements
-- customer_timeline gains soft-delete; timeline rendering filters deleted rows.
-- All ALTERs are idempotent so re-running `bin/console migrate` never fails.
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_timeline' AND COLUMN_NAME = 'deleted_at'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE customer_timeline ADD COLUMN deleted_at DATETIME NULL AFTER created_at',
  'SELECT ''customer_timeline.deleted_at already exists'' AS note');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_timeline' AND INDEX_NAME = 'idx_timeline_active'
);
SET @ddl := IF(@idx_exists = 0,
  'ALTER TABLE customer_timeline ADD INDEX idx_timeline_active (customer_id, created_at)',
  'SELECT ''idx_timeline_active already exists'' AS note');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
