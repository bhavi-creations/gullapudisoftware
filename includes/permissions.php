<?php
/*
|--------------------------------------------------------------------------
| EMPLOYEE ACCESS  (managed from the "Access" page)
|--------------------------------------------------------------------------
| users.role = 'admin'  -> full access.
| users.role = 'staff'  -> only what users.permissions (JSON) allows:
|       {"quotations": ["view","add","edit","delete"], "products": ["view"], ...}
|   An older plain list ["quotations","products"] means full actions on those pages.
|   NULL permissions (users created before this feature) keep full access.
|--------------------------------------------------------------------------
*/

/** module key => [label, icon, pages] */
function app_modules(): array
{
    return [
        'dashboard'      => ['Dashboard', 'grid-1x2', ['dashboard.php']],
        'reports'        => ['Reports', 'bar-chart-line', ['reports.php']],
        'quotations'     => ['Quotations', 'file-earmark-text', ['quotation.php', 'add-quotation.php', 'quotation-print.php']],
        'master_quotations' => ['Master Quotations', 'star', ['master-quotation.php']],
        'orders'         => ['Orders', 'cart-check', ['orders.php', 'add-order.php']],
        'invoices'       => ['Invoices', 'receipt', ['invoices.php', 'invoice-print.php']],
        'direct_invoice' => ['Direct Invoice', 'receipt-cutoff', ['direct-invoice.php']],
        'payments'       => ['Payments', 'credit-card-2-front', ['payment.php']],
        'returns'        => ['Returns & Damage', 'arrow-return-left', ['returns-damage.php']],
        'products'       => ['Products', 'box-seam', ['products.php', 'add-product.php']],
        'categories'     => ['Categories', 'tags', ['categories.php', 'add-categories.php']],
        'stores'         => ['Stores', 'shop', ['stores.php']],
        'taxes'          => ['Taxes', 'percent', ['taxes.php']],
        'customers'      => ['Customers', 'people', ['customers.php', 'add-customer.php']],
        'employees'      => ['Employees', 'person-badge', ['employees.php', 'add-employees.php']],
        'tally'          => ['Tally Export & GST', 'arrow-left-right', ['tally-export.php']],
        'whatsapp'       => ['WhatsApp Center', 'whatsapp', ['whatsapp-center.php']],
        'backup'         => ['Backup & Restore', 'cloud-arrow-up', ['backup-restore.php']],
    ];
}

/** Actions that can be given per page. */
function app_actions(): array
{
    return ['view' => 'View', 'add' => 'Add', 'edit' => 'Edit', 'delete' => 'Delete'];
}

/** Clean a submitted list of module keys. */
function clean_permissions($list): array
{
    $list = is_array($list) ? $list : [];
    return array_values(array_intersect(array_keys(app_modules()), array_map('strval', $list)));
}

/**
 * Clean permissions into {module: [actions]}.
 * Accepts the new map format or the old plain list (= every action).
 * A module with any action always includes "view".
 */
function clean_permission_map($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $all = array_keys(app_actions());
    $map = [];
    if ($raw === [] || array_keys($raw) === range(0, count($raw) - 1)) {
        foreach (clean_permissions($raw) as $module) {
            $map[$module] = $all;
        }
        return $map;
    }
    foreach (app_modules() as $module => $_) {
        if (!isset($raw[$module]) || !is_array($raw[$module])) {
            continue;
        }
        $actions = array_values(array_intersect($all, array_map('strval', $raw[$module])));
        if ($actions && !in_array('view', $actions, true)) {
            array_unshift($actions, 'view');
        }
        if ($actions) {
            $map[$module] = $actions;
        }
    }
    return $map;
}

/** Full actions on each listed module. */
function full_permission_map(array $modules): array
{
    return clean_permission_map(clean_permissions($modules));
}

/** Map of what the logged-in user may do, or null for full access. */
function user_permissions(): ?array
{
    static $cache = [];
    $user = current_user();
    if (!$user || ($user['role'] ?? '') === 'admin') {
        return null;
    }
    $id = (int)$user['id'];
    if (!array_key_exists($id, $cache)) {
        $raw = db_value('SELECT permissions FROM users WHERE id = ?', [$id]);
        if ($raw === null || $raw === false || $raw === '') {
            $cache[$id] = null;
        } else {
            $decoded = json_decode((string)$raw, true);
            $cache[$id] = is_array($decoded) ? clean_permission_map($decoded) : null;
        }
    }
    return $cache[$id];
}

/** Module keys the user can open (null = all). */
function granted_modules(): ?array
{
    $perms = user_permissions();
    return $perms === null ? null : array_keys($perms);
}

function user_can(string $module, string $action = 'view'): bool
{
    $perms = user_permissions();
    return $perms === null || in_array($action, $perms[$module] ?? [], true);
}

/** Which module a page belongs to (null = always allowed, e.g. profile). */
function page_module(string $page): ?string
{
    foreach (app_modules() as $key => [, , $pages]) {
        if (in_array($page, $pages, true)) {
            return $key;
        }
    }
    return null;
}

/** First page this user may open (landing page after login / when blocked). */
function first_allowed_page(): string
{
    foreach (app_modules() as $key => [, , $pages]) {
        if (user_can($key)) {
            return $pages[0];
        }
    }
    return 'profile.php';
}

/** Module for an api.php action, e.g. customers.save -> customers. */
function api_action_module(string $action): ?string
{
    $prefix = strtok($action, '.');
    return in_array($prefix, ['customers', 'categories', 'products'], true) ? $prefix : null;
}

