<?php
$publicPage = true;
require_once __DIR__ . '/includes/init.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = !empty($_GET['expired']) ? 'Your session expired. Please sign in again to continue.' : '';
$email = '';
$nextPage = trim((string)($_POST['next'] ?? $_GET['next'] ?? ''));
if (!preg_match('/^[A-Za-z0-9_-]+\.php$/D', $nextPage) || in_array($nextPage, ['login.php', 'logout.php'], true)) {
    $nextPage = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (!csrf_valid()) {
        $error = 'Session expired. Please try again.';
    } else {
        // Each business has its own database: find the one this login belongs to.
        $found = $email !== '' && $password !== '' ? login_find($email, $password) : ['error' => 'Enter your username and password.'];

        if (isset($found['error'])) {
            $error = $found['error'];
        } else {
            $user = $found['user'];
            $pdo = $found['pdo'];
            session_regenerate_id(true);
            $_SESSION = ['csrf_token' => $_SESSION['csrf_token'] ?? null];
            $_SESSION['tenant_db'] = $found['account']['db_name'];
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
            $pdo->prepare('INSERT INTO activity_log (user_name, module, action) VALUES (?, ?, ?)')
                ->execute([$user['name'], 'Auth', $user['name'] . ' logged in']);
            header('Location: ' . ($nextPage !== '' ? $nextPage : 'dashboard.php'));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gullapudi Glass &amp; Plywood</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    * { box-sizing: border-box; }
    body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px 16px;
        background: #0b1118;
        font-family: "Segoe UI", Arial, sans-serif;
        color: #142238;
    }

    .lg-shell {
        width: 100%;
        max-width: 1180px;
        min-height: 640px;
        display: grid;
        grid-template-columns: 1.1fr 1fr;
        border-radius: 22px;
        overflow: hidden;
        border: 1px solid #263241;
        box-shadow: 0 30px 80px rgba(0, 0, 0, .45);
    }

    /* LEFT */
    .lg-left {
        background: #0b1118;
        color: #fff;
        padding: 28px 28px 34px;
        display: flex;
        flex-direction: column;
    }
    .lg-logo {
        width: 290px;
        max-width: 100%;
        aspect-ratio: 1;
        background: #fff;
        display: block;
        object-fit: contain;
    }
    .lg-tagline {
        font-size: 21px;
        font-weight: 700;
        line-height: 1.4;
        margin: 26px 0 0;
        max-width: 470px;
    }
    .lg-rule { width: 58px; height: 2px; background: #d6a648; margin: 14px 0 24px; border: 0; }
    .lg-features {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 10px;
        padding: 10px;
        border: 1px solid #263241;
        border-radius: 12px;
    }
    .lg-feature {
        background: #151d27;
        border-radius: 8px;
        padding: 12px 6px;
        text-align: center;
        font-size: 12.5px;
        font-weight: 700;
        line-height: 1.25;
    }
    .lg-feature i {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        border: 1px solid #d6a648;
        border-radius: 8px;
        color: #d6a648;
        background: rgba(214, 166, 72, .1);
        font-size: 17px;
        margin-bottom: 8px;
    }
    .lg-motto { margin-top: auto; padding-top: 30px; text-align: center; font-size: 19px; font-weight: 800; }
    .lg-motto span { color: #e2a93b; }

    /* RIGHT */
    .lg-right {
        background: #fff;
        padding: 28px 28px 30px;
        display: flex;
        flex-direction: column;
    }
    .lg-lang {
        align-self: flex-end;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: 1px solid #dfe5ee;
        border-radius: 8px;
        padding: 8px 13px;
        font-size: 14px;
        font-weight: 700;
        color: #142238;
    }
    .lg-form-wrap { margin: auto 0; padding: 24px 0; }
    .lg-title { font-size: 30px; font-weight: 800; margin: 0; color: #0b1118; }
    .lg-sub { color: #64748b; font-size: 15px; margin: 6px 0 26px; }
    .lg-label { display: block; font-size: 14px; font-weight: 700; margin-bottom: 8px; }
    .lg-field {
        display: flex;
        align-items: center;
        gap: 10px;
        height: 56px;
        border: 1px solid #dfe5ee;
        border-radius: 8px;
        padding: 0 16px;
        margin-bottom: 22px;
        color: #64748b;
        transition: border-color .15s, box-shadow .15s;
    }
    .lg-field:focus-within { border-color: #1479ff; box-shadow: 0 0 0 3px rgba(20, 121, 255, .12); }
    .lg-field input {
        flex: 1;
        min-width: 0;
        border: 0;
        outline: 0;
        font-size: 15px;
        color: #142238;
        background: transparent;
    }
    .lg-field input::placeholder { color: #8a97a8; }
    .lg-eye { border: 0; background: transparent; color: #8a97a8; font-size: 17px; padding: 4px; cursor: pointer; }
    .lg-btn {
        width: 100%;
        height: 56px;
        border: 0;
        border-radius: 8px;
        background: #1479ff;
        color: #fff;
        font-size: 17px;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        cursor: pointer;
        box-shadow: 0 8px 20px rgba(20, 121, 255, .3);
    }
    .lg-btn:hover { background: #0868e6; }
    .lg-btn i { font-size: 22px; }
    .lg-error {
        background: #fee2e2;
        color: #b91c1c;
        border-radius: 8px;
        padding: 10px 14px;
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 18px;
    }
    .lg-secure {
        margin-top: 28px;
        display: flex;
        align-items: center;
        gap: 14px;
        background: #ebf3ff;
        border-radius: 10px;
        padding: 14px 16px;
    }
    .lg-secure-icon {
        width: 42px; height: 42px; flex: 0 0 42px;
        border-radius: 50%;
        background: #dbe8fb;
        color: #1d5fd1;
        display: flex; align-items: center; justify-content: center;
        font-size: 18px;
    }
    .lg-secure b { display: block; color: #1d5fd1; font-size: 15px; margin-bottom: 2px; }
    .lg-secure p { margin: 0; color: #33465e; font-size: 13.5px; line-height: 1.4; }
    .lg-secure-lock {
        width: 58px; height: 58px; flex: 0 0 58px;
        margin-left: auto;
        border-radius: 50%;
        background: #1479ff;
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 20px;
    }
    .lg-foot {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 16px;
        font-size: 13.5px;
    }
    .lg-help b { display: flex; align-items: center; gap: 7px; font-size: 14.5px; }
    .lg-help a { color: #1479ff; font-weight: 800; font-size: 15px; text-decoration: none; }
    .lg-copy { color: #475569; font-weight: 600; text-align: right; line-height: 1.4; }

    @media (max-width: 991px) {
        .lg-shell { grid-template-columns: 1fr; min-height: 0; }
        .lg-logo { width: 180px; }
        .lg-features { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .lg-motto { padding-top: 22px; }
    }
    @media (max-width: 480px) {
        .lg-left, .lg-right { padding: 20px 16px; }
        .lg-features { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .lg-title { font-size: 25px; }
        .lg-secure-lock { display: none; }
        .lg-foot { flex-direction: column; align-items: flex-start; }
        .lg-copy { text-align: left; }
    }
</style>
</head>
<body>

<div class="lg-shell">

    <!-- LEFT: BRAND -->
    <div class="lg-left">
        <img src="assets/images/logo.jpg" alt="Gullapudi Glass &amp; Plywood Centre" class="lg-logo">

        <p class="lg-tagline">Complete Business Management Software for Plywood &amp; Hardware Industry</p>
        <hr class="lg-rule">

        <div class="lg-features">
            <div class="lg-feature"><i class="bi bi-file-earmark-text"></i><br>Smart Billing</div>
            <div class="lg-feature"><i class="bi bi-person-vcard"></i><br>Customer Management</div>
            <div class="lg-feature"><i class="bi bi-boxes"></i><br>Stock &amp; Inventory</div>
            <div class="lg-feature"><i class="bi bi-bar-chart-line"></i><br>Reports &amp; Analytics</div>
            <div class="lg-feature"><i class="bi bi-building"></i><br>Accounts &amp; Finance</div>
        </div>

        <div class="lg-motto">Manage <span>Better</span>, Sell <span>More</span>, Grow <span>Faster</span></div>
    </div>

    <!-- RIGHT: LOGIN -->
    <div class="lg-right">
        <span class="lg-lang"><i class="bi bi-globe2"></i> English</span>

        <div class="lg-form-wrap">
            <h1 class="lg-title">Welcome Back!</h1>
            <p class="lg-sub">Login to your account to continue</p>

            <?php if ($error): ?>
                <div class="lg-error"><i class="bi bi-exclamation-circle me-1"></i> <?php echo e($error); ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                <?php if ($nextPage !== ''): ?>
                    <input type="hidden" name="next" value="<?php echo e($nextPage); ?>">
                <?php endif; ?>

                <label class="lg-label" for="email">Username / Mobile</label>
                <div class="lg-field">
                    <i class="bi bi-person"></i>
                    <input type="text" id="email" name="email" value="<?php echo e($email); ?>"
                           placeholder="Enter username or mobile number" required autofocus autocomplete="username">
                </div>

                <label class="lg-label" for="password">Password</label>
                <div class="lg-field">
                    <i class="bi bi-lock-fill"></i>
                    <input type="password" id="password" name="password"
                           placeholder="Enter your password" required autocomplete="current-password">
                    <button class="lg-eye" type="button" id="togglePass" aria-label="Show password">
                        <i class="bi bi-eye-slash"></i>
                    </button>
                </div>

                <button type="submit" class="lg-btn">Login <i class="bi bi-arrow-right"></i></button>
            </form>

            <div class="lg-secure">
                <div class="lg-secure-icon"><i class="bi bi-shield-fill"></i></div>
                <div>
                    <b>Secure &amp; Reliable</b>
                    <p>Your business data is 100% safe with advanced security and daily backups.</p>
                </div>
                <div class="lg-secure-lock"><i class="bi bi-lock-fill"></i></div>
            </div>
        </div>

        <div class="lg-foot">
            <div class="lg-help">
                <b><i class="bi bi-headset"></i> Need Help?</b>
                <a href="tel:+919642343434">+91 9642343434</a>
            </div>
            <div class="lg-copy">(c) <?php echo date('Y'); ?> Gullapudi Glass &amp; Plywood Centre<br>All Rights Reserved</div>
        </div>
    </div>

</div>

<script>
document.getElementById("togglePass").addEventListener("click", function () {
    const input = document.getElementById("password");
    const show = input.type === "password";
    input.type = show ? "text" : "password";
    this.innerHTML = show ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
    this.setAttribute("aria-label", show ? "Hide password" : "Show password");
});
</script>
</body>
</html>
