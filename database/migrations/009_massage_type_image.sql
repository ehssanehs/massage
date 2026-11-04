-- Real photo for a massage type, uploaded from the panel. The bundled
-- illustrations stay as the fallback when this is empty.
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'massage_types' AND COLUMN_NAME = 'image') = 0,
  'ALTER TABLE massage_types ADD COLUMN image VARCHAR(255) NULL AFTER illustration',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
