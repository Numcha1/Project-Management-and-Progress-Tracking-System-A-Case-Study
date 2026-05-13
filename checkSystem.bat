@echo off
setlocal EnableExtensions EnableDelayedExpansion

set "ROOT=%~dp0"
set "PHP_EXE=C:\xampp\php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=php"

set "FAILED=0"
set "LINT_TOTAL=0"
set "LINT_FAIL=0"
set "TABLE_COUNT=0"
set "WEB_CODE_TOTAL=0"
set "WEB_CODE_MOJI=0"
set "JAVA_TOTAL=0"

echo ==================================================
echo RMUTP SYSTEM HEALTH CHECK
echo Root: %ROOT%
echo ==================================================
echo.

call :check_file "%ROOT%README.md" "README"
call :check_file "%ROOT%.htaccess" "Root route config"
call :check_file "%ROOT%docs\sql\rmutp_database.sql" "Main SQL schema"
call :check_file "%ROOT%docs\sql\rmutp_core_database.sql" "Core SQL schema"
call :check_file "%ROOT%frontend\public\index.php" "Public index"
call :check_file "%ROOT%backend\src\Legacy\System\index.php" "Legacy system index"
call :check_file "%ROOT%frontend\public\approval_center.php" "Approval center route"
call :check_file "%ROOT%backend\src\Legacy\Workflow\approval_center.php" "Approval center controller"
call :check_file "%ROOT%frontend\public\proposal_center.php" "Proposal center route"
call :check_file "%ROOT%backend\src\Legacy\Workflow\proposal_center.php" "Proposal center controller"
call :check_file "%ROOT%frontend\public\milestone_board.php" "Milestone board route"
call :check_file "%ROOT%backend\src\Legacy\Workflow\milestone_board.php" "Milestone board controller"
call :check_file "%ROOT%frontend\public\committee_assignment.php" "Committee assignment route"
call :check_file "%ROOT%backend\src\Legacy\Workflow\committee_assignment.php" "Committee assignment controller"
call :check_file "%ROOT%frontend\public\tenant_admin.php" "Tenant admin route"
call :check_file "%ROOT%backend\src\Legacy\Dashboard\tenant_admin.php" "Tenant admin controller"
call :check_file "%ROOT%frontend\public\admin_backups.php" "Admin backup route"
call :check_file "%ROOT%backend\src\Legacy\Dashboard\admin_backups.php" "Admin backup controller"
call :check_file "%ROOT%frontend\public\admin_ops.php" "Admin ops route"
call :check_file "%ROOT%backend\src\Legacy\Dashboard\admin_ops.php" "Admin ops controller"
call :check_file "%ROOT%buildUsers.bat" "User import CLI wrapper"
call :check_file "%ROOT%backend\src\Legacy\Admin\buildUsers.php" "User import CLI script"
call :check_file "%ROOT%runWorker.bat" "Worker CLI wrapper"
call :check_file "%ROOT%backend\src\Legacy\Admin\runWorker.php" "Worker CLI script"
call :check_dir  "%ROOT%frontend\public\uploads" "Public uploads directory"
call :check_dir  "%ROOT%backend\storage\uploads" "Backend uploads directory"
call :check_dir  "%ROOT%backend\storage\backups" "Database backup directory"

echo.
echo [STEP] Checking PHP runtime...
"%PHP_EXE%" -v >nul 2>&1
if errorlevel 1 (
    echo [FAIL] PHP executable not found. Checked: "%PHP_EXE%"
    echo        Install PHP or set C:\xampp\php\php.exe
    set "FAILED=1"
    goto :after_lint
) else (
    echo [ OK ] PHP executable available: "%PHP_EXE%"
)

echo.
echo [STEP] Linting PHP files...
for /f "delims=" %%F in ('dir /b /s "%ROOT%backend\src\*.php" "%ROOT%frontend\public\*.php" 2^>nul') do (
    set /a LINT_TOTAL+=1
    "%PHP_EXE%" -l "%%F" >nul 2>&1
    if errorlevel 1 (
        set /a LINT_FAIL+=1
        echo [FAIL] Syntax error in: %%F
        "%PHP_EXE%" -l "%%F"
    )
)

if "!LINT_TOTAL!"=="0" (
    echo [FAIL] No PHP files found for lint check.
    set "FAILED=1"
) else (
    if "!LINT_FAIL!"=="0" (
        echo [ OK ] PHP lint passed ^(!LINT_TOTAL! files^)
    ) else (
        echo [FAIL] PHP lint failed: !LINT_FAIL! of !LINT_TOTAL! files
        set "FAILED=1"
    )
)

