https://github.com/Numcha1/Project-Management-and-Progress-Tracking-System-A-Case-Study# RMUTP Project Tracker

�к��Դ��������׺˹���ç�ҹ����Ѻ���ҷ `student`, `teacher`, `admin` �Ѳ�Ҵ��� PHP + MySQL/MariaDB ����͡Ẻ����ͧ�Ѻ��������鴨ҡ�ç���ҧ Legacy �����ç���ҧẺ�¡��� (Controller/Service/Repository/View)

## ��������ö��ѡ

- ��Ѥ���Ҫԡ / �������к� / �͡�ҡ�к�
- ������ʼ�ҹ��ҹ����� (PHPMailer + reset token)
- ᴪ�����¡������ҷ: Student / Teacher / Admin
- �Ѵ����ç�ҹ, �ҹ���� (tasks), ��еԴ��������׺˹��
- �觧ҹṺ���, ��Ǩ�ҹ (approve/reject), ��кѹ�֡����ѵԡ���觡�Ѻ�ҹ
- �Ѵ�����Ҫԡ��ç�ҹ����ԭ�Ҩ�������֡��
- �к���С��, �������͹ (notifications), ��� deadline reminder
- ˹����§ҹ������͡ CSV ����Ѻ�������к�
- �к� Audit Logs ����Է������¢ͧ Admin
- �� CSRF protection 㹿�����Ӥѭ����ѻ��Ŵ���Ẻ��ʹ���

## ʶҹ�ʶһѵ¡����Ѩ�غѹ

��ਡ������ҹ��ԧẺ Hybrid:

- `frontend/public/*.php` �� public entry points
- �� runtime �Ѩ�غѹ������ `backend/src/Legacy/**`
- �� scaffold �ç���ҧ������ `backend/src/{Controllers,Services,Repositories,Views,...}` �����ͧ�Ѻ�����ῡ����

��ػ: �к��Ѩ�غѹ�ѧ�ѹ�� Legacy ����ѡ ��С��ѧ����������ç���ҧ����

## �ç���ҧ��ਡ��

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
|  |  |- Legacy/                 # runtime logic �����ҹ��ԧ
|  |  |- Config/Core/...         # scaffold �ç���ҧ����
|  |- storage/
|
|- docs/
|  |- sql/rmutp_database.sql     # schema + incremental upgrade (�������)
|
|- buildDatabase.bat             # setup/upgrade �ҹ�����ż�ҹ CLI
|- buildAdmin.bat                # ���ҧ/�ѻവ admin ��ҹ CLI
|- .htaccess                     # route root -> frontend/public
```

## ������ͧ����к�

- Windows + XAMPP (Apache + MySQL/MariaDB)
- PHP CLI (�й� PHP 8+ ����� `pdo_mysql`)
- MySQL 8+ ���� MariaDB 10.4+

## ���������ҹ (Windows + XAMPP)

1. �Դ Apache ��� MySQL � XAMPP
2. �ҧ��ਡ������ `htdocs`
3. ��駤�� DB (��ҵ�ͧ���) ��ҹ environment variables �� `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
4. ���ҧ/�ѻ�ô�ҹ������

```bat
buildDatabase.bat
```

5. ���ҧ�����������ʼ�ҹ admin

```bat
buildAdmin.bat --email=admin@rmutp.ac.th --name="Super Admin" --password="StrongPassword123!"
```

6. �����纼�ҹ URL �ͧ��������ਡ�� ��

```txt
http://localhost/<your-project-folder>/
```

������ҵç:

```txt
http://localhost/<your-project-folder>/frontend/public/
```

## ʤ�Ի�� CLI

### `buildDatabase.bat`

���¡ `backend/src/Legacy/Admin/buildDatabase.php` �������ҧ�ҹ��������� apply SQL

- SQL �������: `docs/sql/rmutp_database.sql`
- �ͧ�Ѻ options:
  - `--host`
  - `--port`
  - `--database`
  - `--user`
  - `--password`
  - `--sql`

������ҧ:

```bat
buildDatabase.bat --database=rmutp --user=root --password=your_password
buildDatabase.bat --sql=docs\sql\rmutp_database.sql
```

### `buildAdmin.bat`

���¡ `backend/src/Legacy/Admin/buildAdmin.php` �������ҧ�����ѻവ�ѭ���ʹ�Թ

- �ͧ�Ѻ options:
  - `--email`
  - `--name`
  - `--password`

�����˵�:
- �������� `--password` �к����������ʼ�ҹ����ѵ��ѵ�
- ���ʼ�ҹ��ͧ������ҧ���� 10 ����ѡ��

## ������Ǵ���� (Environment Variables)

### Database

| Variable | Default |
|---|---|
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `3306` |
| `DB_NAME` | `rmutp` |
| `DB_USER` | `root` |
| `DB_PASS` | `` (empty) |
| `DB_CHARSET` | `utf8mb4` |

### SMTP / Reset Password

| Variable | Default |
|---|---|
| `SMTP_HOST` | `smtp.gmail.com` |
| `SMTP_PORT` | `587` |
| `SMTP_ENCRYPTION` | `tls` |
| `SMTP_USER` | `` |
| `SMTP_PASS` | `` |
| `SMTP_FROM_NAME` | `RMUTP Support Team` |
| `APP_BASE_URL` | `` (auto detect) |

### Admin Bootstrap (optional)

| Variable | Description |
|---|---|
| `ADMIN_EMAIL` | default email for `buildAdmin` |
| `ADMIN_FULLNAME` | default full name for `buildAdmin` |
| `ADMIN_PASSWORD` | default password for `buildAdmin` |

## ˹���Ӥѭ�ͧ�к�

- Public: `login.php`, `register.php`, `forgot_password.php`, `reset_password.php`
- Entry: `index.php` (role-based redirect)
- Student: `student_dashboard.php`, `project_detail.php`, `all_tasks.php`, `edit_profile.php`
- Teacher: `teacher_dashboard.php`, `project_detail.php`
- Admin: `admin_dashboard.php`, `admin_reports.php`, `admin_audit_logs.php`, `admin_attachments.php`

## �ҹ������

- ��� SQL ��ѡ: `docs/sql/rmutp_database.sql`
- �������������:
  - Full schema
  - Incremental upgrade
  - ����������� `system_settings` ��� `announcements`
- �͡Ẻ����ѹ�������дѺʤ�Ի��

## �͡������ਡ��

- `docs/PROJECT_STRUCTURE.md`
- `docs/FRONTEND_BACKEND_STRUCTURE.md`
- `docs/FILE_MAPPING.md`
- `docs/WEBSITE_STRUCTURE_DESIGN.md`
- `docs/SITEMAP.mmd`

## �����ѭ�����ͧ��

- �Դ˹��������� rewrite ���ӧҹ:
  - ��Ǩ��� Apache �Դ `mod_rewrite` ����
  - ��Ǩ��� `.htaccess` � root �١��ҹ
- �ѹ `buildDatabase.bat` ����ҹ:
  - ��Ǩ��� MySQL �ӧҹ
  - ��Ǩ��� `DB_*` ���ç�Ѻ environment
  - ��Ǩ�Է��� user �������ö `CREATE DATABASE` ��
- ������ʼ�ҹ��������������:
  - ��駤�� `SMTP_USER` ��� `SMTP_PASS`
  - ��� `APP_BASE_URL` ���ç������ԧ���Ҿ�Ǵ���� production
