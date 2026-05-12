# RMUTP Academic Lifecycle Suite

แพลตฟอร์มติดตามโครงงานระดับคณะ/มหาวิทยาลัย ครอบคลุมตั้งแต่ `เสนอหัวข้อ -> อนุมัติ -> milestone -> ประเมิน -> รายงาน`  
พัฒนาด้วย `PHP + MySQL/MariaDB` โดยยังรันงานจริงบน Legacy และรีแฟกเตอร์แบบค่อยเป็นค่อยไป

## 1) ภาพรวมสั้นๆ

- รองรับ 3 บทบาทหลัก: `student`, `teacher`, `admin`
- รองรับ `multi-tenant` แบบแยกฐานข้อมูลต่อคณะ (`core db` + `tenant db`)
- มีโมดูล Academic Lifecycle:
  - `Proposal Center`
  - `Milestone Board`
  - `Committee Assignment`
- มีระบบกำกับดูแล: `approval workflow`, `audit log`, `backup governance`, `CSV import`

## 2) ความสามารถหลัก

### ผู้ใช้ทั่วไป
- สมัคร/ล็อกอิน/ออกจากระบบ
- ลืมรหัสผ่านผ่านอีเมล (token reset)
- แก้ไขข้อมูลส่วนตัว

### Student
- สร้างและจัดการโครงงาน
- จัดการสมาชิกในทีม
- ส่งงานแนบไฟล์, ติดตามสถานะ, ดูงานค้าง
- เข้าสู่กระบวนการ Proposal/Milestone ตามสิทธิ์

### Teacher
- รับ/ปฏิเสธคำเชิญเป็นอาจารย์ที่ปรึกษา
- ตรวจงาน (`approve/reject`) และให้ข้อเสนอแนะ
- ประเมินโครงงานตาม Rubric

### Admin
- จัดการผู้ใช้/ประกาศ/โครงงาน
- KPI Dashboard + Reports + Export CSV
- Audit Logs + Attachment Audit
- Tenant Admin (สร้างคณะ, import users)
- Backup Management

## 3) สถาปัตยกรรมปัจจุบัน

ระบบเป็น Hybrid:

- Public entrypoints: `frontend/public/*.php`
- Runtime หลัก: `backend/src/Legacy/**`
- โครงสร้างรีแฟกเตอร์: `backend/src/Domain/**` และเลเยอร์ใหม่ที่ทยอยย้ายเข้า

แนวทางพัฒนาใช้ `Strangler Pattern` เพื่อไม่หยุดระบบเดิมระหว่างย้ายโค้ด

## 4) โครงสร้างโฟลเดอร์สำคัญ

```txt
rmutp_project/
|- frontend/
|  |- public/                      # entry points และ static assets
|
|- backend/
|  |- libs/PHPMailer/              # mail library
|  |- src/
|  |  |- Legacy/                   # โค้ดที่รันจริงในปัจจุบัน
|  |  |- Domain/                   # โครงสร้างโดเมนใหม่ (incremental refactor)
|  |- storage/
|     |- uploads/
|     |- backups/
|
|- docs/
|  |- sql/rmutp_database.sql       # tenant schema + seed + upgrade
|  |- sql/rmutp_core_database.sql  # core schema (faculties/programs/import logs)
|
|- buildDatabase.bat
|- buildAdmin.bat
|- buildUsers.bat
|- checkSystem.bat
```

## 5) ความต้องการระบบ

- Windows + XAMPP (Apache, MySQL/MariaDB)
- PHP 8+ (`pdo_mysql` ต้องพร้อมใช้งาน)
- MySQL 8+ หรือ MariaDB 10.4+

## 6) วิธีเริ่มใช้งานเร็ว (Quick Start)

1. เปิด Apache และ MySQL ใน XAMPP
2. วางโปรเจกต์ไว้ใน `htdocs`
3. สร้างฐานข้อมูล

```bat
buildDatabase.bat
```

4. สร้าง/อัปเดตบัญชีแอดมิน

```bat
buildAdmin.bat --email=admin@rmutp.ac.th --name="Super Admin" --password="StrongPassword123!"
```

5. เข้าใช้งานผ่าน

```txt
http://localhost/<project-folder>/
```

6. (แนะนำ) ตรวจสุขภาพระบบ

