-- کارت هدیه. این جدول در پایگاه‌دادهٔ محتوای سایت (SITE_DATABASE) است، چون
-- فروش از سایت عمومی انجام می‌شود و صفحهٔ عمومی نباید به دیتابیس CRM دست بزند.
CREATE TABLE IF NOT EXISTS gift_cards (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(16) NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  buyer_name VARCHAR(120) NOT NULL,
  buyer_mobile VARCHAR(20) NOT NULL,
  recipient_name VARCHAR(120) NULL,
  recipient_mobile VARCHAR(20) NULL,
  message TEXT NULL,
  authority VARCHAR(64) NULL,
  ref_id VARCHAR(64) NULL,
  driver VARCHAR(20) NULL,
  paid_at DATETIME NULL,
  used_at DATETIME NULL,
  expires_at DATE NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_gift_code (code),
  UNIQUE KEY uq_gift_authority (authority),
  INDEX idx_gift_status (status, created_at),
  INDEX idx_gift_buyer (buyer_mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
