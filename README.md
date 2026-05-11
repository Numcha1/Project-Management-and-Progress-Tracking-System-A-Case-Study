# ระบบติดตามความคืบหน้าโครงงาน RMUTP

ระบบติดตามความคืบหน้าโครงงานสำหรับบทบาท `student`, `teacher`, `admin` พัฒนาด้วย PHP + MySQL/MariaDB และออกแบบให้รองรับการย้ายโค้ดจากโครงสร้าง Legacy ไปสู่โครงสร้างแบบแยกชั้น (Controller/Service/Repository/View)

## คุณสมบัติหลัก

- สมัครสมาชิก / เข้าสู่ระบบ / ออกจากระบบ
- ลืมรหัสผ่านผ่านอีเมล (PHPMailer + reset token)
- แดชบอร์ดแยกตามบทบาท: Student / Teacher / Admin
- จัดการโครงงาน, งานย่อย (tasks), และติดตามความคืบหน้า
- ส่งงานแนบไฟล์, ตรวจงาน (approve/reject), และบันทึกประวัติการส่งกลับงาน
- จัดการสมาชิกในโครงงานและเชิญอาจารย์ที่ปรึกษา
- ระบบประเมินโครงงานแบบ Rubric พร้อมเก็บประวัติผลประเมิน
- ระบบประกาศ, การแจ้งเตือน (notifications), และ deadline reminder
- หน้ารายงานและส่งออก CSV สำหรับผู้ดูแลระบบ
- KPI Dashboard สำหรับติดตามภาพรวมโครงงานและงานเกินกำหนด
- ระบบ Audit Logs และสิทธิ์ย่อยของ Admin
- มี CSRF protection ในฟอร์มสำคัญและอัปโหลดไฟล์แบบปลอดภัย

## ภาพรวมสถาปัตยกรรมปัจจุบัน

โปรเจกต์นี้ใช้งานจริงแบบ Hybrid:

- `frontend/public/*.php` เป็น public entry points
- โค้ด runtime ปัจจุบันอยู่ที่ `backend/src/Legacy/**`
- มี scaffold โครงสร้างใหม่ที่ `backend/src/{Controllers,Services,Repositories,Views,...}` เพื่อรองรับการรีแฟกเตอร์

สรุป: ระบบปัจจุบันยังรันบน Legacy เป็นหลัก และกำลังทยอยย้ายไปโครงสร้างใหม่

## โครงสร้างโปรเจกต์

```txt
rmutp_project/
|- frontend/
|  |- public/                    # public document root
|     |- *.php                   # wrappers -> backend/src/Legacy/*
|     |- Image/
|     |- assets/
|     |- uploads/
|
|- backend/
|  |- libs/PHPMailer/            # mail library
|  |- src/
|  |  |- Legacy/                 # runtime logic ที่ใช้งานจริง
|  |  |- Config/Core/...         # scaffold โครงสร้างใหม่
|  |- storage/
|
|- docs/
|  |- sql/rmutp_database.sql     # schema + incremental upgrade (ไฟล์เดียว)
|
|- buildDatabase.bat             # setup/upgrade ฐานข้อมูลผ่าน CLI
|- buildAdmin.bat                # สร้าง/อัปเดต admin ผ่าน CLI
|- .htaccess                     # route root -> frontend/public
```

## ความต้องการของระบบ

- Windows + XAMPP (Apache + MySQL/MariaDB)
- PHP CLI (แนะนำ PHP 8+ พร้อม `pdo_mysql`)
- MySQL 8+ หรือ MariaDB 10.4+

## วิธีติดตั้งและเริ่มต้นใช้งาน (Windows + XAMPP)

