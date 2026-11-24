<?php
/*
|--------------------------------------------------------------------------
| SIDEBAR (grouped navigation)
|--------------------------------------------------------------------------
| $appNav is also used by the top bar's Quick Search (Ctrl+K) to open pages.
| Profile, change password and logout live in the top bar's user menu.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/init.php';

$currentPage = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$isAdmin = (current_user()['role'] ?? '') === 'admin';

// Arranged in the order the work happens:
// Dashboard -> Sales (quote, order, bill) -> Money -> Stock -> People -> Reports -> Settings.
// [label, icon, url, pages that mark it active, who sees it: '' = by Access rights, 'admin', 'super']
$appNav = [
    'Overview' => [
        ['Dashboard', 'grid-1x2', 'dashboard.php', ['dashboard.php']],
    ],
    'Sales' => [
        ['Quotations', 'file-earmark-text', 'quotation.php', ['quotation.php', 'add-quotation.php', 'quotation-print.php']],
        ['Master Quotations', 'star', 'master-quotation.php', ['master-quotation.php']],
        ['Orders', 'cart-check', 'orders.php', ['orders.php', 'add-order.php']],
        ['Direct Invoice', 'receipt-cutoff', 'direct-invoice.php', ['direct-invoice.php']],
        ['Invoices', 'receipt', 'invoices.php', ['invoices.php', 'invoice-print.php']],
    ],
    'Payments & Returns' => [
        ['Payments', 'credit-card-2-front', 'payment.php', ['payment.php']],
        ['Returns & Damage', 'arrow-return-left', 'returns-damage.php', ['returns-damage.php']],
    ],
    'Stock' => [
        ['Products', 'box-seam', 'products.php', ['products.php', 'add-product.php']],
        ['Categories', 'tags', 'categories.php', ['categories.php', 'add-categories.php']],
    ],
    'People' => [
        ['Customers', 'people', 'customers.php', ['customers.php', 'add-customer.php']],
        ['Employees', 'person-badge', 'employees.php', ['employees.php', 'add-employees.php']],
        // Owner gives each employee View / Add / Edit / Delete access per page.
        ['Access', 'shield-lock', 'access.php', ['access.php'], 'admin'],
    ],
    'Reports' => [
        ['Reports', 'bar-chart-line', 'reports.php', ['reports.php']],
        ['Tally Export & GST', 'arrow-left-right', 'tally-export.php', ['tally-export.php']],
        ['WhatsApp Center', 'whatsapp', 'whatsapp-center.php', ['whatsapp-center.php']],
    ],
    'Settings' => [
        ['Taxes (GST)', 'percent', 'taxes.php', ['taxes.php']],
        ['Business Settings', 'building-gear', 'business-settings.php', ['business-settings.php'], 'admin'],
        ['Backup & Restore', 'cloud-arrow-up', 'backup-restore.php', ['backup-restore.php']],
        // Only the main business's administrators can create / manage other businesses.
        ['Business Accounts', 'buildings', 'business-accounts.php', ['business-accounts.php'], 'super'],
    ],
];
// Hide pages this user cannot open (Employees > Access, admin-only pages).
foreach ($appNav as $group => $items) {
    $appNav[$group] = array_values(array_filter($items, function ($menu) use ($isAdmin) {
        $who = $menu[4] ?? '';
        if ($who === 'admin') {
            return $isAdmin;
        }
        if ($who === 'super') {
            return is_super_admin();
        }
        $module = page_module($menu[2]);
        return $module === null || user_can($module);
    }));
    if (!$appNav[$group]) {
        unset($appNav[$group]);
    }
}

function sbEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<!-- MOBILE TOGGLE -->
<button type="button" class="sidebar-toggle" id="sidebarToggle"
        aria-controls="mainSidebar" aria-expanded="false" aria-label="Open sidebar">
    <i class="bi bi-list"></i>
</button>

<!-- MOBILE OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="mainSidebar">

    <!-- BRAND -->
    <div class="sidebar-logo-section">
        <a href="dashboard.php" class="sb-brand text-decoration-none">
            <span class="sb-brand-mark">
                <?php if (COMPANY_LOGO): ?>
                    <img src="<?php echo sbEscape(COMPANY_LOGO); ?>" alt="">
                <?php else: ?>
                    <?php echo sbEscape(mb_strtoupper(mb_substr(COMPANY_SHORT, 0, 1))); ?>
                <?php endif; ?>
            </span>
            <span class="sb-brand-text">
                <span class="sidebar-company-name"><?php echo sbEscape(COMPANY_SHORT); ?></span>
                <?php if (COMPANY_TAGLINE !== ''): ?>
                    <span class="sidebar-company-subtitle"><?php echo sbEscape(COMPANY_TAGLINE); ?></span>
                <?php endif; ?>
            </span>
        </a>
    </div>

    <!-- MENU -->
    <nav class="sidebar-navigation" aria-label="Main Menu">
        <?php foreach ($appNav as $group => $items): ?>
            <div class="sb-group"><?php echo sbEscape($group); ?></div>
            <ul class="sidebar-menu">
                <?php foreach ($items as $menu): ?>
                    <?php $active = in_array($currentPage, $menu[3], true); ?>
                    <li class="sidebar-menu-item">
                        <a href="<?php echo sbEscape($menu[2]); ?>"
                           class="sidebar-menu-link<?php echo $active ? ' active' : ''; ?>"
                           <?php echo $active ? 'aria-current="page"' : ''; ?>>
                            <i class="bi bi-<?php echo sbEscape($menu[1]); ?> sidebar-menu-icon"></i>
                            <span class="sidebar-menu-text"><?php echo sbEscape($menu[0]); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </nav>

    <!-- SUPPORT -->
    <div class="sidebar-bottom">
        <a href="tel:+919642434343" class="sb-support">
            <span class="sb-support-icon"><i class="bi bi-headset"></i></span>
            <span>
                <span class="sb-support-title">Need help?</span>
                <span class="sb-support-phone">+91 9642434343</span>
            </span>
        </a>
    </div>

</aside>

<script>
(function () {
    "use strict";

    const sidebar = document.getElementById("mainSidebar");
    const toggle = document.getElementById("sidebarToggle");
    const overlay = document.getElementById("sidebarOverlay");

    function setSidebar(open) {
        sidebar.classList.toggle("open", open);
        overlay.classList.toggle("show", open);
        toggle.setAttribute("aria-expanded", String(open));
        toggle.setAttribute("aria-label", open ? "Close sidebar" : "Open sidebar");
        toggle.innerHTML = open ? '<i class="bi bi-x-lg"></i>' : '<i class="bi bi-list"></i>';
    }

    toggle.addEventListener("click", () => setSidebar(!sidebar.classList.contains("open")));
    overlay.addEventListener("click", () => setSidebar(false));
    document.addEventListener("keydown", e => { if (e.key === "Escape") setSidebar(false); });
    window.addEventListener("resize", () => { if (window.innerWidth >= 992) setSidebar(false); });

    // Keep the active menu item visible.
    const activeMenu = sidebar.querySelector(".sidebar-menu-link.active");
    if (activeMenu) activeMenu.scrollIntoView({ block: "nearest", behavior: "instant" });
})();
</script>
