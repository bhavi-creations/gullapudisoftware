<?php
/*
|--------------------------------------------------------------------------
| MULTI-BUSINESS (one database per business)
|--------------------------------------------------------------------------
| - gullapudi_master.accounts lists every business and its database.
| - The main business keeps the original database (DB_NAME).
| - Every other business gets its own database (TENANT_DB_PREFIX + id) with
|   the same tables, so its data can never mix with another business.
| - The logged-in business is remembered in $_SESSION['tenant_db'];
|   db() (includes/db.php) always connects to that database.
|--------------------------------------------------------------------------
*/

/** Connection to the master database (created automatically). */
function master(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    try {
        $pdo = db_connect(MASTER_DB_NAME, true);
    } catch (PDOException $e) {
        db_fatal($e);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_name VARCHAR(200) NOT NULL,
        owner_name VARCHAR(150) NOT NULL DEFAULT '',
        mobile VARCHAR(15) NULL,
        username VARCHAR(150) NULL,
        db_name VARCHAR(64) NOT NULL UNIQUE,
        is_main TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!(int)$pdo->query('SELECT COUNT(*) FROM accounts WHERE is_main = 1')->fetchColumn()) {
        $pdo->prepare("INSERT INTO accounts (business_name, owner_name, username, db_name, is_main, status) VALUES (?, 'Administrator', 'admin', ?, 1, 'active')")
            ->execute([MAIN_BUSINESS_NAME, DB_NAME]);
    }
    return $pdo;
}

