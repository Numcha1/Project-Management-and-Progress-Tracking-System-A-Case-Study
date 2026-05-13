# File Mapping (Current -> Target v2)

## Auth
- `login.php` -> `app/Controllers/AuthController.php@login` + `app/Views/auth/login.php`
- `register.php` -> `app/Controllers/AuthController.php@register` + `app/Views/auth/register.php`
- `forgot_password.php` -> `app/Controllers/AuthController.php@forgotPassword` + `app/Views/auth/forgot-password.php`
- `reset_password.php` -> `app/Controllers/AuthController.php@resetPassword` + `app/Views/auth/reset-password.php`
- `logout.php` -> `app/Controllers/AuthController.php@logout`

## Entry and Routing
- `index.php` -> `public/index.php` + `routes/web.php` + role-based middleware redirect

## Dashboard by Role
- `student_dashboard.php` -> `app/Controllers/StudentController.php@dashboard` + `app/Views/student/dashboard.php`
- `teacher_dashboard.php` -> `app/Controllers/TeacherController.php@dashboard` + `app/Views/teacher/dashboard.php`
- `admin_dashboard.php` -> `app/Controllers/AdminController.php@dashboard` + `app/Views/admin/dashboard.php`

## Project and Tasks
- `project_detail.php` -> `app/Controllers/ProjectController.php@show` + `app/Views/project/detail.php`
- `all_tasks.php` -> `app/Controllers/StudentController.php@tasks` + `app/Views/student/all-tasks.php`
- `proposal_center.php` -> `app/Controllers/ProjectController.php@proposalCenter` + `app/Views/project/proposal-center.php`
- `milestone_board.php` -> `app/Controllers/ProjectController.php@milestoneBoard` + `app/Views/project/milestone-board.php`
- `committee_assignment.php` -> `app/Controllers/ProjectController.php@committeeAssignment` + `app/Views/project/committee-assignment.php`

## Profile
- `edit_profile.php` -> `app/Controllers/ProfileController.php@edit` + `app/Views/profile/edit.php`

## Admin Utilities
- `create_admin.php` -> `app/Controllers/AdminController.php@createAdmin` + `app/Views/admin/create-admin.php`
- `tenant_admin.php` -> `app/Controllers/AdminController.php@tenantAdmin` + `app/Views/admin/tenant-admin.php`

## Configuration and Database
- `db_connect.php` -> `config/database.php` + bootstrap in `public/index.php`
- `config.php` -> `config/app.php`

## Third-party and Uploads
- `PHPMailer/*` -> `vendor/phpmailer/*` (or keep in `app/Libraries/PHPMailer/`)
- `uploads/*` -> `storage/uploads/*` (served via secure download endpoint)
- `Image/*` -> `public/assets/img/*`
