<?php
/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION + AUTO SCHEMA + HELPERS
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';

date_default_timezone_set(APP_TIMEZONE);

/** Database of the business that is logged in (the main business when nobody is). */
function current_db_name(): string
{
    $name = $_SESSION['tenant_db'] ?? '';
    if (is_string($name) && $name !== '' && preg_match('/^' . preg_quote(TENANT_DB_PREFIX, '/') . '[0-9]+$/', $name)) {
        return $name;
    }
    return DB_NAME;
}

function is_main_business(): bool
{
    return current_db_name() === DB_NAME;
}

/** Opens a PDO connection to one database; creates it first when $create is true. */
function db_connect(string $name, bool $create = false): PDO
{
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn . ';dbname=' . $name, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // 1049 = unknown database -> create it (only when allowed).
        $unknown = (int)($e->errorInfo[1] ?? 0) === 1049 || strpos($e->getMessage(), '1049') !== false;
        if (!$create || !$unknown) {
            throw $e;
        }
        $server = new PDO($dsn, DB_USER, DB_PASS, $options);
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = new PDO($dsn . ';dbname=' . $name, DB_USER, DB_PASS, $options);
    }

    $pdo->exec("SET time_zone = '+05:30'");
    return $pdo;
}

/** Connection to the logged-in business's own database (schema installed automatically). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    try {
        // Only the main database is created on the fly; business databases are
        // created when the account is added (Business Accounts page).
        $pdo = db_connect(current_db_name(), is_main_business());
    } catch (PDOException $e) {
        db_fatal($e);
    }
    db_install($pdo);

    return $pdo;
}

function db_fatal(Throwable $e): void
{
    http_response_code(500);
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $message = 'Database connection failed. Start MySQL in XAMPP and check includes/config.php. (' . $e->getMessage() . ')';
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message]);
    } else {
        echo '<div style="font-family:Arial;padding:40px;color:#b91c1c">' . htmlspecialchars($message) . '</div>';
    }
    exit;
}

/* ---------------------------------------------------------------
 | SCHEMA (created once, then a version flag is stored)
 --------------------------------------------------------------- */