:after_lint
echo.
echo [STEP] Scanning JavaScript / TypeScript text integrity...
for /f "delims=" %%F in ('dir /b /s "%ROOT%backend\src\*.js" "%ROOT%backend\src\*.jsx" "%ROOT%backend\src\*.ts" "%ROOT%backend\src\*.tsx" "%ROOT%frontend\public\*.js" "%ROOT%frontend\public\*.jsx" "%ROOT%frontend\public\*.ts" "%ROOT%frontend\public\*.tsx" 2^>nul') do (
    set /a WEB_CODE_TOTAL+=1
    powershell -NoProfile -Command "$t=[System.IO.File]::ReadAllText('%%F',[System.Text.Encoding]::UTF8); $badMarker=[string]([char]0x0E4F)+[char]0x0E1F+[char]0x0E1D; if($t.Contains($badMarker)){ exit 2 }; foreach($c in $t.ToCharArray()){ $o=[int][char]$c; if((($o -ge 128 -and $o -le 159) -or ($o -ge 1024 -and $o -le 1327) -or $o -eq 65533)){ exit 2 } }; exit 0" >nul 2>&1
    if errorlevel 2 (
        set /a WEB_CODE_MOJI+=1
        echo [FAIL] Found mojibake text in: %%F
    )
)

if "!WEB_CODE_TOTAL!"=="0" (
    echo [ OK ] No JavaScript / TypeScript files found to scan
) else (
    if "!WEB_CODE_MOJI!"=="0" (
        echo [ OK ] JavaScript / TypeScript scan passed ^(!WEB_CODE_TOTAL! files^)
    ) else (
        echo [FAIL] JavaScript / TypeScript scan failed: !WEB_CODE_MOJI! of !WEB_CODE_TOTAL! files
        set "FAILED=1"
    )
)

echo.
echo [STEP] Checking Java source files...
for /f "delims=" %%F in ('dir /b /s "%ROOT%backend\src\*.java" "%ROOT%frontend\public\*.java" 2^>nul') do (
    set /a JAVA_TOTAL+=1
)
if "!JAVA_TOTAL!"=="0" (
    echo [ OK ] No Java files found
) else (
    echo [ OK ] Found Java files: !JAVA_TOTAL!
)

echo.
echo [STEP] Checking SQL table definitions...
if exist "%ROOT%docs\sql\rmutp_database.sql" (
    for /f %%A in ('findstr /r /c:"^CREATE TABLE IF NOT EXISTS " "%ROOT%docs\sql\rmutp_database.sql" ^| find /c /v ""') do set "TABLE_COUNT=%%A"
    if "!TABLE_COUNT!"=="" set "TABLE_COUNT=0"

    if !TABLE_COUNT! GEQ 20 (
        echo [ OK ] SQL CREATE TABLE definitions found: !TABLE_COUNT!
    ) else (
        echo [FAIL] Expected at least 20 CREATE TABLE definitions, found: !TABLE_COUNT!
        set "FAILED=1"
    )

    findstr /c:"CREATE TABLE IF NOT EXISTS project_approval_requests" "%ROOT%docs\sql\rmutp_database.sql" >nul 2>&1
    if errorlevel 1 (
        echo [FAIL] Missing table: project_approval_requests
        set "FAILED=1"
    ) else (
        echo [ OK ] Found table: project_approval_requests
    )

    findstr /c:"CREATE TABLE IF NOT EXISTS project_approval_actions" "%ROOT%docs\sql\rmutp_database.sql" >nul 2>&1
    if errorlevel 1 (
        echo [FAIL] Missing table: project_approval_actions
        set "FAILED=1"
    ) else (
        echo [ OK ] Found table: project_approval_actions
    )
) else (
    echo [FAIL] SQL schema file not found.
    set "FAILED=1"
)

echo.
echo ==================================================
if "%FAILED%"=="0" (
    echo SYSTEM CHECK PASSED
    echo ==================================================
    exit /b 0
) else (
    echo SYSTEM CHECK FAILED
    echo ==================================================
    exit /b 1
)

:check_file
if exist "%~1" (
    echo [ OK ] %~2
) else (
    echo [FAIL] %~2 - missing: %~1
    set "FAILED=1"
)
exit /b 0

:check_dir
if exist "%~1\" (
    echo [ OK ] %~2
) else (
    echo [FAIL] %~2 - missing: %~1
    set "FAILED=1"
)
exit /b 0

