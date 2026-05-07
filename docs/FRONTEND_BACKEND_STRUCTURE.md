# Frontend / Backend Split

## New Structure
```txt
rmutp_project/
  frontend/
    public/
      *.php (public entry files)
      Image/
      uploads/
      assets/
  backend/
    src/
      Legacy/
      Config/
      Core/
      Controllers/
      Services/
      Repositories/
      Routes/
      Views/
    libs/
      PHPMailer/
    storage/
      uploads/
  docs/
  *.php (root compatibility redirect wrappers)
```

## Runtime Flow
1. User opens old root URL such as `/login.php`.
2. Root wrapper redirects to `/frontend/public/login.php`.
3. `frontend/public/login.php` loads backend script:
   - `backend/src/Legacy/Auth/login.php`
4. Backend legacy scripts include shared files via absolute `__DIR__` paths.

## Notes
- `db_connect.php` and `config.php` at root are kept as compatibility include wrappers.
- Public assets are under `frontend/public` for direct browser access.
- PHPMailer is moved to `backend/libs/PHPMailer`.

