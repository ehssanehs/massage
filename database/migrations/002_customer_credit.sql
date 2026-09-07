-- Customer credit ledger (migration 002). Additive: no existing tables are altered
-- except for the new credit_balance cache column on customers.
SET @has_credit_balance := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'credit_balance');
SET @add_credit_balance := IF(@has_credit_balance = 0,
  'ALTER TABLE customers ADD COLUMN credit_balance DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER notes',
  'SELECT 1');
PREPARE stmt FROM @add_credit_balance;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS credit_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(30) NOT NULL COMMENT 'earn, spend, adjust, refund',
  amount DECIMAL(15,2) NOT NULL COMMENT 'signed: earn/positive-adjust > 0, spend/negative-adjust < 0',
  balance_after DECIMAL(15,2) NOT NULL COMMENT 'customer credit_balance right after this entry',
  entity VARCHAR(100) NULL COMMENT 'massage_sessions, customer_packages, customers, settings',
  entity_id BIGINT UNSIGNED NULL,
  note TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_credit_customer (customer_id, created_at),
  INDEX idx_credit_entity (entity, entity_id),
  CONSTRAINT fk_credit_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_credit_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`,`value`,type,group_name,updated_at) VALUES
('credit_earn_percent','10','number','loyalty',NOW())
ON DUPLICATE KEY UPDATE `value`=`value`;
