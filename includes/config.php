<?php
/*
|--------------------------------------------------------------------------
| GULLAPUDI - APPLICATION CONFIGURATION
|--------------------------------------------------------------------------
| Change these values to match your MySQL server.
| XAMPP default: host=localhost, user=root, password='' (empty).
| The database and all tables are created automatically on first load.
|--------------------------------------------------------------------------
*/

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'gullapudi_db');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NAME', 'Gullapudi Glass & Plywood Centre');
define('APP_STORE', 'Gullapudi');
define('APP_TIMEZONE', 'Asia/Kolkata');

/* Default administrator created on first run (change password after login). */
define('DEFAULT_ADMIN_EMAIL', 'admin@gullapudi.com');
define('DEFAULT_ADMIN_PASSWORD', 'admin123');

/* Folder for backups and uploaded images (relative to project root). */
define('BACKUP_DIR', __DIR__ . '/../backups');
define('UPLOAD_DIR', __DIR__ . '/../uploads');
