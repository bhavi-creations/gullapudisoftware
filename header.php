<?php
require_once __DIR__ . '/includes/init.php';
$pageTitle = $pageTitle ?? 'Admin Dashboard';
$asset = fn(string $path) => $path . '?v=' . filemtime(__DIR__ . '/' . $path);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
<title><?php echo e($pageTitle . ' | ' . COMPANY_SHORT); ?></title>

<!-- Inter font -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Bootstrap Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

<link rel="stylesheet" href="<?php echo $asset('assets/css/style.css'); ?>">
<link rel="stylesheet" href="<?php echo $asset('assets/css/layout.css'); ?>">
<link rel="stylesheet" href="<?php echo $asset('assets/css/premium.css'); ?>">

<!-- Loaded in <head> so every page's inline scripts can use them -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script src="<?php echo $asset('assets/js/app.js'); ?>"></script>
</head>

<body>

<?php
// Pages can set $fullScreen = true (e.g. the POS screens) to hide the sidebar.
$fullScreen = !empty($fullScreen);
if (!$fullScreen) {
    include __DIR__ . '/sidebar.php';
}
?>

<main class="sidebar-page-content<?php echo $fullScreen ? ' no-sidebar' : ''; ?>" id="pageContent">

<?php if (!$fullScreen):
    $tbUser = current_user();
    $tbName = $tbUser['name'] ?? 'User';
    $tbInitials = '';
    foreach (preg_split('/\s+/', trim($tbName)) as $part) {
        if ($part !== '' && mb_strlen($tbInitials) < 2) {
            $tbInitials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }
    $tbIsAdmin = ($tbUser['role'] ?? '') === 'admin';
    // Pages for Quick Search's "Go to" section.
    $tbPages = [];
    foreach ($appNav ?? [] as $group => $items) {
        foreach ($items as $it) {
            $tbPages[] = ['title' => $it[0], 'icon' => $it[1], 'url' => $it[2], 'group' => $group];
        }
    }
    $tbPages[] = ['title' => 'New Invoice', 'icon' => 'plus-circle', 'url' => 'direct-invoice.php', 'group' => 'Create'];
    $tbPages[] = ['title' => 'New Quotation', 'icon' => 'plus-circle', 'url' => 'add-quotation.php', 'group' => 'Create'];
    $tbPages[] = ['title' => 'New Customer', 'icon' => 'plus-circle', 'url' => 'add-customer.php', 'group' => 'Create'];
    $tbPages[] = ['title' => 'New Product', 'icon' => 'plus-circle', 'url' => 'add-product.php', 'group' => 'Create'];
    $tbPages[] = ['title' => 'My Profile', 'icon' => 'person-circle', 'url' => 'profile.php', 'group' => 'Account'];
    $tbPages[] = ['title' => 'Change Password', 'icon' => 'shield-lock', 'url' => 'change-password.php', 'group' => 'Account'];
?>
<!-- TOP BAR -->
<header class="topbar" id="topbar">
    <button type="button" class="tb-search" id="tbSearchOpen" aria-label="Quick search">
        <i class="bi bi-search"></i>
        <span class="tb-search-text">Search customers, products, invoices…</span>
        <kbd>Ctrl K</kbd>
    </button>

    <div class="tb-actions">
        <div class="dropdown">
            <button type="button" class="tb-new" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-plus-lg"></i><span>New</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end tb-menu">
                <li><a class="dropdown-item" href="direct-invoice.php"><i class="bi bi-receipt-cutoff"></i>Invoice</a></li>
                <li><a class="dropdown-item" href="add-quotation.php"><i class="bi bi-file-earmark-text"></i>Quotation</a></li>
                <li><a class="dropdown-item" href="add-order.php"><i class="bi bi-cart-plus"></i>Order</a></li>
                <li><a class="dropdown-item" href="payment.php"><i class="bi bi-cash-coin"></i>Payment</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="add-customer.php"><i class="bi bi-person-plus"></i>Customer</a></li>
                <li><a class="dropdown-item" href="add-product.php"><i class="bi bi-box-seam"></i>Product</a></li>
            </ul>
        </div>

        <div class="dropdown">
            <button type="button" class="tb-icon-btn" id="tbBell" data-bs-toggle="dropdown" data-bs-auto-close="outside"
                    aria-expanded="false" aria-label="Notifications">
                <i class="bi bi-bell"></i>
                <span class="tb-badge d-none" id="tbBellCount">0</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end tb-alerts">
                <div class="tb-alerts-head">
                    <strong>Notifications</strong>
                    <span id="tbAlertsSummary">Loading…</span>
                </div>
                <div class="tb-alerts-body" id="tbAlertsBody">
                    <div class="tb-empty"><span class="spinner-border spinner-border-sm"></span></div>
                </div>
            </div>
        </div>

        <div class="dropdown">
            <button type="button" class="tb-user" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="tb-avatar"><?php echo e($tbInitials ?: 'U'); ?></span>
                <span class="tb-user-text">
                    <span class="tb-user-name"><?php echo e($tbName); ?></span>
                    <span class="tb-user-role"><?php echo e(ucfirst($tbUser['role'] ?? '')); ?> · <?php echo e(COMPANY_SHORT); ?></span>
                </span>
                <i class="bi bi-chevron-down tb-user-caret"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end tb-menu">
                <li class="tb-menu-head">
                    <strong><?php echo e($tbName); ?></strong>
                    <span><?php echo e($tbUser['email'] ?? ''); ?></span>
                </li>
                <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person-circle"></i>My Profile</a></li>
                <li><a class="dropdown-item" href="change-password.php"><i class="bi bi-shield-lock"></i>Change Password</a></li>
                <?php if ($tbIsAdmin): ?>
                    <li><a class="dropdown-item" href="business-settings.php"><i class="bi bi-building-gear"></i>Business Settings</a></li>
                <?php endif; ?>
                <li><a class="dropdown-item" href="tel:+919642434343"><i class="bi bi-headset"></i>Support · +91 9642434343</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="logout.php" onclick="return confirm('Logout now?');"><i class="bi bi-box-arrow-right"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</header>

<!-- QUICK SEARCH (Ctrl+K) -->
<div class="cmdk" id="cmdk" hidden>
    <div class="cmdk-backdrop" data-cmdk-close></div>
    <div class="cmdk-panel" role="dialog" aria-modal="true" aria-label="Quick search">
        <div class="cmdk-input">
            <i class="bi bi-search"></i>
            <input type="text" id="cmdkInput" placeholder="Search customers, products, invoices, quotations or pages…" autocomplete="off" spellcheck="false">
            <kbd data-cmdk-close>Esc</kbd>
        </div>
        <div class="cmdk-results" id="cmdkResults" role="listbox"></div>
        <div class="cmdk-foot">
            <span><kbd>↑</kbd><kbd>↓</kbd> move</span>
            <span><kbd>Enter</kbd> open</span>
            <span><kbd>Esc</kbd> close</span>
        </div>
    </div>
</div>
<script>window.APP_PAGES = <?php echo json_encode($tbPages, json_flags()); ?>;</script>
<script src="<?php echo $asset('assets/js/premium.js'); ?>" defer></script>
<?php endif; ?>
