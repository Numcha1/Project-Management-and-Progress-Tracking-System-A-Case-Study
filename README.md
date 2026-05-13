# RMUTP Academic Lifecycle Suite

แพลตฟอร์มติดตามโครงงานระดับคณะ (Medium Scope) สำหรับบริหารวงจรโครงงานตั้งแต่
`เสนอหัวข้อ -> อนุมัติ -> milestone -> ประเมิน -> รายงาน` บนสถาปัตยกรรม
`PHP + MySQL/MariaDB` ที่คงระบบ Legacy ให้ใช้งานต่อได้ พร้อมรีแฟกเตอร์แบบ incremental
ตามแนวทาง Strangler Pattern

---

## สารบัญ

1. [ภาพรวมระบบ](#1-ภาพรวมระบบ)
2. [ขอบเขตเวอร์ชัน](#2-ขอบเขตเวอร์ชัน-medium-scope)
3. [สถาปัตยกรรมและโครงสร้างโปรเจกต์](#3-สถาปัตยกรรมและโครงสร้างโปรเจกต์)
4. [ความต้องการระบบ](#4-ความต้องการระบบ)
5. [ติดตั้งและเริ่มใช้งาน](#5-ติดตั้งและเริ่มใช้งาน)
6. [Deployment Modes: Single vs Multi-tenant](#6-deployment-modes-single-vs-multi-tenant)
7. [คำสั่งสำหรับผู้ดูแลระบบ](#7-คำสั่งสำหรับผู้ดูแลระบบ)
8. [Workflow การใช้งานตามบทบาท](#8-workflow-การใช้งานตามบทบาท)
9. [Endpoints/หน้าหลักของระบบ](#9-endpointsหน้าหลักของระบบ)
10. [Environment Variables](#10-environment-variables)
11. [Operations และงานบำรุงรักษา](#11-operations-และงานบำรุงรักษา)
12. [Troubleshooting](#12-troubleshooting)
13. [Roadmap ระยะต่อไป](#13-roadmap-ระยะต่อไป)
14. [เอกสารอ้างอิง](#14-เอกสารอ้างอิง)

---

## 1) ภาพรวมระบบ

### เป้าหมายของระบบ

- รองรับการจัดการโครงงานระดับคณะ/หลักสูตรแบบใช้งานจริงในทีมขนาดกลาง
- ให้ผู้ใช้งาน 3 บทบาท (`student`, `teacher`, `admin`) ทำงานร่วมกันบน workflow เดียว
- รองรับการขยายเป็น multi-tenant โดยแยกฐานข้อมูลแต่ละคณะเพื่อบริหารจัดการอิสระ

### ความสามารถเด่น

- Authentication ครบ: สมัคร, เข้าสู่ระบบ, ลืมรหัสผ่านผ่านอีเมล, รีเซ็ตรหัสผ่าน
- Academic Lifecycle Modules:
  - `Proposal Center`
  - `Milestone Board`
  - `Committee Assignment`
  - `Approval Center`
- Governance & Operations:
  - KPI dashboard, reports, CSV export
  - audit log และ attachment audit
  - backup management และ ops center
  - import ผู้ใช้แบบ CSV

---

## 2) ขอบเขตเวอร์ชัน (Medium Scope)

### In Scope

- ฟีเจอร์ Student / Teacher / Admin ครบ flow หลัก
- Workflow โครงงาน Proposal + Milestone + Committee แบบใช้งานจริง
- Dashboard และรายงานเชิงปฏิบัติการ
- Backup และ Operation jobs ในระดับระบบ
- โครงสร้างสำหรับ incremental refactor (`backend/src/Domain`)

### Out of Scope (ตอนนี้)

- SSO/IAM เชิงมหาวิทยาลัย
- Policy engine หลายชั้นแบบ cross-faculty ที่ซับซ้อน
- Compliance workflow ระดับ audit/legal เต็มรูปแบบ
- Microservices / distributed event orchestration

---

## 3) สถาปัตยกรรมและโครงสร้างโปรเจกต์

### Architecture (Hybrid Runtime)

- Public entrypoints: `frontend/public/*.php`
- Runtime หลักที่ใช้งานจริง: `backend/src/Legacy/**`
- โครงสร้างโดเมนใหม่ระหว่างย้าย: `backend/src/Domain/**`
- ใช้ Strangler Pattern เพื่อลดความเสี่ยงระหว่างรีแฟกเตอร์

### โครงสร้างโฟลเดอร์หลัก

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
|- runWorker.bat
|- checkSystem.bat
```

---

## 4) ความต้องการระบบ

### Runtime

- Windows 10/11 + XAMPP
- Apache (เปิด `mod_rewrite`)
- PHP 8+ (`pdo_mysql` และ extensions พื้นฐานต้องพร้อมใช้งาน)
- MySQL 8+ หรือ MariaDB 10.4+

### Recommended

- Git
- PowerShell
- สิทธิ์ในการสร้าง database/schema และ table

---

## 5) ติดตั้งและเริ่มใช้งาน

### Step 1: เตรียมเครื่อง

1. เปิด Apache และ MySQL ใน XAMPP
2. วางโฟลเดอร์โปรเจกต์ไว้ใต้ `xampp/htdocs`
3. ยืนยันว่าเข้าถึง PHP ได้จาก command line

### Step 2: สร้างฐานข้อมูลเริ่มต้น

```bat
buildDatabase.bat
```

### Step 3: สร้างบัญชีผู้ดูแล

```bat
buildAdmin.bat --email=admin@rmutp.ac.th --name="Super Admin" --password="StrongPassword123!"
```

### Step 4: ตรวจสุขภาพระบบ

```bat
checkSystem.bat
```

### Step 5: เข้าใช้งาน

```txt
http://localhost/<project-folder>/
```

---

## 6) Deployment Modes: Single vs Multi-tenant

### Single-tenant (ค่าเริ่มต้น)

- ใช้ DB เดียว (`DB_NAME`)
- เหมาะกับการเริ่มพัฒนา/ทดสอบระบบเร็ว

### Multi-tenant

- ใช้ `core db` สำหรับ metadata กลาง (คณะ/โปรแกรม/mapping)
- ใช้ `tenant db` แยกต่อคณะ
- เหมาะกับการใช้งานหลายคณะและต้องการแยกข้อมูลระดับองค์กร

### ตัวอย่างการ setup โหมด multi-tenant

1) สร้าง core db

```bat
buildDatabase.bat --mode=core
```

2) provision tenant รายคณะ

```bat
buildDatabase.bat --mode=tenant --faculty=fst --faculty-name="Faculty of Science and Technology" --tenant-db=rmutp_fst
```

3) อัปเกรด schema ทุก tenant

```bat
buildDatabase.bat --mode=upgrade-all-tenants
```

4) นำเข้าผู้ใช้รายคณะ

```bat
buildUsers.bat --faculty=fst --csv=users_fst.csv --upsert
```

---

## 7) คำสั่งสำหรับผู้ดูแลระบบ

### Database / Schema

```bat
buildDatabase.bat
buildDatabase.bat --mode=core
buildDatabase.bat --mode=tenant --faculty=fst --faculty-name="Faculty of Science and Technology" --tenant-db=rmutp_fst
buildDatabase.bat --mode=upgrade-all-tenants
```

### User Administration

```bat
buildAdmin.bat --email=admin@rmutp.ac.th --name="Super Admin" --password="StrongPassword123!"
buildUsers.bat --faculty=fst --csv=users_fst.csv --upsert
```

### Worker / Queue

```bat
runWorker.bat --schedule-recurring --limit=50
runWorker.bat --loop --interval-seconds=15 --schedule-recurring
```

### System Check

```bat
checkSystem.bat
```

สิ่งที่ `checkSystem.bat` ตรวจหลักๆ:

- โครงสร้างไฟล์และโฟลเดอร์สำคัญ
- PHP runtime และ lint
- SQL table definition
- การสแกนไฟล์ JS/TS หา mojibake
- การปะปนไฟล์ที่ไม่อยู่ในเทคโนโลยีเป้าหมาย

---

## 8) Workflow การใช้งานตามบทบาท

### Guest / User ทั่วไป

1. สมัครสมาชิก
2. ยืนยันข้อมูลและเข้าสู่ระบบ
3. กรณีลืมรหัสผ่าน: ขอ reset link ผ่านอีเมล

### Student

1. สร้างโครงงานและทีม
2. ส่ง proposal/แก้ไขตาม feedback
3. อัปเดต milestone และส่งงานแนบไฟล์
4. ติดตามสถานะอนุมัติและงานค้างจาก dashboard

### Teacher

1. รับ/ปฏิเสธคำเชิญที่ปรึกษา/กรรมการ
2. ตรวจงานและให้ข้อเสนอแนะ
3. ประเมินโครงงานตาม rubric และวงรอบที่กำหนด

### Admin

1. จัดการผู้ใช้/สิทธิ์/ประกาศ
2. ติดตาม KPI, รายงาน, audit logs
3. จัดการ tenant, backup, และ operation jobs

---

## 9) Endpoints/หน้าหลักของระบบ

### Auth

- `login.php`
- `register.php`
- `forgot_password.php`
- `reset_password.php`

### Student

- `student_dashboard.php`
- `project_detail.php`
- `all_tasks.php`

### Teacher

- `teacher_dashboard.php`
- `project_detail.php`

### Workflow

- `proposal_center.php`
- `milestone_board.php`
- `committee_assignment.php`
- `approval_center.php`

### Admin

- `admin_dashboard.php`
- `admin_kpi.php`
- `admin_reports.php`
- `admin_audit_logs.php`
- `admin_attachments.php`
- `admin_backups.php`
- `tenant_admin.php`
- `admin_ops.php`

---

## 10) Environment Variables

> ปัจจุบันระบบรองรับการกำหนดค่าทั้งผ่าน environment และค่า default ในระบบ

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

### ตัวอย่างค่า (เพื่อ dev local)

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=rmutp
DB_USER=root
DB_PASS=
TENANT_MODE=single
CORE_DB_NAME=rmutp_core
DEFAULT_TENANT_CODE=fst

SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_ENCRYPTION=tls
SMTP_USER=
SMTP_PASS=
SMTP_FROM_NAME=RMUTP Lifecycle Suite
APP_BASE_URL=http://localhost/rmutp_project/
```

---

## 11) Operations และงานบำรุงรักษา

### Backup Governance

- ใช้หน้า `admin_backups.php` สำหรับดูสถานะ/ควบคุมงานสำรอง
- เก็บไฟล์สำรองใน `backend/storage/backups/`

### Realtime & Ops

- หน้า `realtime_status.php` และ `admin_ops.php` ใช้ติดตามงาน runtime/queue
- ใช้ worker CLI (`runWorker.bat`) สำหรับงาน recurring และงานคิวที่ต้องประมวลผล

### Audit & Reporting

- ใช้ `admin_audit_logs.php` สำหรับติดตามกิจกรรมสำคัญ
- ใช้ `admin_reports.php` และ `admin_kpi.php` เพื่อดูภาพรวมการดำเนินงาน

---

## 12) Troubleshooting

### เว็บเปิดได้แต่ route เพี้ยน

- ตรวจว่า Apache เปิด `mod_rewrite`
- ตรวจ `.htaccess` และ `AllowOverride`
- restart Apache หลังแก้ config

### สร้างฐานข้อมูลไม่ผ่าน

- ตรวจ `DB_*` และสิทธิ์ user ว่ามี `CREATE/ALTER/INDEX`
- ตรวจว่า MySQL/MariaDB service ทำงานอยู่
- ทดสอบเชื่อมต่อด้วย client ภายนอกก่อนรัน batch

### โหมด multi-tenant ล็อกอินไม่เจอผู้ใช้

- ตรวจ `TENANT_MODE=multi`
- ตรวจ `tenant code` ที่ส่งเข้าระบบ
- ตรวจ mapping `faculties.tenant_db_name` ใน core db

### ลืมรหัสผ่านแต่ไม่ส่งอีเมล

- ตรวจ `SMTP_HOST/PORT/ENCRYPTION`
- ตรวจ `SMTP_USER/SMTP_PASS`
- ตรวจ firewall/นโยบายผู้ให้บริการอีเมล
- ตรวจ `APP_BASE_URL` ให้ถูกต้องกับ URL ที่ผู้ใช้เข้าจริง

### worker ไม่ประมวลผล

- รัน `runWorker.bat --schedule-recurring --limit=50` เพื่อตรวจงานทันที
- ถ้าต้องการต่อเนื่องให้ใช้ `--loop`
- ตรวจ log ของงานใน Ops Center

---

## 13) Roadmap ระยะต่อไป

- เสริม domain services และ repository layer เพิ่มเติม
- ย้าย logic สำคัญจาก Legacy ไป Domain ทีละ module
- เพิ่ม test coverage สำหรับ workflow หลัก
- ปรับ observability (metrics/logging) ในงาน queue และ backup

---

## 14) เอกสารอ้างอิง

- `docs/FILE_MAPPING.md`
- `docs/SITEMAP.mmd`
- `docs/WEBSITE_STRUCTURE_DESIGN.md`
- `docs/sql/rmutp_database.sql`
- `docs/sql/rmutp_core_database.sql`

---

## Demo Accounts

หลังรันฐานข้อมูลด้วยไฟล์ SQL หลัก จะมีบัญชีตัวอย่างพร้อมใช้งาน:

- รหัสผ่านเริ่มต้นทุกบัญชี: `DemoPass123!`
- Admin: `admin.demo@rmutp.ac.th`
- Teacher: `teacher.one@rmutp.ac.th`, `teacher.two@rmutp.ac.th`
- Student: `student.one@rmutp.ac.th`, `student.two@rmutp.ac.th`, `student.three@rmutp.ac.th`

---

เวอร์ชันนี้ออกแบบให้ "กลางและดูแลง่าย" สำหรับทีมพัฒนา 4 คน และรองรับการขยายแบบ incremental ในระยะยาว
