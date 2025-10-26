# PHP PDO MySQL Driver Fix Guide

## Option A: Install PHP with Complete Extensions
1. Download PHP 8.x with extensions from https://windows.php.net/download
2. Choose "Thread Safe" version with extensions included
3. Extract to C:\php
4. Add C:\php to Windows PATH
5. Copy php.ini-development to php.ini
6. Uncomment these lines in php.ini:
   ```
   extension=pdo_mysql
   extension=mysqli
   extension=mysqlnd
   ```

## Option B: Current PHP Configuration Fix
1. Find your PHP installation: `where php`
2. Find php.ini location: `php --ini`
3. If no php.ini exists, copy php.ini-development to php.ini
4. Edit php.ini and enable:
   ```
   extension_dir = "ext"
   extension=pdo_mysql
   extension=mysqli
   ```

## Option C: Use XAMPP/WAMP
- Install XAMPP which includes PHP with all MySQL extensions
- Update your Windows PATH to use XAMPP's PHP

## Verify Fix:
```bash
php -m | findstr -i mysql
php -r "print_r(PDO::getAvailableDrivers());"
```

## Test Database Connection:
```bash
php artisan tinker
# In tinker:
DB::connection()->getPdo();
```

## Alternative: MySQL Workbench
- Use MySQL Workbench for direct database access
- Connect with: 127.0.0.1:3306, user: root (try no password first)
- Create aiovpn user manually if needed