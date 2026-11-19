<?php
/*
|--------------------------------------------------------------------------
| GULLAPUDI - APPLICATION CONFIGURATION
|--------------------------------------------------------------------------
| Two database setups - picked automatically:
| - LOCAL : opened as http://localhost/... (XAMPP, user root, no password)
| - LIVE  : opened on the subdomain (hosting database below)
| All tables are created automatically on first load.
|--------------------------------------------------------------------------
*/

$appHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost'));
define('IS_LOCAL', PHP_SAPI === 'cli' || in_array($appHost, ['localhost', '127.0.0.1', '::1'], true));

if (IS_LOCAL) {
    // Local XAMPP
    define('DB_HOST', 'localhost');
    define('DB_PORT', 3306);
    define('DB_NAME', 'gullapudi_db');
    define('DB_USER', 'root');
    define('DB_PASS', '');
} else {
    // Live subdomain (hosting)
    define('DB_HOST', 'localhost');
    define('DB_PORT', 3306);
    define('DB_NAME', 'gullapudisoftware');
    define('DB_USER', 'bhavicreations');
    define('DB_PASS', 'd8Az75YlgmyBnVM');
}

define('APP_NAME', 'Gullapudi Glass & Plywood Centre');
define('APP_TIMEZONE', 'Asia/Kolkata');

/* Default administrator created on first run (change password after login). */
define('DEFAULT_ADMIN_EMAIL', 'admin@gullapudi.com');
define('DEFAULT_ADMIN_PASSWORD', 'admin123');

/* Folder for backups and uploaded images (relative to project root). */
define('BACKUP_DIR', __DIR__ . '/../backups');
define('UPLOAD_DIR', __DIR__ . '/../uploads');

/* Multi-business: every business account gets its own database.
   The master database only lists the accounts (which database each one uses). */
// Live hosting gives one database, so the accounts list is kept inside it.
define('MASTER_DB_NAME', IS_LOCAL ? 'gullapudi_master' : DB_NAME);
define('TENANT_DB_PREFIX', IS_LOCAL ? 'gullapudi_biz_' : 'gullapudisoftware_biz_');
define('MAIN_BUSINESS_NAME', 'Gullapudi Glass & Plywood Centre');

/* Company details of the MAIN business (database DB_NAME). They are copied into its
   Business Settings the first time; after that, edit them from Business Settings.
   Other businesses fill in their own details from Business Settings. */
define('MAIN_COMPANY_DEFAULTS', [
    'company_short'       => 'GULLAPUDI',
    'company_tagline'     => 'GLASS & PLYWOOD CENTRE',
    'company_name'        => 'Gullapudi Glass & Plywood',
    'company_prop'        => 'Gullapudi Glass & Plywood',
    'company_address'     => 'Gullapudi',
    'company_phone'       => '9642434343',
    'company_email'       => '',
    'company_gstin'       => '37AFBPG9432L2Z7',
    'company_state'       => 'Andhra Pradesh',
    'company_state_code'  => 'IN-AP',
    'bank_holder'         => 'Gullapudi Glass & Plywood',
    'bank_name'           => 'UNION BANK OF INDIA',
    'bank_account'        => '033110100001118',
    'bank_branch_ifsc'    => 'TILAK STREET, KAKINADA & UBIN0803031',
    'company_logo'        => 'assets/images/logo.jpg',
]);
