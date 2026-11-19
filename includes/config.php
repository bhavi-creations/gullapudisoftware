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

/* Company details printed on invoices and quotations (includes/doc-print.php).
   Taken from your sample tax invoice - change any line here. Leave '' to hide it. */
define('COMPANY_NAME', 'Gullapudi Glass & Plywood');
define('COMPANY_PROP', 'Gullapudi Glass & Plywood');
define('COMPANY_ADDRESS', 'Gullapudi');
define('COMPANY_PHONE', '9642434343');
define('COMPANY_EMAIL', '');
define('COMPANY_GSTIN', '37AFBPG9432L2Z7');
define('COMPANY_STATE', 'Andhra Pradesh');
define('COMPANY_STATE_CODE', 'IN-AP');

/* Bank details (bottom-right of the invoice) */
define('COMPANY_BANK_HOLDER', 'Gullapudi Glass & Plywood');
define('COMPANY_BANK_NAME', 'UNION BANK OF INDIA');
define('COMPANY_BANK_ACCOUNT', '033110100001118');
define('COMPANY_BANK_BRANCH_IFSC', 'TILAK STREET, KAKINADA & UBIN0803031');