1. เปิด Apache และ MySQL ใน XAMPP
2. วางโปรเจกต์ไว้ใน `htdocs`
3. ตั้งค่า DB (ถ้าต้องการ) ผ่าน environment variables เช่น `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
4. สร้าง/อัปเกรดฐานข้อมูล

```bat
buildDatabase.bat
```

5. สร้างหรือรีเซ็ตรหัสผ่าน admin

```bat
buildAdmin.bat --email=admin@rmutp.ac.th --name="Super Admin" --password="StrongPassword123!"
```

6. เข้าเว็บผ่าน URL ของโฟลเดอร์โปรเจกต์ เช่น

```txt
http://localhost/<your-project-folder>/
```

หรือเข้าตรง:

```txt
http://localhost/<your-project-folder>/frontend/public/
```

## ข้อมูล Demo สำหรับทดสอบ Flow

หลังรัน `buildDatabase.bat` ระบบจะมีข้อมูลตัวอย่างครบ Flow (seed จาก `docs/sql/rmutp_database.sql`) และใช้งานด้วยรหัสผ่านเดียวกันทุกบัญชี:

- รหัสผ่านทุกบัญชี: `DemoPass123!`
- Admin: `admin.demo@rmutp.ac.th`
- Teacher: `teacher.one@rmutp.ac.th`, `teacher.two@rmutp.ac.th`
- Student: `student.one@rmutp.ac.th`, `student.two@rmutp.ac.th`, `student.three@rmutp.ac.th`

Flow ที่แนะนำสำหรับการเดโม:

1. Login เป็น `student.one` แล้วเข้าโครงงาน `Smart Library Queue System`
2. ดูงานที่มีสถานะ `pending/rejected` และปุ่ม Rubric ในหน้าโครงงาน
3. Login เป็น `teacher.one` แล้วบันทึกผลที่หน้า `project_evaluation.php?id=<project_id>`
4. Login เป็น `admin.demo` แล้วดู `admin_kpi.php` และ `admin_reports.php`

## สคริปต์คำสั่ง (CLI)

### สร้างหรืออัปเกรดฐานข้อมูล: `buildDatabase.bat`

เรียก `backend/src/Legacy/Admin/buildDatabase.php` เพื่อสร้างฐานข้อมูลและ apply SQL

- SQL เริ่มต้น: `docs/sql/rmutp_database.sql`
- รองรับ options:
  - `--host`
  - `--port`
  - `--database`
  - `--user`
  - `--password`
  - `--sql`

ตัวอย่าง:

```bat
buildDatabase.bat --database=rmutp --user=root --password=your_password
buildDatabase.bat --sql=docs\sql\rmutp_database.sql
```

### สร้างหรืออัปเดตผู้ดูแลระบบ: `buildAdmin.bat`

เรียก `backend/src/Legacy/Admin/buildAdmin.php` เพื่อสร้างหรืออัปเดตบัญชีแอดมิน

- รองรับ options:
  - `--email`
  - `--name`
  - `--password`

หมายเหตุ:
- ถ้าไม่ส่ง `--password` ระบบจะสุ่มรหัสผ่านให้อัตโนมัติ
- รหัสผ่านต้องยาวอย่างน้อย 10 ตัวอักษร

## ตัวแปรแวดล้อม (Environment Variables)

### ฐานข้อมูล

| Variable | ค่าเริ่มต้น |
|---|---|
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `3306` |
| `DB_NAME` | `rmutp` |
| `DB_USER` | `root` |
| `DB_PASS` | `` (empty) |
| `DB_CHARSET` | `utf8mb4` |

### อีเมล (SMTP) / รีเซ็ตรหัสผ่าน

| Variable | ค่าเริ่มต้น |
|---|---|
| `SMTP_HOST` | `smtp.gmail.com` |
| `SMTP_PORT` | `587` |
| `SMTP_ENCRYPTION` | `tls` |
| `SMTP_USER` | `` |
| `SMTP_PASS` | `` |
| `SMTP_FROM_NAME` | `RMUTP Support Team` |
| `APP_BASE_URL` | `` (auto detect) |

### ค่าตั้งต้นผู้ดูแลระบบ (ไม่บังคับ)

| Variable | คำอธิบาย |
|---|---|
| `ADMIN_EMAIL` | default email สำหรับ `buildAdmin` |
| `ADMIN_FULLNAME` | default full name สำหรับ `buildAdmin` |
| `ADMIN_PASSWORD` | default password สำหรับ `buildAdmin` |

## หน้าสำคัญของระบบ

- Public: `login.php`, `register.php`, `forgot_password.php`, `reset_password.php`
- Entry: `index.php` (role-based redirect)
- Student: `student_dashboard.php`, `project_detail.php`, `all_tasks.php`, `edit_profile.php`
- Teacher: `teacher_dashboard.php`, `project_detail.php`
- Shared: `project_evaluation.php` (Rubric ประเมินโครงงาน)
- Admin: `admin_dashboard.php`, `admin_kpi.php`, `admin_reports.php`, `admin_audit_logs.php`, `admin_attachments.php`

## ข้อมูลฐานข้อมูล

- ไฟล์ SQL หลัก: `docs/sql/rmutp_database.sql`
- เป็นไฟล์รวมที่มี:
  - Full schema
  - Incremental upgrade
  - ค่าเริ่มต้นใน `system_settings` และ `announcements`
- ออกแบบให้รันซ้ำได้ในระดับสคริปต์

## เอกสารในโปรเจกต์

- `docs/PROJECT_STRUCTURE.md`
- `docs/FRONTEND_BACKEND_STRUCTURE.md`
- `docs/FILE_MAPPING.md`
- `docs/WEBSITE_STRUCTURE_DESIGN.md`
- `docs/SITEMAP.mmd`
- `docs/DEMO_FLOW.md`

## การแก้ปัญหาเบื้องต้น

- เปิดหน้าเว็บแล้ว rewrite ไม่ทำงาน:
  - ตรวจว่า Apache เปิด `mod_rewrite` แล้ว
  - ตรวจว่า `.htaccess` ใน root ถูกอ่าน
- รัน `buildDatabase.bat` ไม่ผ่าน:
  - ตรวจว่า MySQL ทำงาน
  - ตรวจค่า `DB_*` ให้ตรงกับ environment
  - ตรวจสิทธิ์ user ว่าสามารถ `CREATE DATABASE` ได้
- ลืมรหัสผ่านแล้วไม่ส่งอีเมล:
  - ตั้งค่า `SMTP_USER` และ `SMTP_PASS`
  - ตั้ง `APP_BASE_URL` ให้ตรงโดเมนจริงในสภาพแวดล้อม production