function db_schema(): array
{
    return [
"CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    username VARCHAR(150) NOT NULL,
    email VARCHAR(254) NOT NULL UNIQUE,
    mobile VARCHAR(15) NULL,
    dob DATE NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'staff',
    password_hash VARCHAR(255) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_login DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_type VARCHAR(30) NOT NULL DEFAULT 'Individual',
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    name VARCHAR(200) NOT NULL,
    mobile VARCHAR(15) NOT NULL,
    email VARCHAR(254) NULL,
    code VARCHAR(50) NULL,
    platform VARCHAR(100) NULL,
    store VARCHAR(100) NULL,
    gstin VARCHAR(20) NULL,
    addresses TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    newsletter VARCHAR(5) NOT NULL DEFAULT 'no',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (mobile), INDEX (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    parent_id INT NULL,
    color_code VARCHAR(20) NULL,
    image_url VARCHAR(500) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    featured TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    store VARCHAR(100) NULL,
    category_id INT NULL,
    category VARCHAR(150) NULL,
    product_type VARCHAR(50) NULL,
    discount_type VARCHAR(30) NULL,
    price DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount_value DECIMAL(14,2) NOT NULL DEFAULT 0,
    hsn_code VARCHAR(30) NULL,
    thickness VARCHAR(50) NULL,
    size VARCHAR(50) NULL,
    product_expiry TINYINT(1) NOT NULL DEFAULT 0,
    measurement_unit VARCHAR(30) NULL,
    taxes TEXT NULL,
    default_taxes TEXT NULL,
    tax_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
    stock DECIMAL(14,2) NOT NULL DEFAULT 0,
    low_stock_alert DECIMAL(14,2) NOT NULL DEFAULT 10,
    description TEXT NULL,
    additional_info TEXT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    featured TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS taxes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    type VARCHAR(20) NOT NULL DEFAULT 'Percentage',
    platforms VARCHAR(100) NOT NULL DEFAULT 'All platforms',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS quotations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quotation_id VARCHAR(30) NOT NULL UNIQUE,
    date DATE NOT NULL,
    valid_upto DATE NULL,
    customer_id INT NULL,
    customer VARCHAR(200) NULL,
    salesman VARCHAR(150) NULL,
    store VARCHAR(100) NULL,
    reference VARCHAR(150) NULL,
    region VARCHAR(30) NULL,
    notes TEXT NULL,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax DECIMAL(14,2) NOT NULL DEFAULT 0,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'Draft',
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS quotation_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quotation_id INT NOT NULL,
    product_id INT NULL,
    name VARCHAR(200) NOT NULL,
    thickness VARCHAR(50) NULL,
    size VARCHAR(50) NULL,
    hsn VARCHAR(30) NULL,
    quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
    rate DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax DECIMAL(6,2) NOT NULL DEFAULT 0,
    discount DECIMAL(6,2) NOT NULL DEFAULT 0,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    INDEX (quotation_id),
    FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(30) NOT NULL UNIQUE,
    date DATE NOT NULL,
    delivery_date DATE NULL,
    customer_id INT NULL,
    customer VARCHAR(200) NULL,
    salesman VARCHAR(150) NULL,
    store VARCHAR(100) NULL,
    reference VARCHAR(150) NULL,
    region VARCHAR(30) NULL,
    notes TEXT NULL,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax DECIMAL(14,2) NOT NULL DEFAULT 0,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'Invoice Raised',
    invoice_id INT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NULL,
    name VARCHAR(200) NOT NULL,
    thickness VARCHAR(50) NULL,
    size VARCHAR(50) NULL,
    hsn VARCHAR(30) NULL,
    quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
    rate DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax DECIMAL(6,2) NOT NULL DEFAULT 0,
    discount DECIMAL(6,2) NOT NULL DEFAULT 0,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    INDEX (order_id),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(30) NOT NULL UNIQUE,
    order_id INT NULL,
    customer_id INT NULL,
    customer VARCHAR(200) NULL,
    date DATE NOT NULL,
    due_date DATE NULL,
    region VARCHAR(30) NULL,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax DECIMAL(14,2) NOT NULL DEFAULT 0,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'Submitted',
    eway_bill VARCHAR(50) NULL,
    vehicle_no VARCHAR(50) NULL,
    dispatched_through VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'Payment Received',
    invoice VARCHAR(30) NULL,
    invoice_id INT NULL,
    customer_id INT NULL,
    customer VARCHAR(200) NULL,
    narration VARCHAR(30) NULL,
    mode VARCHAR(30) NOT NULL DEFAULT 'Cash',
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    reference VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (date), INDEX (invoice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS returns_damage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_id VARCHAR(30) NOT NULL UNIQUE,
    order_id VARCHAR(30) NULL,
    date DATE NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Return Requested',
    notes TEXT NULL,
    damage_tagged TINYINT(1) NOT NULL DEFAULT 0,
    stock_adjusted TINYINT(1) NOT NULL DEFAULT 0,
    products TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS backup_operations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    operation VARCHAR(50) NOT NULL,
    type VARCHAR(30) NOT NULL,
    requested_by VARCHAR(150) NULL,
    created_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(30) NOT NULL,
    site VARCHAR(150) NULL,
    file_name VARCHAR(255) NULL,
    file_size BIGINT NULL,
    command VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS whatsapp_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NULL,
    customer VARCHAR(200) NULL,
    mobile VARCHAR(15) NOT NULL,
    template VARCHAR(50) NULL,
    message TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Sent',
    sent_by VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_name VARCHAR(150) NULL,
    module VARCHAR(50) NOT NULL,
    action VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

const DB_SCHEMA_VERSION = '3';

function db_install(PDO $pdo): void
{
    try {
        $version = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'")->fetchColumn();
        if ($version === DB_SCHEMA_VERSION) {
            return;
        }
    } catch (PDOException $e) {
        // settings table does not exist yet.
    }

    foreach (db_schema() as $sql) {
        $pdo->exec($sql);
    }

    // Upgrades for databases created by an older version (CREATE TABLE IF NOT EXISTS
    // does not add new columns to an existing table).
    $columnExists = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $newColumns = [
        // v2: dispatch details printed on the tax invoice
        ['invoices', 'eway_bill', 'VARCHAR(50) NULL'],
        ['invoices', 'vehicle_no', 'VARCHAR(50) NULL'],
        ['invoices', 'dispatched_through', 'VARCHAR(150) NULL'],
        // v3: customer account type + GST number
        ['customers', 'account_type', "VARCHAR(30) NOT NULL DEFAULT 'Individual'"],
        ['customers', 'first_name', 'VARCHAR(100) NULL'],
        ['customers', 'last_name', 'VARCHAR(100) NULL'],
        ['customers', 'gstin', 'VARCHAR(20) NULL'],
    ];
    foreach ($newColumns as [$table, $column, $definition]) {
        $columnExists->execute([$table, $column]);
        if (!(int)$columnExists->fetchColumn()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    // Default administrator.
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $stmt = $pdo->prepare('INSERT INTO users (name, username, email, role, password_hash, enabled) VALUES (?, ?, ?, ?, ?, 1)');
        $stmt->execute([
            'Administrator', 'admin', DEFAULT_ADMIN_EMAIL, 'admin',
            password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT),
        ]);
    }

    // Standard GST taxes.
    if ((int)$pdo->query('SELECT COUNT(*) FROM taxes')->fetchColumn() === 0) {
        $taxes = [
            ['SGST-14', 'SGST-14', 14], ['CGST 14', 'CGST-14', 14],
            ['2.5% State Goods and Services Tax', 'SGST 2.5', 2.5],
            ['2.5% Central Goods and Services Tax', 'CGST 2.5', 2.5],
            ['5% Integrated Goods and Services Tax', 'IGST 5', 5],
            ['12% Integrated Goods and Services Tax', 'IGST 12', 12],
            ['18% Integrated Goods and Services Tax', 'IGST 18', 18],
            ['9% State Goods and Services Tax', 'SGST 9', 9],
            ['9% Central Goods and Services Tax', 'CGST 9', 9],
            ['6% State Goods and Services Tax', 'SGST 6', 6],
            ['6% Central Goods and Services Tax', 'CGST 6', 6],
        ];
        $stmt = $pdo->prepare('INSERT INTO taxes (name, code, amount) VALUES (?, ?, ?)');
        foreach ($taxes as $t) {
            $stmt->execute($t);
        }
    }

    $pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('schema_version', ?)")
        ->execute([DB_SCHEMA_VERSION]);
}

/* ---------------------------------------------------------------
 | QUERY HELPERS
 --------------------------------------------------------------- */
function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function db_value(string $sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function db_exec(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')';
    db_exec($sql, array_values($data));
    return (int)db()->lastInsertId();
}

function db_update(string $table, array $data, int $id): int
{
    $sets = [];
    foreach (array_keys($data) as $col) {
        $sets[] = '`' . $col . '` = ?';
    }
    $params = array_values($data);
    $params[] = $id;
    return db_exec('UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
}

/** Next sequential document number, e.g. QUOT-0001, SI-0001. */
function next_number(string $table, string $column, string $prefix, int $pad = 4): string
{
    $rows = db_all("SELECT `$column` AS n FROM `$table` WHERE `$column` LIKE ?", [$prefix . '%']);
    $max = 0;
    foreach ($rows as $r) {
        $num = (int)substr($r['n'], strlen($prefix));
        if ($num > $max) {
            $max = $num;
        }
    }
    return $prefix . str_pad((string)($max + 1), $pad, '0', STR_PAD_LEFT);
}

function log_activity(string $module, string $action): void
{
    try {
        db_insert('activity_log', [
            'user_name' => $_SESSION['user_name'] ?? 'System',
            'module'    => $module,
            'action'    => mb_substr($action, 0, 255),
        ]);
    } catch (Throwable $e) {
        // Logging must never break the main action.
    }
}

/** Cast DB rows: listed keys to bool / float for clean JSON. */
function cast_rows(array $rows, array $bools = [], array $floats = []): array
{
    foreach ($rows as &$row) {
        foreach ($bools as $b) {
            if (array_key_exists($b, $row)) {
                $row[$b] = (bool)$row[$b];
            }
        }
        foreach ($floats as $f) {
            if (array_key_exists($f, $row)) {
                $row[$f] = (float)$row[$f];
            }
        }
        if (isset($row['id'])) {
            $row['id'] = (int)$row['id'];
        }
    }
    return $rows;
}

/** Refresh an invoice's status from its payments. */
function refresh_invoice_status(string $invoiceNumber): void
{
    $invoice = db_one('SELECT id, amount, status, due_date FROM invoices WHERE invoice_number = ?', [$invoiceNumber]);
    if (!$invoice || in_array($invoice['status'], ['Draft', 'Cancelled'], true)) {
        return;
    }
    $paid = (float)db_value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice = ?', [$invoiceNumber]);
    $amount = (float)$invoice['amount'];

    if ($paid >= $amount - 0.009 && $amount > 0) {
        $status = 'Paid';
    } elseif ($paid > 0) {
        $status = 'Partially Paid';
    } elseif ($invoice['due_date'] && $invoice['due_date'] < date('Y-m-d')) {
        $status = 'Overdue';
    } else {
        $status = 'Submitted';
    }
    db_update('invoices', ['status' => $status], (int)$invoice['id']);
}
