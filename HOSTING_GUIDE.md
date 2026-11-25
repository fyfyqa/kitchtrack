# Panduan Hosting Percuma KitchTrack (InfinityFree — PHP + MySQL)

Ikuti langkah mudah ini untuk menjadikan **KitchTrack** boleh dibuka secara **LIVE** oleh sesiapa sahaja (pensyarah, panel FYP, atau staf):

## 1. Daftar Akaun Percuma di InfinityFree
1. Layari [infinityfree.com](https://www.infinityfree.com/) dan daftar akaun percuma.
2. Klik **Create Account** → pilih **Subdomain** percuma (contoh: `kitchtrack.rf.gd` atau `kitchtrack.epizy.com`).
3. Tunggu ~1 minit sehingga status akaun menjadi **Active**, kemudian klik **Control Panel** (cPanel).

## 2. Cipta Database MySQL & Import `database_hosting.sql`
1. Dalam Control Panel, klik **MySQL Databases**.
2. Cipta 1 database baru (contoh: `kitchtrack`).
3. Catat 4 maklumat penting yang dipaparkan pada skrin:
   - **MySQL Hostname** (contoh: `sql104.infinityfree.com`)
   - **MySQL Database Name** (contoh: `if0_38123456_kitchtrack`)
   - **MySQL Username** (contoh: `if0_38123456`)
   - **MySQL Password** (sama dengan password akaun hosting anda — boleh lihat di halaman *Account Details* InfinityFree)
4. Klik butang **phpMyAdmin** di sebelah database tersebut.
5. Pergi ke tab **Import** → pilih fail **`database_hosting.sql`** → klik **Go / Import**.
   *(Guna `database_hosting.sql` — bukan `database.sql` — kerana versi ini sudah dibuang arahan `CREATE DATABASE` supaya tidak keluar ralat `#1044 Access denied` di hosting.)*

## 3. Muat Naik Fail Projek ke `htdocs/`
1. Dalam Control Panel InfinityFree, klik **Online File Manager** (atau guna FileZilla FTP).
2. Buka folder **`htdocs`**.
3. Muat naik semua fail & folder dari `C:\xampp\htdocs\kitchtrack` ke dalam folder `htdocs/` tersebut:
   - `index.php`, `login.php`, `logout.php`, `manifest.json`
   - folder `config/`, `includes/`, `owner/`, `staff/`, `assets/`, `uploads/`
   *(Tidak perlu muat naik folder `.git` atau fail `.sql`.)*

## 4. Kemas Kini `config/db.php`
Buka `htdocs/config/db.php` di Online File Manager (klik kanan → **Edit**) dan tukar 4 baris ini mengikut maklumat dari Langkah 2:

```php
$db_host = 'sql104.infinityfree.com';
$db_name = 'if0_38123456_kitchtrack';
$db_user = 'if0_38123456';
$db_pass = 'PASSWORD_MYSQL_ANDA';
```

Klik **Save**.

## 5. Buka Laman Web Live Anda!
Layari subdomain anda (contoh: `http://kitchtrack.rf.gd`) dan uji log masuk dengan akaun demo:
- **Owner:** `owner` / `password123`
- **Staff:** `siti` / `password123` atau `ali` / `password123`
- **Inactive Staff (Ujian Sekatan):** `farah` / `password123`
