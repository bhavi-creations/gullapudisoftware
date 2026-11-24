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
    permissions TEXT NULL,
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
    business_name VARCHAR(200) NULL,
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
    is_master TINYINT(1) NOT NULL DEFAULT 0,
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

"CREATE TABLE IF NOT EXISTS stores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    address VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

const DB_SCHEMA_VERSION = '7';

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
        // v4: business name for Business customers
        ['customers', 'business_name', 'VARCHAR(200) NULL'],
        // v5: employee page access + master quotations
        ['users', 'permissions', 'TEXT NULL'],
        ['quotations', 'is_master', 'TINYINT(1) NOT NULL DEFAULT 0'],
        // v6: quotation -> invoice link, one GST rate per product, payment notes
        ['quotations', 'invoice_id', 'INT NULL'],
        ['products', 'tax_id', 'INT NULL'],
        ['payments', 'notes', 'VARCHAR(255) NULL'],
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

    /*
     * v6: GST is kept as ONE rate per tax (e.g. "GST 18%").
     * Inside the state it is printed as CGST 9% + SGST 9%, outside as IGST 18%.
     * Old separate CGST / SGST / IGST rows are converted into GST rates once.
     */
    $old = $pdo->query("SELECT id, name, code, amount FROM taxes
                        WHERE UPPER(code) REGEXP 'CGST|SGST|IGST' OR UPPER(name) REGEXP 'CGST|SGST|IGST|CENTRAL|STATE GOODS|INTEGRATED'")->fetchAll();
    if ($old) {
        $rates = [];
        foreach ($old as $t) {
            $label = strtoupper($t['code'] . ' ' . $t['name']);
            $split = preg_match('/CGST|SGST|CENTRAL|STATE GOODS/', $label) && !preg_match('/IGST|INTEGRATED/', $label);
            $rates[] = round((float)$t['amount'] * ($split ? 2 : 1), 2);
        }
        $pdo->exec('DELETE FROM taxes WHERE id IN (' . implode(',', array_map(fn($t) => (int)$t['id'], $old)) . ')');
        $stdRates = array_unique(array_merge([0, 5, 12, 18, 28], $rates));
    } elseif ((int)$pdo->query('SELECT COUNT(*) FROM taxes')->fetchColumn() === 0) {
        $stdRates = [0, 5, 12, 18, 28];
    } else {
        $stdRates = [];
    }
    sort($stdRates);
    $exists = $pdo->prepare('SELECT COUNT(*) FROM taxes WHERE amount = ?');
    $insert = $pdo->prepare("INSERT INTO taxes (name, code, amount, type, platforms, enabled) VALUES (?, ?, ?, 'Percentage', 'All platforms', 1)");
    foreach ($stdRates as $rate) {
        $exists->execute([$rate]);
        if (!(int)$exists->fetchColumn()) {
            $label = rtrim(rtrim(number_format((float)$rate, 2, '.', ''), '0'), '.');
            $insert->execute(['GST ' . $label . '%', 'GST-' . $label, $rate]);
        }
    }
    // Link each product to the GST rate it already uses.
    $pdo->exec('UPDATE products p JOIN taxes t ON t.amount = p.tax_rate
                SET p.tax_id = t.id WHERE p.tax_id IS NULL');

    // v7: Stores page. Start the list with the store names already used on saved records.
    if ((int)$pdo->query('SELECT COUNT(*) FROM stores')->fetchColumn() === 0) {
        $pdo->exec("INSERT IGNORE INTO stores (name)
                    SELECT DISTINCT TRIM(store) FROM (
                        SELECT store FROM products UNION SELECT store FROM quotations UNION SELECT store FROM orders
                    ) s WHERE store IS NOT NULL AND TRIM(store) <> ''");
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

/**
 * Categories in tree order (parent first, then its sub-categories).
 * Each row: id, name, parent_id, depth, label ("Plywood › 18mm Ply").
 */
function category_tree(bool $enabledOnly = false): array
{
    $rows = db_all('SELECT id, name, parent_id, color_code FROM categories'
        . ($enabledOnly ? ' WHERE enabled = 1' : '') . ' ORDER BY sort_order, name');
    $byParent = [];
    $ids = array_column($rows, 'id');
    foreach ($rows as $r) {
        $pid = (int)$r['parent_id'];
        // Orphans (parent deleted / disabled) are shown at the top level.
        if ($pid && !in_array($r['parent_id'], $ids, false)) {
            $pid = 0;
        }
        $byParent[$pid][] = $r;
    }
    $out = [];
    $walk = function (int $pid, int $depth, string $prefix) use (&$walk, &$out, $byParent) {
        foreach ($byParent[$pid] ?? [] as $r) {
            $label = $prefix === '' ? $r['name'] : $prefix . ' › ' . $r['name'];
            $out[] = [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'parent_id' => $r['parent_id'] ? (int)$r['parent_id'] : null,
                'color_code' => $r['color_code'],
                'depth' => $depth,
                'label' => $label,
            ];
            if ($depth < 10) {
                $walk((int)$r['id'], $depth + 1, $label);
            }
        }
    };
    $walk(0, 0, '');
    return $out;
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

/* ---------------------------------------------------------------
 | GST RATES (Taxes page). One rate per tax, e.g. GST 18%:
 |   Inside the state  -> CGST 9% + SGST 9%
 |   Outside the state -> IGST 18%
 --------------------------------------------------------------- */
function gst_rates(bool $enabledOnly = true): array
{
    $rows = db_all('SELECT id, name, code, amount FROM taxes'
        . ($enabledOnly ? ' WHERE enabled = 1' : '') . ' ORDER BY amount, id');
    return array_map(fn($t) => [
        'id' => (int)$t['id'],
        'name' => $t['name'],
        'code' => $t['code'],
        'rate' => (float)$t['amount'],
    ], $rows);
}

/** "9% CGST + 9% SGST" (inside state) or "18% IGST" (outside state). */
function gst_split_label(float $rate, bool $inter): string
{
    $fmt = fn(float $v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    return $inter ? $fmt($rate) . '% IGST' : $fmt($rate / 2) . '% CGST + ' . $fmt($rate / 2) . '% SGST';
}

/**
 * Replace the product lines of an order and keep product stock correct:
 * stock of the old lines is given back first, then the new lines are taken out.
 */
/**
 * Lines that ask for more than the product's stock, e.g. ["Plain MDF 3 mm (only 10 available)"].
 * The same product on several lines is added together. Rows are locked (call inside a transaction).
 */
function stock_shortages(array $items): array
{
    $needed = [];
    foreach ($items as $it) {
        if (!empty($it['product_id'])) {
            $pid = (int)$it['product_id'];
            $needed[$pid] = ($needed[$pid] ?? 0) + (float)$it['quantity'];
        }
    }
    $short = [];
    foreach ($needed as $pid => $qty) {
        $row = db_one('SELECT name, stock FROM products WHERE id = ? FOR UPDATE', [$pid]);
        $have = $row ? (float)$row['stock'] : 0.0;
        if ($qty > $have + 0.0001) {
            $short[] = ($row['name'] ?? 'Product') . ' (only '
                . rtrim(rtrim(number_format(max(0, $have), 2, '.', ''), '0'), '.') . ' available)';
        }
    }
    return $short;
}

/** Thrown when a sale asks for more than the stock. */
class StockShortException extends RuntimeException
{
}

/** Stop the sale when any line is more than the stock (stock must never go below 0). */
function require_stock(array $items): void
{
    $short = stock_shortages($items);
    if ($short) {
        throw new StockShortException('Not enough stock: ' . implode(', ', $short) . '. Reduce the quantity.');
    }
}

/**
 * Replace an order's lines: old lines go back to stock, new lines are taken.
 * $checkStock = false lets the owner sell more than the stock on purpose
 * (Quotation > Convert to Invoice > "Convert anyway").
 */
function order_replace_items(int $orderId, array $items, bool $checkStock = true): void
{
    foreach (db_all('SELECT product_id, quantity FROM order_items WHERE order_id = ? AND product_id IS NOT NULL', [$orderId]) as $old) {
        db_exec('UPDATE products SET stock = stock + ? WHERE id = ?', [$old['quantity'], $old['product_id']]);
    }
    // Old lines are back in stock now, so an edit can reuse them.
    if ($checkStock) {
        require_stock($items);
    }
    db_exec('DELETE FROM order_items WHERE order_id = ?', [$orderId]);
    foreach ($items as $it) {
        db_insert('order_items', ['order_id' => $orderId] + $it);
        if (!empty($it['product_id'])) {
            db_exec('UPDATE products SET stock = stock - ? WHERE id = ?', [$it['quantity'], $it['product_id']]);
        }
    }
}

/** Totals of a list of lines (quantity, rate, tax %, discount %). */
function lines_totals(array $items): array
{
    $sub = 0.0;
    $disc = 0.0;
    $tax = 0.0;
    foreach ($items as $it) {
        $base = (float)$it['quantity'] * (float)$it['rate'];
        $d = $base * (float)$it['discount'] / 100;
        $sub += $base;
        $disc += $d;
        $tax += ($base - $d) * (float)$it['tax'] / 100;
    }
    return [
        'subtotal' => round($sub, 2),
        'discount' => round($disc, 2),
        'tax' => round($tax, 2),
        'amount' => round($sub - $disc + $tax, 2),
    ];
}

/**
 * Stock movement per product id:
 *   sold     = quantity billed on orders / invoices (stock was reduced for these)
 *   returned = good items returned by customers (approved, back in stock)
 *   damaged  = damaged items (approved returns, not back in stock)
 *   pending  = return requests not yet approved
 *   added    = total stock that came in (available + sold - returned)
 */
function product_stock_stats(): array
{
    $stats = [];
    $sold = db_all("SELECT oi.product_id, SUM(oi.quantity) AS qty
                    FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id
                    WHERE oi.product_id IS NOT NULL
                    GROUP BY oi.product_id");
    foreach ($sold as $r) {
        $stats[(int)$r['product_id']]['sold'] = (float)$r['qty'];
    }
    foreach (db_all('SELECT status, products FROM returns_damage') as $r) {
        foreach (json_decode((string)$r['products'], true) ?: [] as $p) {
            $pid = (int)($p['product_id'] ?? 0);
            $qty = (float)($p['quantity'] ?? 0);
            if (!$pid || $qty <= 0) {
                continue;
            }
            if ($r['status'] === 'Return Approved') {
                $key = ($p['reason'] ?? '') === 'damaged' ? 'damaged' : 'returned';
            } elseif ($r['status'] === 'Return Requested') {
                $key = 'pending';
            } else {
                continue;
            }
            $stats[$pid][$key] = ($stats[$pid][$key] ?? 0) + $qty;
        }
    }
    return $stats;
}

/** Adds sold / returned / damaged / pending / added to product rows (each needs id + stock). */
function attach_stock_stats(array $rows): array
{
    $stats = product_stock_stats();
    foreach ($rows as &$r) {
        $s = $stats[(int)$r['id']] ?? [];
        $r['sold'] = round($s['sold'] ?? 0, 2);
        $r['returned'] = round($s['returned'] ?? 0, 2);
        $r['damaged'] = round($s['damaged'] ?? 0, 2);
        $r['pending_return'] = round($s['pending'] ?? 0, 2);
        // Total that came into stock = what is left + what went out - what came back.
        $r['added'] = round((float)$r['stock'] + $r['sold'] - $r['returned'], 2);
    }
    unset($r);
    return $rows;
}

/**
 * Store names from the Stores page (enabled ones by default).
 * The list is never empty: the business name is added as the first store when none exist.
 */
function store_names(bool $enabledOnly = true): array
{
    $names = array_column(db_all('SELECT name FROM stores' . ($enabledOnly ? ' WHERE enabled = 1' : '') . ' ORDER BY id'), 'name');
    if (!$names && !(int)db_value('SELECT COUNT(*) FROM stores')) {
        $default = defined('APP_STORE') && APP_STORE !== '' ? APP_STORE : 'Main Store';
        db_exec('INSERT IGNORE INTO stores (name) VALUES (?)', [$default]);
        $names = [$default];
    }
    return $names;
}