function master_all(string $sql, array $params = []): array
{
    $stmt = master()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function master_one(string $sql, array $params = []): ?array
{
    $stmt = master()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Master row of the business that is logged in. */
function current_account(): ?array
{
    static $account = false;
    if ($account === false) {
        $account = master_one('SELECT * FROM accounts WHERE db_name = ?', [current_db_name()]);
    }
    return $account;
}

/** Only the administrators of the main business can manage business accounts. */
function is_super_admin(): bool
{
    $user = current_user();
    return $user && is_main_business() && $user['role'] === 'admin';
}

/** Connects to a business database and brings its tables up to date. */
function tenant_pdo(string $dbName): PDO
{
    $pdo = db_connect($dbName, false);
    db_install($pdo);
    return $pdo;
}

/* ---------------------------------------------------------------
 | COMPANY DETAILS (stored per business in its settings table)
 --------------------------------------------------------------- */
const COMPANY_FIELDS = [
    'company_short', 'company_tagline', 'company_name', 'company_prop', 'company_address',
    'company_phone', 'company_email', 'company_gstin', 'company_state', 'company_state_code',
    'bank_holder', 'bank_name', 'bank_account', 'bank_branch_ifsc', 'company_logo',
];

function company_settings(): array
{
    $placeholders = implode(',', array_fill(0, count(COMPANY_FIELDS), '?'));
    $rows = db_all("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)", COMPANY_FIELDS);
    $values = array_column($rows, 'setting_value', 'setting_key');

    // First run of the main business: copy the details from config.php once.
    if (is_main_business() && !isset($values['company_name'])) {
        company_save(MAIN_COMPANY_DEFAULTS);
        $values = MAIN_COMPANY_DEFAULTS + $values;
    }

    $out = [];
    foreach (COMPANY_FIELDS as $key) {
        $out[$key] = (string)($values[$key] ?? '');
    }
    return $out;
}

function company_save(array $values, ?PDO $pdo = null): void
{
    $pdo = $pdo ?: db();
    $stmt = $pdo->prepare('REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)');
    foreach (COMPANY_FIELDS as $key) {
        if (array_key_exists($key, $values)) {
            $stmt->execute([$key, (string)$values[$key]]);
        }
    }
}

/** Makes the company details available as constants (used by the print templates). */
function company_define(): void
{
    if (defined('COMPANY_NAME')) {
        return;
    }
    $c = company_settings();
    $name = $c['company_name'] !== '' ? $c['company_name'] : (current_account()['business_name'] ?? MAIN_BUSINESS_NAME);
    $logo = $c['company_logo'];
    if ($logo !== '' && !is_file(__DIR__ . '/../' . $logo)) {
        $logo = '';
    }

    define('COMPANY_NAME', $name);
    define('COMPANY_SHORT', $c['company_short'] !== '' ? $c['company_short'] : mb_strtoupper($name));
    define('COMPANY_TAGLINE', $c['company_tagline']);
    define('COMPANY_PROP', $c['company_prop']);
    define('COMPANY_ADDRESS', $c['company_address']);
    define('COMPANY_PHONE', $c['company_phone']);
    define('COMPANY_EMAIL', $c['company_email']);
    define('COMPANY_GSTIN', $c['company_gstin']);
    define('COMPANY_STATE', $c['company_state']);
    define('COMPANY_STATE_CODE', $c['company_state_code']);
    define('COMPANY_BANK_HOLDER', $c['bank_holder']);
    define('COMPANY_BANK_NAME', $c['bank_name']);
    define('COMPANY_BANK_ACCOUNT', $c['bank_account']);
    define('COMPANY_BANK_BRANCH_IFSC', $c['bank_branch_ifsc']);
    define('COMPANY_LOGO', $logo);
    define('APP_STORE', COMPANY_SHORT);
}

/* ---------------------------------------------------------------
 | BUSINESS LOGO (uploads/logos/<database>_<random>.<ext>)
 --------------------------------------------------------------- */
const LOGO_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

/** Checks an uploaded logo ($_FILES entry). Returns an error message, or null when it is fine. */
function logo_check(array $file): ?string
{
    $info = ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK ? @getimagesize($file['tmp_name']) : false;
    if (!$info || !isset(LOGO_TYPES[$info[2]])) {
        return 'Logo must be a JPG, PNG or WEBP image.';
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return 'Logo must be smaller than 2 MB.';
    }
    return null;
}

/** Saves a checked logo for one business and returns its path (relative to the project). */
function logo_store(array $file, string $dbName): string
{
    $dir = UPLOAD_DIR . '/logos';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = $dbName . '_' . bin2hex(random_bytes(6)) . '.' . LOGO_TYPES[getimagesize($file['tmp_name'])[2]];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save the logo. Check folder permissions for /uploads.');
    }
    return 'uploads/logos/' . $name;
}

/* ---------------------------------------------------------------
 | LOGIN ACROSS BUSINESSES
 --------------------------------------------------------------- */

/**
 * Finds the business + user for a username / email / mobile and password.
 * Returns ['account' => row, 'user' => row, 'pdo' => PDO] or ['error' => message].
 */
function login_find(string $identifier, string $password): array
{
    foreach (master_all('SELECT * FROM accounts ORDER BY is_main DESC, id') as $account) {
        try {
            $pdo = tenant_pdo($account['db_name']);
        } catch (Throwable $e) {
            continue; // database missing - skip this business
        }
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? OR username = ? OR mobile = ?');
        $stmt->execute([$identifier, $identifier, $identifier]);
        foreach ($stmt->fetchAll() as $user) {
            if (!password_verify($password, $user['password_hash'])) {
                continue;
            }
            if ($account['status'] !== 'active') {
                return ['error' => 'This business account is disabled. Contact the administrator.'];
            }
            if (!(int)$user['enabled']) {
                return ['error' => 'Your account is disabled. Contact the administrator.'];
            }
            return ['account' => $account, 'user' => $user, 'pdo' => $pdo];
        }
    }
    return ['error' => 'Invalid username, mobile or password.'];
}

/**
 * Login names must be unique across all businesses, otherwise the login page
 * could not tell which business to open. Returns the business name that already
 * uses one of the values, or null. $excludeDb skips one database (the one being edited).
 */
function login_taken(array $values, ?string $excludeDb = null): ?string
{
    $values = array_values(array_unique(array_filter(array_map('trim', $values), 'strlen')));
    if (!$values) {
        return null;
    }
    $in = implode(',', array_fill(0, count($values), '?'));
    foreach (master_all('SELECT business_name, db_name FROM accounts ORDER BY id') as $account) {
        if ($account['db_name'] === $excludeDb) {
            continue;
        }
        try {
            $pdo = $account['db_name'] === current_db_name() ? db() : tenant_pdo($account['db_name']);
        } catch (Throwable $e) {
            continue;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email IN ($in) OR username IN ($in) OR mobile IN ($in)");
        $stmt->execute(array_merge($values, $values, $values));
        if ((int)$stmt->fetchColumn()) {
            return $account['business_name'];
        }
    }
    return null;
}

/* ---------------------------------------------------------------
 | CREATE A BUSINESS
 --------------------------------------------------------------- */

/**
 * Creates the account, its database (all tables) and its administrator login.
 * $data: business_name, owner_name, mobile, email, username, password. Returns the account id.
 */
function business_create(array $data): int
{
    $m = master();
    $m->prepare("INSERT INTO accounts (business_name, owner_name, mobile, username, db_name, status) VALUES (?, ?, ?, ?, ?, 'active')")
        ->execute([$data['business_name'], $data['owner_name'], $data['mobile'] ?: null, $data['username'], 'pending_' . bin2hex(random_bytes(8))]);
    $id = (int)$m->lastInsertId();
    $given = trim((string)($data['db_name'] ?? ''));

    // The main / master database can never hold another business - when it is typed
    // by mistake, simply create a new database automatically instead.
    if (in_array(strtolower($given), [strtolower(DB_NAME), strtolower(MASTER_DB_NAME)], true)) {
        $given = '';
    }

    $dbName = $given !== '' ? $given : business_auto_db_name($id);
    $created = false;   // true when this function made the database itself
    $pdo = null;        // set once the (empty) database is open

    try {
        if ($given !== '') {
            // A database made in the hosting panel - must exist and be empty.
            $pdo = business_existing_db($given);
        } else {
            $pdo = business_db_make($dbName);
            $created = true;
        }
        db_install($pdo);

        // db_install() adds a default administrator - turn it into the owner's login.
        $email = $data['email'] !== '' ? $data['email'] : $data['username'] . '@business-' . $id . '.local';
        $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, mobile = ?, role = ?, password_hash = ?, enabled = 1 ORDER BY id LIMIT 1')
            ->execute([$data['owner_name'], $data['username'], $email, $data['mobile'] ?: null, 'admin', password_hash($data['password'], PASSWORD_DEFAULT)]);

        company_save([
            'company_short' => mb_strtoupper($data['business_name']),
            'company_tagline' => '',
            'company_name' => $data['business_name'],
            'company_prop' => $data['owner_name'],
            'company_address' => '',
            'company_phone' => $data['mobile'],
            'company_email' => $data['email'],
            'company_gstin' => '',
            'company_state' => 'Andhra Pradesh',
            'company_state_code' => 'IN-AP',
            'bank_holder' => '',
            'bank_name' => '',
            'bank_account' => '',
            'bank_branch_ifsc' => '',
            'company_logo' => '',
        ], $pdo);

        $m->prepare('UPDATE accounts SET db_name = ? WHERE id = ?')->execute([$dbName, $id]);
    } catch (Throwable $e) {
        try {
            if ($created) {
                business_db_drop($dbName);
            } elseif ($pdo instanceof PDO) {
                business_db_clear($pdo);   // it was empty before - undo the half-made tables
            }
        } catch (Throwable $ignored) {
        }
        $m->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
        throw $e;
    }
    return $id;
}

/**
 * Name for an automatically created business database.
 * cPanel only allows databases that start with "<cpanel user>_".
 */
function business_auto_db_name(int $id): string
{
    $prefix = (!IS_LOCAL && defined('CPANEL_USER') && CPANEL_USER !== '') ? CPANEL_USER . '_biz_' : TENANT_DB_PREFIX;
    $name = $prefix . $id;
    // Never reuse a database that already has tables (e.g. left from a deleted business).
    for ($n = 2; business_db_in_use($name); $n++) {
        $name = $prefix . $id . '_' . $n;
    }
    return $name;
}

function business_db_in_use(string $name): bool
{
    if (master_one('SELECT id FROM accounts WHERE db_name = ?', [$name])) {
        return true;
    }
    try {
        return business_db_has_tables(db_connect($name, false));
    } catch (Throwable $e) {
        return false; // does not exist (or cannot be opened) - free to create
    }
}

/**
 * Creates a new empty database for one business and opens it.
 */
function business_db_make(string $name): PDO
{
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        throw new RuntimeException('Database name: letters, numbers and _ only.');
    }
    try {
        $safeName = str_replace('`', '', $name);
        master()->exec("CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = db_connect($name, true);
    } catch (PDOException $e) {
        if (!cpanel_enabled()) {
            throw business_db_error($e);
        }
        cpanel_call('Mysql/create_database', ['name' => $name]);
        cpanel_call('Mysql/set_privileges_on_database', ['user' => DB_USER, 'database' => $name, 'privileges' => 'ALL PRIVILEGES']);
        try {
            $pdo = db_connect($name, false);
        } catch (PDOException $e2) {
            throw new RuntimeException('The database ' . $name . ' was created in cPanel but cannot be opened: ' . $e2->getMessage());
        }
    }
    if (business_db_has_tables($pdo)) {
        business_db_reset_leftover($pdo, $name);
    }
    return $pdo;
}

/** Deletes a business database (directly, or through cPanel when needed). */
function business_db_drop(string $name): void
{
    if (in_array(strtolower($name), [strtolower(DB_NAME), strtolower(MASTER_DB_NAME)], true)) {
        return; // never the main database
    }
    try {
        master()->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '', $name) . '`');
        return;
    } catch (Throwable $e) {
        if (!cpanel_enabled()) {
            throw $e;
        }
    }
    cpanel_call('Mysql/delete_database', ['name' => $name]);
}

