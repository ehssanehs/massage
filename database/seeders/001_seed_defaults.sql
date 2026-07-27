SET NAMES utf8mb4;
INSERT INTO branches (id,name,code,phone,address,status,created_at) VALUES (1,'شعبه مرکزی','MAIN','02100000000','تهران','active',NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO roles (id,name,slug,permissions,created_at) VALUES
(1,'مدیر کل','super_admin','["*"]',NOW()),
(2,'مدیر','manager','["dashboard.view","customers.view","customers.manage","appointments.view","appointments.manage","sessions.view","sessions.manage","followups.view","followups.manage","services.view","services.manage","therapists.view","therapists.manage","finance.view","expenses.view","expenses.manage","salaries.view","reports.view","packages.view","packages.manage","inventory.view","inventory.manage","campaigns.view","campaigns.manage","audit.view","settings.manage","backup.manage"]',NOW()),
(3,'پذیرش','receptionist','["dashboard.view","customers.view","customers.manage","appointments.view","appointments.manage","sessions.view","sessions.manage","followups.view","followups.manage","services.view","therapists.view","packages.view"]',NOW()),
(4,'درمانگر','therapist','["dashboard.view","appointments.view","sessions.view","customers.view"]',NOW()),
(5,'حسابدار','accountant','["dashboard.view","finance.view","expenses.view","expenses.manage","salaries.view","reports.view"]',NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), permissions=VALUES(permissions);

INSERT INTO users (id,branch_id,role_id,name,email,password_hash,status,permissions,created_at) VALUES
(1,1,1,'مدیر سیستم','admin@example.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi','active','["*"]',NOW())
ON DUPLICATE KEY UPDATE email=VALUES(email);

INSERT INTO settings (`key`,`value`,type,group_name,updated_at) VALUES
('brand_name','مرکز ماساژ آرامش','text','branding',NOW()),
('primary_color','#7c3aed','color','branding',NOW()),
('secondary_color','#14b8a6','color','branding',NOW()),
('website_title','سامانه مدیریت مرکز ماساژ','text','branding',NOW()),
('contact_phone','02100000000','text','business',NOW()),
('address','تهران، ایران','text','business',NOW()),
('default_followup_days','30','number','automation',NOW()),
('currency','ریال','text','finance',NOW()),
('default_theme','light','text','ui',NOW())
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);

