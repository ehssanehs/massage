-- Online booking needs to be recognisable in the staff panel: where an
-- appointment came from, and the reference the guest holds.
--
-- Migrations re-run on every `bin/console migrate` (there is no ledger table),
-- so each statement is guarded and becomes a no-op once applied.

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'source') = 0,
  "ALTER TABLE appointments ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'staff' AFTER status",
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'booking_ref') = 0,
  'ALTER TABLE appointments ADD COLUMN booking_ref VARCHAR(12) NULL AFTER source',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'uq_appointment_booking_ref') = 0,
  'ALTER TABLE appointments ADD UNIQUE KEY uq_appointment_booking_ref (booking_ref)',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'idx_appointment_source') = 0,
  'ALTER TABLE appointments ADD INDEX idx_appointment_source (source, status)',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
