<?php
/*
|--------------------------------------------------------------------------
| BOOTSTRAP: include this at the very top of every page.
|--------------------------------------------------------------------------
| - Starts the session
| - Connects to MySQL (auto-creates DB + tables)
| - Requires login (except pages that set $publicPage = true)
| - CSRF + JSON helpers
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/permissions.php';

db(); // connect to the logged-in business's database + install schema

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_valid(): bool
{
    $sent = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return $sent !== '' && hash_equals(csrf_token(), $sent);
}

function require_csrf_json(): void
{
    if (!csrf_valid()) {
        json_response(['success' => false, 'message' => 'Session expired. Please refresh the page.'], 403);
    }
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = is_logged_in()
            ? db_one('SELECT id, name, username, email, mobile, dob, role, enabled FROM users WHERE id = ?', [(int)$_SESSION['user_id']])
            : null;
    }
    return $user;
}

function json_flags(): int
{
    return JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
}

/* ---------- Authentication guard ---------- */
if (empty($publicPage)) {
    $user = current_user();
    // A business that has been disabled from Business Accounts is logged out everywhere.
    $account = current_account();
    if (!$account || $account['status'] !== 'active') {
        $user = null;
    }
    if (!$user || !(int)$user['enabled']) {
        $_SESSION = [];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            json_response(['success' => false, 'message' => 'Please login again.'], 401);
        }
        header('Location: login.php');
        exit;
    }
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    enforce_page_access();
}

company_define();
csrf_token();