```bat
checkSystem.bat
```

## 7) Multi-tenant Commands

### สร้าง Core DB

```bat
buildDatabase.bat --mode=core
```

### Provision คณะใหม่ (Tenant DB)

```bat
buildDatabase.bat --mode=tenant --faculty=fst --faculty-name="Faculty of Science and Technology" --tenant-db=rmutp_fst
```

### อัปเกรดทุก Tenant

```bat
buildDatabase.bat --mode=upgrade-all-tenants
```

### นำเข้าผู้ใช้จาก CSV รายคณะ

```bat
buildUsers.bat --faculty=fst --csv=users_fst.csv --upsert
```

## 8) Demo Accounts

หลังรันฐานข้อมูลด้วยไฟล์ SQL หลัก จะมีบัญชีตัวอย่างพร้อมใช้งาน

- รหัสผ่านเริ่มต้นทุกบัญชี: `DemoPass123!`
- Admin: `admin.demo@rmutp.ac.th`
- Teacher: `teacher.one@rmutp.ac.th`, `teacher.two@rmutp.ac.th`
- Student: `student.one@rmutp.ac.th`, `student.two@rmutp.ac.th`, `student.three@rmutp.ac.th`

## 9) Environment Variables ที่ใช้บ่อย

### Database

- `DB_HOST` (default: `127.0.0.1`)
- `DB_PORT` (default: `3306`)
- `DB_NAME` (default: `rmutp`)
- `DB_USER` (default: `root`)
- `DB_PASS` (default: empty)
- `TENANT_MODE` (`single` หรือ `multi`)
- `CORE_DB_NAME` (default: `rmutp_core`)
- `DEFAULT_TENANT_CODE`

### SMTP / Reset Password

- `SMTP_HOST` (default: `smtp.gmail.com`)
- `SMTP_PORT` (default: `587`)
- `SMTP_ENCRYPTION` (default: `tls`)
- `SMTP_USER`
- `SMTP_PASS`
- `SMTP_FROM_NAME`
- `APP_BASE_URL`

## 10) หน้าหลักของระบบ

- Auth: `login.php`, `register.php`, `forgot_password.php`, `reset_password.php`
- Student: `student_dashboard.php`, `project_detail.php`, `all_tasks.php`
- Teacher: `teacher_dashboard.php`, `project_detail.php`
- Workflow: `proposal_center.php`, `milestone_board.php`, `committee_assignment.php`, `approval_center.php`
- Admin: `admin_dashboard.php`, `admin_kpi.php`, `admin_reports.php`, `admin_audit_logs.php`, `admin_attachments.php`, `admin_backups.php`, `tenant_admin.php`

## 11) คำสั่งตรวจระบบ

```bat
checkSystem.bat
```

เช็กหลักๆ:
- ไฟล์สำคัญและโฟลเดอร์สำคัญ
- PHP runtime
- PHP lint
- SQL table definition
- สแกนไฟล์ JavaScript/TypeScript (ถ้ามี) หา mojibake
- ตรวจว่ามีไฟล์ Java หรือไม่

## 12) ปัญหาที่พบบ่อย

- เปิดเว็บแล้ว route ไม่ทำงาน: ตรวจ `mod_rewrite` และ `.htaccess`
- สร้าง DB ไม่ผ่าน: ตรวจ `DB_*` และสิทธิ์ user DB
- โหมด multi-tenant ล็อกอินไม่เจอผู้ใช้: ตรวจ `tenant code`, `faculties.tenant_db_name`, `TENANT_MODE=multi`
- ลืมรหัสผ่านแล้วไม่ส่งเมล: ตรวจ `SMTP_USER`/`SMTP_PASS`

## 13) เอกสารเพิ่มเติม

- `docs/FILE_MAPPING.md`
- `docs/SITEMAP.mmd`
- `docs/WEBSITE_STRUCTURE_DESIGN.md`
- `docs/sql/rmutp_database.sql`
- `docs/sql/rmutp_core_database.sql`

---

หากต้องการใช้งานระดับองค์กร แนะนำเริ่มจาก `pilot 2 คณะ` ก่อน แล้วค่อย rollout ทั้งมหาวิทยาลัยโดยใช้ runbook เดียวกัน
