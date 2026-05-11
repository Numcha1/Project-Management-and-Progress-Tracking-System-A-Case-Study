# Project Structure (Current)

โครงสร้างที่ใช้งานจริงหลังจัดระเบียบ:

```
rmutp_project/
|- backend/
|  |- libs/                 # ไลบรารีภายนอก (เช่น PHPMailer)
|  |- src/
|  |  |- Legacy/            # โค้ดระบบหลักที่หน้าเว็บเรียกใช้งานจริง
|  |  |- Config/ Core/ ...  # โครงสร้างรุ่นใหม่ (ค่อยๆ ย้ายเข้าได้)
|  |- storage/              # พื้นที่เก็บไฟล์ฝั่ง backend
|
|- frontend/
|  |- public/               # Document root ที่เว็บเรียกตรง
|  |  |- Image/
|  |  |- assets/
|  |  |- uploads/           # ไฟล์แนบ runtime (ไม่เก็บเข้า git)
|
|- docs/
|  |- sql/
|  |  |- rmutp_database.sql # SQL ไฟล์เดียว (default)
|
|- buildDatabase.bat        # ติดตั้ง/อัปเกรดฐานข้อมูล
|- buildAdmin.bat           # สร้าง/รีเซ็ตบัญชีแอดมิน
|- README.md
```

## Removed As Unused

- `backup_before_frontend_backend_split_20260508_014001/`
- `backup_before_reorg_20260508_013540/`
- `frontend/root-compat/`

## Notes

- เริ่มต้นเข้าเว็บจาก `frontend/public/index.php`
- ที่ root ใช้ `.htaccess` ชี้เข้า `frontend/public`
- ถ้าต้องการจัดระเบียบเพิ่ม ขั้นถัดไปคือแยกโค้ดใหม่ออกจาก `backend/src/Legacy` ทีละโมดูล
