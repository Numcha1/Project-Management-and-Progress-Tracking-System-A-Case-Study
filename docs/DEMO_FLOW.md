# Demo Flow (End-to-End)

เอกสารนี้สรุป Flow การใช้งานแบบครบเส้นทาง พร้อมตารางฐานข้อมูลที่เกี่ยวข้อง เพื่อใช้เดโมระบบในเวลาสั้น

## บัญชีสำหรับเดโม

- รหัสผ่านทุกบัญชี: `DemoPass123!`
- Admin: `admin.demo@rmutp.ac.th`
- Teacher: `teacher.one@rmutp.ac.th`, `teacher.two@rmutp.ac.th`
- Student: `student.one@rmutp.ac.th`, `student.two@rmutp.ac.th`, `student.three@rmutp.ac.th`

## Flow แนะนำ (ประมาณ 10-15 นาที)

1. Student login และเปิดโครงงาน
2. Student ดู tasks และสถานะ (`approved/pending/rejected`)
3. Teacher login และประเมินผ่าน Rubric
4. Student กลับมาดูผลประเมินล่าสุด
5. Admin login และดู KPI dashboard
6. Admin เปิดรายงาน/ไฟล์แนบ/ประวัติการใช้งาน

## เส้นทางข้อมูลต่อ Step

### 1) Student เปิดหน้าโครงงาน

- หน้า: `project_detail.php?id=<project_id>`
- อ่านจาก:
  - `projects`
  - `project_members`
  - `tasks`
  - `task_comments`
  - `task_return_history`

### 2) Teacher ประเมิน Rubric

- หน้า: `project_evaluation.php?id=<project_id>`
- อ่านจาก:
  - `rubric_criteria`
  - `project_evaluations`
  - `evaluation_scores`
- เขียนลง:
  - `project_evaluations`
  - `evaluation_scores`
  - `notifications`
  - `audit_logs`

### 3) Student ดูผลประเมิน

- หน้า: `project_evaluation.php?id=<project_id>`
- อ่านจาก:
  - `project_evaluations`
  - `evaluation_scores`
  - `rubric_criteria`

### 4) Admin ดู KPI

- หน้า: `admin_kpi.php`
- อ่านจาก:
  - `projects`
  - `tasks`
  - `users`
  - `project_members`

### 5) Admin ดูรายงานและ Audit

- หน้า:
  - `admin_reports.php`
  - `admin_attachments.php`
  - `admin_audit_logs.php`
- อ่านจาก:
  - `projects`
  - `tasks`
  - `task_return_history`
  - `audit_logs`

## หมายเหตุ

- Demo dataset ถูก seed ใน `docs/sql/rmutp_database.sql`
- เป็นแบบ idempotent รันซ้ำได้โดยไม่สร้างข้อมูลซ้ำ
