-- Public site content: editable text blocks + the massage-type catalog the
-- marketing pages render. Separate from CRM tables: no customer data lives here.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS site_blocks (
  `key` VARCHAR(120) NOT NULL PRIMARY KEY,
  `value` LONGTEXT NULL,
  `group_name` VARCHAR(60) NOT NULL DEFAULT 'general',
  `type` VARCHAR(20) NOT NULL DEFAULT 'text',
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS massage_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(120) NOT NULL,
  title VARCHAR(190) NOT NULL,
  summary VARCHAR(400) NULL,
  body TEXT NULL,
  illustration VARCHAR(120) NULL,
  image VARCHAR(255) NULL,
  duration_minutes INT NULL,
  price_from DECIMAL(15,2) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  deleted_at DATETIME NULL,
  UNIQUE KEY uq_massage_type_slug (slug),
  INDEX idx_massage_type_order (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