/* ---------------------------------------------------------------
 | cPanel API (only used on live hosting when it is set in config.php)
 --------------------------------------------------------------- */
function cpanel_enabled(): bool
{
    return !IS_LOCAL
        && defined('CPANEL_USER') && CPANEL_USER !== ''
        && defined('CPANEL_TOKEN') && CPANEL_TOKEN !== '';
}

function cpanel_call(string $function, array $params): array
{
    $host = (defined('CPANEL_HOST') && CPANEL_HOST !== '') ? CPANEL_HOST : 'localhost';
    $url = 'https://' . $host . ':2083/execute/' . $function . '?' . http_build_query($params);
    $headers = ['Authorization: cpanel ' . CPANEL_USER . ':' . CPANEL_TOKEN];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => $host !== 'localhost',
            CURLOPT_SSL_VERIFYHOST => $host !== 'localhost' ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
    } else {
        $ctx = stream_context_create([
            'http' => ['header' => implode("\r\n", $headers), 'timeout' => 30, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => $host !== 'localhost', 'verify_peer_name' => $host !== 'localhost'],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $err = $body === false ? 'request failed' : '';
    }

    $json = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($json)) {
        throw new RuntimeException('cPanel could not be reached (' . ($err ?: 'invalid reply') . '). Check CPANEL_HOST / CPANEL_USER / CPANEL_TOKEN in includes/config.php.');
    }
    if (empty($json['status'])) {
        throw new RuntimeException('cPanel: ' . implode(' ', (array)($json['errors'] ?? ['unknown error'])));
    }
    return $json;
}