/** Which action (view / add / edit / delete) the current request needs. */
function request_action(string $page): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method !== 'POST') {
        // Opening a form page needs add / edit.
        $editParams = ['add-quotation.php' => 'edit', 'add-order.php' => 'edit', 'add-product.php' => 'id', 'add-customer.php' => 'id',
            'direct-invoice.php' => 'edit'];
        if (isset($editParams[$page])) {
            return !empty($_GET[$editParams[$page]]) ? 'edit' : 'add';
        }
        if (in_array($page, ['add-categories.php', 'add-employees.php'], true)) {
            return 'add';
        }
        return 'view';
    }

    $action = strtolower((string)($page === 'api.php' ? ($_GET['action'] ?? '') : ($_POST['action'] ?? '')));
    if ($action === '' && in_array($page, ['add-quotation.php', 'add-order.php'], true)) {
        $data = json_decode((string)($_POST['quotation_data'] ?? ''), true);
        return !empty($data['edit_id']) ? 'edit' : 'add';
    }
    if ($action === '' && $page === 'direct-invoice.php') {
        $data = json_decode((string)($_POST['invoice_data'] ?? ''), true);
        return !empty($data['edit_id']) ? 'edit' : 'add';
    }
    if ($action === '' && in_array($page, ['add-product.php', 'add-customer.php'], true)) {
        return !empty($_POST['id']) ? 'edit' : 'add';
    }
    if ($action === '' && $page === 'add-employees.php') {
        return 'add';
    }
    if (preg_match('/(^|[._])(items|list|refresh|refresh_history|ledger|view|search|alerts)$/', $action)) {
        return 'view';
    }
    if (str_contains($action, 'delete')) {
        return 'delete';
    }
    if (str_starts_with($action, 'create') || in_array($action, ['save_payment', 'log_message', 'categories.save_bulk', 'customers.import'], true)) {
        return 'add';
    }
    if (preg_match('/(^|[._])save(_\w+)?$/', $action)) {
        $id = $page === 'api.php' ? null : ($_POST['id'] ?? null);
        if ($page === 'api.php') {
            $input = json_decode((string)file_get_contents('php://input'), true);
            $id = is_array($input) ? ($input['id'] ?? null) : ($_POST['id'] ?? null);
        }
        return !empty($id) ? 'edit' : 'add';
    }
    return 'edit';
}

/** Stops the request when the current page / action is not allowed for this employee. */
function enforce_page_access(): void
{
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $module = $page === 'api.php'
        ? api_action_module((string)($_GET['action'] ?? ''))
        : page_module($page);

    if ($module === null || user_permissions() === null) {
        return;
    }
    $need = request_action($page);
    // Uploading an image belongs to whichever form is open.
    if ($page === 'api.php' && ($_GET['action'] ?? '') === 'upload') {
        return;
    }
    if (user_can($module, $need)) {
        return;
    }

    $label = app_modules()[$module][0];
    $message = $need === 'view'
        ? 'You do not have access to ' . $label . '. Please contact your administrator.'
        : 'You do not have ' . app_actions()[$need] . ' access for ' . $label . '. Please contact your administrator.';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        json_response(['success' => false, 'message' => $message], 403);
    }
    if ($page === 'dashboard.php') {
        header('Location: ' . first_allowed_page());
        exit;
    }
    http_response_code(403);
    $back = user_can($module) ? app_modules()[$module][2][0] : first_allowed_page();
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Access denied</title></head><body style="font-family:Arial,sans-serif;background:#f5f7fb;margin:0">'
        . '<div style="max-width:460px;margin:12vh auto;background:#fff;border-radius:14px;padding:32px;box-shadow:0 8px 30px rgba(0,0,0,.08);text-align:center">'
        . '<h2 style="margin:0 0 10px;color:#b91c1c">Access denied</h2><p style="color:#444">' . e($message) . '</p>'
        . '<a href="' . e($back) . '" style="display:inline-block;margin-top:12px;padding:10px 18px;background:#1479ff;color:#fff;border-radius:8px;text-decoration:none">Go back</a>'
        . '</div></body></html>';
    exit;
}

/**
 * CSS that hides Add / Edit / Delete buttons the employee is not allowed to use
 * on the current page (the server also blocks the request itself).
 */
function permission_hide_css(): string
{
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $module = page_module($page);
    if ($module === null || user_permissions() === null) {
        return '';
    }
    $rules = [];
    if (!user_can($module, 'delete')) {
        $rules[] = '[data-action="delete"],[data-action^="delete"],[title^="Delete"],.app-details-btn.danger,[data-perm="delete"]';
    }
    if (!user_can($module, 'edit')) {
        $rules[] = '[data-action="edit"],[data-action="status"],[title^="Edit"],a[href*="?edit="],a[href*="add-product.php?id="],'
            . 'a[href*="add-customer.php?id="],[data-perm="edit"],[data-toggle-tax]';
    }
    if (!user_can($module, 'add')) {
        $rules[] = '[data-perm="add"],.qt-add-btn,.pr-add-btn,.inv-add-btn,.tax-add-btn,.pay-add-btn,.cat-add-btn,'
            . 'a[href="add-quotation.php"],a[href="add-product.php"],a[href="add-categories.php"],a[href="add-customer.php"],'
            . 'a[href="add-employees.php"],a[href="direct-invoice.php"]';
    }
    return $rules ? implode(',', $rules) . '{display:none !important}' : '';
}
