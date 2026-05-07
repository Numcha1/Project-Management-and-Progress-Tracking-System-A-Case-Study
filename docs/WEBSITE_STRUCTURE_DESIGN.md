# Website Structure Design (RMUTP Project Tracker)

## 1) Objective
- Build a clear and scalable website structure for a project tracking system.
- Separate public pages, role-based dashboards, and shared components.
- Prepare current flat PHP files for future modular refactor.

## 2) User Roles
- Guest: not logged in, can access login/register/forgot password.
- Student: manage own project progress and profile.
- Teacher: monitor student projects and provide feedback.
- Admin: manage users, announcements, and system overview.

## 3) Sitemap (Information Architecture)
1. Public
   - `/login.php`
   - `/register.php`
   - `/forgot_password.php`
   - `/reset_password.php`
2. Entry
   - `/index.php` (role-based redirect)
3. Student Area
   - `/student_dashboard.php`
   - `/project_detail.php?id={projectId}`
   - `/all_tasks.php`
   - `/edit_profile.php`
4. Teacher Area
   - `/teacher_dashboard.php`
   - `/project_detail.php?id={projectId}`
5. Admin Area
   - `/admin_dashboard.php`
   - `/create_admin.php`
6. System
   - `/logout.php`

## 4) Navigation Structure
- Global (authenticated): Dashboard, Profile, Logout.
- Student nav: Dashboard, Tasks, Project Detail, Profile.
- Teacher nav: Dashboard, Student Projects, Project Detail.
- Admin nav: Dashboard, Users, Announcements, Create Admin.
- Public nav: Login, Register, Forgot Password.

## 5) Page Template Structure
- Layout shell:
  - `Header` (logo, role badge, user name)
  - `Role Navigation`
  - `Main Content`
  - `Footer`
- Reusable UI blocks:
  - Stat cards
  - Tables with filter/search
  - Alert/flash message
  - Modal confirm/delete
  - Form sections with validation message

## 6) Recommended Folder Structure (Target v2)
```txt
rmutp_project/
  app/
    Core/
      Router.php
      Controller.php
      Auth.php
      View.php
    Controllers/
      AuthController.php
      StudentController.php
      TeacherController.php
      AdminController.php
      ProfileController.php
      ProjectController.php
    Services/
      UserService.php
      ProjectService.php
      AnnouncementService.php
    Repositories/
      UserRepository.php
      ProjectRepository.php
      AnnouncementRepository.php
    Views/
      layouts/
        main.php
        auth.php
      components/
        navbar.php
        sidebar.php
        flash.php
        stat-card.php
      auth/
        login.php
        register.php
        forgot-password.php
        reset-password.php
      student/
        dashboard.php
        all-tasks.php
      teacher/
        dashboard.php
      admin/
        dashboard.php
        create-admin.php
      project/
        detail.php
      profile/
        edit.php
  config/
    app.php
    database.php
  routes/
    web.php
  public/
    index.php
    assets/
      css/
      js/
      img/
  storage/
    uploads/
    logs/
  docs/
    WEBSITE_STRUCTURE_DESIGN.md
    FILE_MAPPING.md
    SITEMAP.mmd
```

## 7) Current File Mapping -> Target v2
- `login.php` -> `app/Views/auth/login.php` + `AuthController@login`
- `register.php` -> `app/Views/auth/register.php` + `AuthController@register`
- `forgot_password.php` -> `app/Views/auth/forgot-password.php` + `AuthController@forgotPassword`
- `reset_password.php` -> `app/Views/auth/reset-password.php` + `AuthController@resetPassword`
- `index.php` -> `public/index.php` + role redirect in `Auth` middleware
- `student_dashboard.php` -> `app/Views/student/dashboard.php` + `StudentController@dashboard`
- `teacher_dashboard.php` -> `app/Views/teacher/dashboard.php` + `TeacherController@dashboard`
- `admin_dashboard.php` -> `app/Views/admin/dashboard.php` + `AdminController@dashboard`
- `all_tasks.php` -> `app/Views/student/all-tasks.php` + `StudentController@tasks`
- `project_detail.php` -> `app/Views/project/detail.php` + `ProjectController@show`
- `edit_profile.php` -> `app/Views/profile/edit.php` + `ProfileController@edit`
- `create_admin.php` -> `app/Views/admin/create-admin.php` + `AdminController@createAdmin`
- `db_connect.php` -> `config/database.php` + DB bootstrap service
- `config.php` -> `config/app.php`

## 8) Route Design (Friendly URL Style)
- `GET /login`
- `POST /login`
- `GET /register`
- `POST /register`
- `GET /password/forgot`
- `POST /password/forgot`
- `GET /password/reset/{token}`
- `POST /password/reset`
- `GET /dashboard` (auto by role)
- `GET /student/tasks`
- `GET /projects/{id}`
- `GET /profile/edit`
- `POST /profile/edit`
- `GET /admin/users`
- `POST /admin/announcements`
- `GET /logout`

## 9) Security and Access Layers
- Middleware:
  - `auth` for all protected routes.
  - `role:student|teacher|admin` for role-specific areas.
- Validation:
  - Centralize input validation per controller action.
- Security controls:
  - CSRF token on all POST forms.
  - Output escaping in all views.
  - Password hashing via `password_hash`/`password_verify`.

## 10) Implementation Phases
1. Create target folders (`app`, `routes`, `config`, `public`, `storage`, `docs`).
2. Move auth pages first (lowest risk).
3. Build shared layout/components.
4. Migrate student/teacher/admin dashboards.
5. Migrate project/profile pages.
6. Add middleware + friendly routing.
7. Regression test role permissions and core flows.