/** Opens a database made in the hosting panel and checks it can hold a new business. */
function business_existing_db(string $name): PDO
{
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        throw new RuntimeException('Database name: letters, numbers and _ only.');
    }
    if (in_array(strtolower($name), [strtolower(DB_NAME), strtolower(MASTER_DB_NAME)], true)) {
        throw new RuntimeException('That is the main business database. Create a new empty database for this business.');
    }
    if (master_one('SELECT id FROM accounts WHERE db_name = ?', [$name])) {
        throw new RuntimeException('Database ' . $name . ' is already used by another business.');
    }
    try {
        $pdo = db_connect($name, true);
    } catch (PDOException $e) {
        $pdo = null;
        if (cpanel_enabled()) {
            try {
                cpanel_call('Mysql/create_database', ['name' => $name]);
            } catch (RuntimeException $ignored) {
            }
            cpanel_call('Mysql/set_privileges_on_database', ['user' => DB_USER, 'database' => $name, 'privileges' => 'ALL PRIVILEGES']);
            try {
                $pdo = db_connect($name, false);
            } catch (PDOException $e) {
            }
        }
        if (!$pdo) {
            throw new RuntimeException('Cannot open database "' . $name . '" - the MySQL user "' . DB_USER . '" has no access to it. '
                . 'Fix in the hosting panel: (1) MySQL Databases > create the database "' . $name . '" (if not created yet); '
                . '(2) "Add User To Database" > user "' . DB_USER . '" + database "' . $name . '" > tick ALL PRIVILEGES > Make Changes. '
                . 'Then click Create Business again. (' . $e->getMessage() . ')');
        }
    }
    if (business_db_has_tables($pdo)) {
        business_db_reset_leftover($pdo, $name);
    }
    return $pdo;
}

/**
 * The database still has tables from an old / deleted business (no account uses it).
 * Keep a backup of the old data, then empty it so the new business starts with fresh,
 * empty tables. The main database and databases of other software are never touched.
 */
function business_db_reset_leftover(PDO $pdo, string $name): void
{
    if (in_array(strtolower($name), [strtolower(DB_NAME), strtolower(MASTER_DB_NAME)], true)
        || master_one('SELECT id FROM accounts WHERE db_name = ?', [$name])) {
        throw new RuntimeException('Database ' . $name . ' is already used by another business.');
    }
    $tables = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array('users', $tables, true) || !in_array('settings', $tables, true)) {
        throw new RuntimeException('Database ' . $name . ' has tables of another software. Use a new empty database.');
    }
    if (function_exists('backup_create')) {
        $backupDir = rtrim(realpath(BACKUP_DIR) ?: BACKUP_DIR, '/\\') . '/deleted-businesses';
        try {
            [$file] = backup_create($pdo, $backupDir);
            @rename($backupDir . '/' . $file, $backupDir . '/' . $name . '_old_' . $file);
        } catch (Throwable $e) {
        }
    }
    business_db_clear($pdo);
}

function business_db_has_tables(PDO $pdo): bool
{
    return (bool)$pdo->query('SHOW TABLES')->fetchColumn();
}

/** Removes every table of a business database (used when the database itself cannot be dropped). */
function business_db_clear(PDO $pdo): void
{
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $t) {
        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $t) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/** Turns "access denied" (hosting does not allow creating databases) into clear steps. */
function business_db_error(PDOException $e): Throwable
{
    $msg = $e->getMessage();
    if (str_contains($msg, '1044') || str_contains($msg, '1045') || stripos($msg, 'denied') !== false) {
        return new RuntimeException('Your hosting does not allow the software to create a database by itself. '
            . 'DB Error: ' . $msg . ' | '
            . 'Or create a new empty database in hosting panel and enter its name in "Database Name".');
    }
    return $e;
}