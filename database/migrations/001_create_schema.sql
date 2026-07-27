-- Persian Massage Center CRM schema for MySQL 8+/MariaDB 10.6+
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS branches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  code VARCHAR(50) NULL,
  phone VARCHAR(80) NULL,
  address TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(100) NOT NULL UNIQUE,
  permissions JSON NULL,
  created_at DATETIME NULL, updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  phone VARCHAR(80) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  permissions JSON NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(190) PRIMARY KEY,
  `value` TEXT NULL,
  type VARCHAR(50) NULL,
  group_name VARCHAR(100) NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NULL,
  customer_code VARCHAR(50) NOT NULL UNIQUE,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  mobile VARCHAR(50) NOT NULL,
  alternative_phone VARCHAR(50) NULL,
  email VARCHAR(190) NULL,
  address TEXT NULL,
  occupation VARCHAR(190) NULL,
  birth_date DATE NULL,
  gender VARCHAR(30) NULL,
  registration_date DATE NOT NULL,
  introduced_by VARCHAR(190) NULL,
  referral_source VARCHAR(190) NULL,
  referred_by_customer_id BIGINT UNSIGNED NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'new',
  segment VARCHAR(40) NULL,
  tags TEXT NULL,
  profile_photo VARCHAR(255) NULL,
  emergency_contact VARCHAR(190) NULL,
  consent_preferences TEXT NULL,
  followup_interval_days INT NULL,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL,
  INDEX idx_customer_mobile (mobile), INDEX idx_customer_status (status),
  CONSTRAINT fk_customers_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
  CONSTRAINT fk_customers_referrer FOREIGN KEY (referred_by_customer_id) REFERENCES customers(id),
  CONSTRAINT fk_customers_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  description TEXT NULL,
  default_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  duration_minutes INT NOT NULL DEFAULT 60,
  commission_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
  fixed_commission DECIMAL(15,2) NOT NULL DEFAULT 0,
  followup_interval_days INT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS therapists (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NULL,
  name VARCHAR(190) NOT NULL,
  code VARCHAR(50) NOT NULL UNIQUE,
  phone VARCHAR(80) NULL,
  email VARCHAR(190) NULL,
  address TEXT NULL,
  hire_date DATE NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  skills TEXT NULL,
  service_ids JSON NULL,
  working_schedule JSON NULL,
  salary_model VARCHAR(50) NOT NULL DEFAULT 'percentage',
  base_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
  commission_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
  fixed_commission DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL,
  CONSTRAINT fk_therapists_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  therapist_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  appointment_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'pending',
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL,
  INDEX idx_appt_therapist_time (therapist_id, appointment_date, start_time, end_time),
  INDEX idx_appt_status (status),
  CONSTRAINT fk_appt_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_appt_therapist FOREIGN KEY (therapist_id) REFERENCES therapists(id),
  CONSTRAINT fk_appt_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS massage_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NULL,
  appointment_id BIGINT UNSIGNED NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  therapist_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  massage_date DATE NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  duration_minutes INT NULL,
  therapist_code VARCHAR(50) NULL,
  referrer VARCHAR(190) NULL,
  referral_source VARCHAR(190) NULL,
  price DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount DECIMAL(15,2) NOT NULL DEFAULT 0,
  final_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  payment_method VARCHAR(40) NULL,
  payment_status VARCHAR(40) NOT NULL DEFAULT 'paid',
  status VARCHAR(40) NOT NULL DEFAULT 'completed',
  customer_feedback TEXT NULL,
  satisfaction_score DECIMAL(3,1) NULL,
  massage_quality TINYINT NULL,
  therapist_behavior TINYINT NULL,
  cleanliness TINYINT NULL,
  complaint_flag TINYINT(1) NOT NULL DEFAULT 0,
  therapist_notes TEXT NULL,
  customer_notes TEXT NULL,
  recommended_next_visit_date DATE NULL,
  followup_status VARCHAR(40) NULL,
  additional_services TEXT NULL,
  internal_notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL,
  INDEX idx_session_customer (customer_id, massage_date), INDEX idx_session_date (massage_date),
  CONSTRAINT fk_session_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id),
  CONSTRAINT fk_session_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_session_therapist FOREIGN KEY (therapist_id) REFERENCES therapists(id),
  CONSTRAINT fk_session_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS followups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NULL,
  due_date DATE NOT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'pending',
  priority VARCHAR(30) NOT NULL DEFAULT 'normal',
  description TEXT NULL,
  result TEXT NULL,
  contacted_at DATETIME NULL,
  assigned_to BIGINT UNSIGNED NULL,
  created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL,
  INDEX idx_followup_due (due_date, status),
  CONSTRAINT fk_follow_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_follow_session FOREIGN KEY (session_id) REFERENCES massage_sessions(id),
  CONSTRAINT fk_follow_user FOREIGN KEY (assigned_to) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_timeline (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(80) NOT NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NULL,
  entity VARCHAR(100) NULL,
  entity_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_timeline_customer (customer_id, created_at),
  CONSTRAINT fk_timeline_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  category VARCHAR(100) NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  expense_date DATE NOT NULL,
  payment_method VARCHAR(40) NULL,
  description TEXT NULL,
  receipt_path VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL,
  INDEX idx_expense_date (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS salary_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  therapist_id BIGINT UNSIGNED NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  session_count INT NOT NULL DEFAULT 0,
  gross_revenue DECIMAL(15,2) NOT NULL DEFAULT 0,
  base_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
  commission_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  bonuses DECIMAL(15,2) NOT NULL DEFAULT 0,
  deductions DECIMAL(15,2) NOT NULL DEFAULT 0,
  final_payable DECIMAL(15,2) NOT NULL DEFAULT 0,
  status VARCHAR(40) NOT NULL DEFAULT 'draft',
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at DATETIME NULL, updated_at DATETIME NULL,
  CONSTRAINT fk_salary_therapist FOREIGN KEY (therapist_id) REFERENCES therapists(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_packages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  total_sessions INT NOT NULL,
  used_sessions INT NOT NULL DEFAULT 0,
  price DECIMAL(15,2) NOT NULL DEFAULT 0,
  payment_status VARCHAR(40) NOT NULL DEFAULT 'paid',
  starts_at DATE NULL,
  expires_at DATE NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL,
  CONSTRAINT fk_pkg_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  category VARCHAR(100) NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  minimum_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  unit VARCHAR(50) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(30) NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  description TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NULL,
  CONSTRAINT fk_inv_item FOREIGN KEY (item_id) REFERENCES inventory_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  segment VARCHAR(100) NULL,
  channel VARCHAR(50) NULL,
  message TEXT NULL,
  starts_at DATE NULL,
  ends_at DATE NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(190) NOT NULL,
  entity VARCHAR(100) NULL,
  entity_id BIGINT UNSIGNED NULL,
  ip_address VARCHAR(80) NULL,
  user_agent VARCHAR(255) NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_audit_action (action), INDEX idx_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
