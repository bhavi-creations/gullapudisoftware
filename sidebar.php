
<?php
/*
|--------------------------------------------------------------------------
| GULLAPUDI SIDEBAR - SCREENSHOT MATCHING DESIGN
|--------------------------------------------------------------------------
| File: sidebar.php
| Main Class: sidebar
| Width: 226px
| Bootstrap 5 + Bootstrap Icons
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/init.php';

$currentPage = basename(
    parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''
);

$sidebarMenus = [
    ['Dashboard', 'speedometer2', 'dashboard.php', ['dashboard.php']],
    ['Customers', 'people-fill', 'customers.php', ['customers.php', 'add-customer.php']],
    ['Categories', 'tags-fill', 'categories.php', ['categories.php', 'add-categories.php']],
    ['Products', 'boxes', 'products.php', ['products.php', 'add-product.php']],
    ['Taxes', 'percent', 'taxes.php', ['taxes.php']],
    ['Quotations', 'file-earmark-text', 'quotation.php', ['quotation.php', 'add-quotation.php']],
    ['Orders', 'cart-check', 'orders.php', ['orders.php', 'add-order.php']],
    ['Invoices', 'receipt', 'invoices.php', ['invoices.php']],
    ['Direct Invoice', 'receipt-cutoff', 'direct-invoice.php', ['direct-invoice.php']],
    ['Payments', 'credit-card', 'payment.php', ['payment.php']],
    ['Employees', 'person-circle', 'employees.php', ['employees.php', 'add-employees.php']],
    ['Reports', 'bar-chart-line', 'reports.php', ['reports.php']],
    ['Tally Export & GST', 'arrow-left-right', 'tally-export.php', ['tally-export.php']],
    ['WhatsApp Center', 'whatsapp', 'whatsapp-center.php', ['whatsapp-center.php']],
    ['Backup & Restore', 'cloud-arrow-up-fill', 'backup-restore.php', ['backup-restore.php']],
    ['Returns & Damage', 'truck-front-fill', 'returns-damage.php', ['returns-damage.php']]
];

function sbEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$sidebarUser = $_SESSION['user_name'] ?? 'User';
$sidebarEmail = $_SESSION['user_email'] ?? '';
$sidebarInitials = '';
foreach (preg_split('/\s+/', trim($sidebarUser)) as $sbPart) {
    if ($sbPart !== '' && strlen($sidebarInitials) < 2) {
        $sidebarInitials .= strtoupper(mb_substr($sbPart, 0, 1));
    }
}
$sidebarInitials = $sidebarInitials ?: 'U';
?>


<!-- MOBILE TOGGLE -->
<button type="button"
        class="sidebar-toggle"
        id="sidebarToggle"
        aria-controls="mainSidebar"
        aria-expanded="false"
        aria-label="Open sidebar">
    <i class="bi bi-list"></i>
</button>

<!-- MOBILE OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- =====================================
     SIDEBAR STRUCTURE
===================================== -->

<aside class="sidebar" id="mainSidebar">

    <!-- LOGO -->
    <div class="sidebar-logo-section">

        <a href="dashboard.php" class="text-decoration-none">

            <?php if (is_file(__DIR__ . '/assets/images/gullapudi-logo.png')): ?>
            <img
                src="assets/images/gullapudi-logo.png"
                alt="Gullapudi Logo"
                class="sidebar-logo"
            >
            <?php endif; ?>

            <h2 class="sidebar-company-name">
                GULLAPUDI
            </h2>

            <p class="sidebar-company-subtitle">
                GLASS &amp; PLYWOOD CENTRE
            </p>

        </a>

    </div>

    <!-- MENU -->
    <nav class="sidebar-navigation" aria-label="Main Menu">

        <ul class="sidebar-menu">

            <?php foreach ($sidebarMenus as $menu): ?>

                <?php
                $active = in_array(
                    $currentPage,
                    $menu[3],
                    true
                );
                ?>

                <li class="sidebar-menu-item">

                    <a
                        href="<?php echo sbEscape($menu[2]); ?>"
                        class="sidebar-menu-link <?php echo $active ? 'active' : ''; ?>"
                        <?php echo $active ? 'aria-current="page"' : ''; ?>
                        title="<?php echo sbEscape($menu[0]); ?>"
                    >

                        <i class="bi bi-<?php echo sbEscape($menu[1]); ?> sidebar-menu-icon"></i>

                        <span class="sidebar-menu-text">
                            <?php echo sbEscape($menu[0]); ?>
                        </span>

                    </a>

                </li>

            <?php endforeach; ?>

        </ul>

    </nav>

    <!-- BOTTOM -->
    <div class="sidebar-bottom">

        <!-- SUPPORT -->
        <a href="tel:+919642434343"
           class="sidebar-support">

            <div class="sidebar-support-icon">
                <i class="bi bi-headset"></i>
            </div>

            <div class="sidebar-support-content">

                <div class="sidebar-support-title">
                    Support
                </div>

                <div class="sidebar-support-help">
                    Need Help?
                </div>

                <div class="sidebar-support-phone">
                    +91 9642434343
                </div>

            </div>

        </a>

        <!-- PROFILE -->
        <div class="sidebar-profile">

            <button type="button"
                    class="sidebar-profile-button"
                    id="sidebarProfileButton"
                    aria-expanded="false"
                    aria-controls="sidebarProfileDropdown">

                <span class="sidebar-avatar">
                    <?php echo sbEscape($sidebarInitials); ?>
                </span>

                <span class="sidebar-profile-details">

                    <span class="sidebar-profile-name">
                        <?php echo sbEscape($sidebarUser); ?>
                    </span>

                    <span class="sidebar-profile-email">
                        <?php echo sbEscape($sidebarEmail); ?>
                    </span>

                </span>

                <i class="bi bi-chevron-down sidebar-profile-arrow"></i>

            </button>

            <div class="sidebar-profile-dropdown"
                 id="sidebarProfileDropdown"
                 hidden>

                <a href="profile.php">
                    <i class="bi bi-person"></i>
                    My Profile
                </a>

                <a href="change-password.php">
                    <i class="bi bi-shield-lock"></i>
                    Change Password
                </a>

            </div>

        </div>

        <!-- LOGOUT -->
        <a href="logout.php"
           class="sidebar-logout"
           onclick="return confirm('Logout now?');">

            <i class="bi bi-box-arrow-right"></i>
            Logout

        </a>

    </div>

</aside>

<script>
(function () {
    "use strict";

    const sidebar = document.getElementById("mainSidebar");
    const toggle = document.getElementById("sidebarToggle");
    const overlay = document.getElementById("sidebarOverlay");

    const profileButton =
        document.getElementById("sidebarProfileButton");

    const profileDropdown =
        document.getElementById("sidebarProfileDropdown");

    function setSidebar(open) {

        sidebar.classList.toggle("open", open);
        overlay.classList.toggle("show", open);

        toggle.setAttribute("aria-expanded", String(open));
        toggle.setAttribute(
            "aria-label",
            open ? "Close sidebar" : "Open sidebar"
        );

        toggle.innerHTML = open
            ? '<i class="bi bi-x-lg"></i>'
            : '<i class="bi bi-list"></i>';
    }

    toggle.addEventListener("click", function () {
        setSidebar(!sidebar.classList.contains("open"));
    });

    overlay.addEventListener("click", function () {
        setSidebar(false);
    });

    profileButton.addEventListener("click", function () {

        const open = this.getAttribute("aria-expanded") === "true";

        this.setAttribute("aria-expanded", String(!open));

        profileDropdown.hidden = open;
    });

    document.addEventListener("keydown", function (event) {

        if (event.key === "Escape") {
            setSidebar(false);
            profileDropdown.hidden = true;
            profileButton.setAttribute("aria-expanded", "false");
        }
    });

    window.addEventListener("resize", function () {

        if (window.innerWidth >= 992) {
            setSidebar(false);
        }
    });

    /* Automatically scroll active menu into view if needed */
    const activeMenu =
        sidebar.querySelector(".sidebar-menu-link.active");

    if (activeMenu) {
        const nav = sidebar.querySelector(".sidebar-navigation");
        const navRect = nav.getBoundingClientRect();
        const itemRect = activeMenu.getBoundingClientRect();

        if (itemRect.bottom > navRect.bottom ||
            itemRect.top < navRect.top) {
            activeMenu.scrollIntoView({
                block: "nearest",
                behavior: "instant"
            });
        }
    }

})();
</script>