INSERT INTO services (id,name,description,default_price,duration_minutes,commission_percentage,fixed_commission,followup_interval_days,status,created_at) VALUES
(1,'ماساژ ریلکسی','ماساژ آرامش‌بخش و کاهش استرس',2500000,60,30,0,30,'active',NOW()),
(2,'ماساژ دیپ تیشو','ماساژ عمقی برای گرفتگی عضلات',3500000,75,35,0,30,'active',NOW()),
(3,'ماساژ ورزشی','مناسب ورزشکاران و ریکاوری',3200000,60,30,0,21,'active',NOW()),
(4,'هات استون','ماساژ با سنگ داغ',4200000,90,30,0,45,'active',NOW()),
(5,'ماساژ درمانی','تمرکز بر دردهای عضلانی',3800000,75,35,0,30,'active',NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO therapists (id,branch_id,name,code,phone,email,hire_date,status,skills,service_ids,salary_model,base_salary,commission_percentage,fixed_commission,working_schedule,created_at) VALUES
(1,1,'سارا احمدی','T001','09120000001','sara@example.com',CURDATE(),'active','ریلکسی، دیپ تیشو','[1,2,5]','base_plus_percentage',150000000,25,0,'{"sat":"09-17","sun":"09-17","mon":"09-17"}',NOW()),
(2,1,'نیما رضایی','T002','09120000002','nima@example.com',CURDATE(),'active','ورزشی، درمانی','[2,3,5]','percentage',0,40,0,'{"sat":"12-20","sun":"12-20","tue":"12-20"}',NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO customers (id,branch_id,customer_code,first_name,last_name,mobile,email,occupation,birth_date,gender,registration_date,referral_source,status,tags,followup_interval_days,notes,created_by,created_at) VALUES
(1,1,'C260727101','مریم','کریمی','09121111111','maryam@example.com','مدیر فروش','1992-05-10','female',CURDATE(),'Instagram','vip','VIP,ریلکسی',30,'مشتری وفادار',1,NOW()),
(2,1,'C260727102','علی','محمدی','09122222222','ali@example.com','ورزشکار','1988-09-02','male',CURDATE(),'Google','active','ورزشی,کمردرد',21,'ترجیح به عصرها',1,NOW())
ON DUPLICATE KEY UPDATE mobile=VALUES(mobile);

INSERT INTO appointments (customer_id,therapist_id,service_id,appointment_date,start_time,end_time,status,notes,created_by,created_at) VALUES
(1,1,1,CURDATE(),'10:00','11:00','confirmed','رزرو نمونه امروز',1,NOW()),
(2,2,3,DATE_ADD(CURDATE(), INTERVAL 1 DAY),'16:00','17:00','pending','پیگیری تلفنی قبل از مراجعه',1,NOW());

INSERT INTO massage_sessions (customer_id,therapist_id,service_id,massage_date,start_time,end_time,duration_minutes,therapist_code,price,discount,final_amount,payment_method,payment_status,status,satisfaction_score,customer_feedback,therapist_notes,created_by,created_at) VALUES
(1,1,1,DATE_SUB(CURDATE(), INTERVAL 31 DAY),'10:00','11:00',60,'T001',2500000,0,2500000,'card','paid','completed',5,'عالی بود','نیازمند پیگیری ۳۰ روزه',1,NOW()),
(2,2,3,DATE_SUB(CURDATE(), INTERVAL 10 DAY),'16:00','17:00',60,'T002',3200000,200000,3000000,'cash','paid','completed',4,'خوب بود','مراجعه بعدی سه هفته دیگر',1,NOW());

INSERT INTO followups (customer_id,session_id,due_date,status,priority,description,created_at) VALUES
(1,1,DATE_SUB(CURDATE(), INTERVAL 1 DAY),'pending','high','پیگیری بازگشت مشتری VIP',NOW()),
(2,2,DATE_ADD(CURDATE(), INTERVAL 7 DAY),'pending','normal','یادآوری مراجعه ورزشی',NOW());

INSERT INTO customer_timeline (customer_id,type,title,body,entity,entity_id,created_by,created_at) VALUES
(1,'registration','ثبت‌نام مشتری','پرونده مشتری ایجاد شد','customers',1,1,NOW()),
(1,'session','جلسه ماساژ','ماساژ ریلکسی تکمیل شد','massage_sessions',1,1,DATE_SUB(NOW(), INTERVAL 31 DAY)),
(2,'registration','ثبت‌نام مشتری','پرونده مشتری ایجاد شد','customers',2,1,NOW()),
(2,'session','جلسه ماساژ','ماساژ ورزشی تکمیل شد','massage_sessions',2,1,DATE_SUB(NOW(), INTERVAL 10 DAY));

INSERT INTO expenses (title,category,amount,expense_date,payment_method,description,created_by,created_at) VALUES
('خرید روغن ماساژ','Supplies',12000000,CURDATE(),'card','موجودی ماهانه',1,NOW()),
('تبلیغات اینستاگرام','Marketing',8000000,DATE_SUB(CURDATE(), INTERVAL 3 DAY),'online','کمپین تابستان',1,NOW());

INSERT INTO inventory_items (name,category,quantity,minimum_quantity,unit,status,created_by,created_at) VALUES
('روغن ماساژ اسطوخودوس','روغن',12,5,'لیتر','active',1,NOW()),
('حوله سفید','مصرفی',40,20,'عدد','active',1,NOW()),
('ملحفه یکبار مصرف','مصرفی',120,50,'عدد','active',1,NOW());
