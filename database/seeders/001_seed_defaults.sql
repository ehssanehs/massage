SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Minimum rows the application needs in order to run at all.
--
-- A fresh install is otherwise EMPTY: no services, therapists, customers,
-- appointments, sessions, follow-ups, expenses, inventory or branch is created.
-- For sample data use `php bin/console demo:seed` (database/seeders/002_demo_data.sql).
-- ---------------------------------------------------------------------------

-- Roles are not optional. `users.role_id` is NOT NULL with a foreign key, and
-- every permission check reads the role's permission list, so without these
-- rows no user could ever be created.
INSERT INTO roles (id,name,slug,permissions,created_at) VALUES
(1,'مدیر کل','super_admin','["*"]',NOW()),
(2,'مدیر','manager','["dashboard.view","customers.view","customers.manage","appointments.view","appointments.manage","sessions.view","sessions.manage","followups.view","followups.manage","services.view","services.manage","therapists.view","therapists.manage","finance.view","expenses.view","expenses.manage","salaries.view","reports.view","packages.view","packages.manage","inventory.view","inventory.manage","campaigns.view","campaigns.manage","audit.view","settings.manage","backup.manage"]',NOW()),
(3,'پذیرش','receptionist','["dashboard.view","customers.view","customers.manage","appointments.view","appointments.manage","sessions.view","sessions.manage","followups.view","followups.manage","services.view","therapists.view","packages.view"]',NOW()),
(4,'درمانگر','therapist','["dashboard.view","appointments.view","sessions.view","customers.view"]',NOW()),
(5,'حسابدار','accountant','["dashboard.view","finance.view","expenses.view","expenses.manage","salaries.view","reports.view"]',NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), permissions=VALUES(permissions);

-- First administrator, so the panel is reachable after install.
-- branch_id stays NULL (no branch is created) — it is optional.
-- Change this password from «تغییر رمز عبور» right after the first login.
INSERT INTO users (id,branch_id,role_id,name,email,password_hash,status,permissions,created_at) VALUES
(1,NULL,1,'مدیر سیستم','admin@example.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','active','["*"]',NOW())
ON DUPLICATE KEY UPDATE email=VALUES(email), password_hash=VALUES(password_hash), status=VALUES(status), role_id=VALUES(role_id), permissions=VALUES(permissions);

-- Display and behaviour defaults, so the UI is not blank on a fresh install.
-- The code falls back to these same values when a key is absent, so emptying
-- this table is safe.
INSERT INTO settings (`key`,`value`,type,group_name,updated_at) VALUES
('brand_name','مرکز ماساژ آرامش','text','branding',NOW()),
('primary_color','#7c3aed','color','branding',NOW()),
('secondary_color','#14b8a6','color','branding',NOW()),
('website_title','سامانه مدیریت مرکز ماساژ','text','branding',NOW()),
('contact_phone','02100000000','text','business',NOW()),
('address','تهران، ایران','text','business',NOW()),
('default_followup_days','30','number','automation',NOW()),
('currency','ریال','text','finance',NOW()),
('credit_earn_percent','10','number','loyalty',NOW()),
('default_theme','light','text','ui',NOW())
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);
