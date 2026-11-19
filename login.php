<?php
$publicPage = true;
require_once __DIR__ . '/includes/init.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (!csrf_valid()) {
        $error = 'Session expired. Please try again.';
    } else {
        $user = db_one('SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1', [$email, $email]);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid email or password.';
        } elseif (!(int)$user['enabled']) {
            $error = 'Your account is disabled. Contact the administrator.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            db_exec('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);
            log_activity('Auth', $user['name'] . ' logged in');
            header('Location: dashboard.php');
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
<title>Login | Gullapudi</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/layout.css">
</head>
<body>

<section class="auth-page">
    <div class="auth-card">

        <div class="auth-brand">
            <h1>GULLAPUDI</h1>
            <p>GLASS &amp; PLYWOOD CENTRE</p>
        </div>

        <h2 class="h5 fw-bold mb-3">Sign in to your account</h2>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?php echo e($error); ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

            <div class="mb-3">
                <label class="form-label fw-semibold" for="email">Email / Username</label>
                <input type="text" class="form-control" id="email" name="email"
                       value="<?php echo e($email); ?>" required autofocus>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold" for="password">Password</label>
                <div class="input-group">
                    <input type="password" class="form-control" id="password" name="password" required>
                    <button class="btn btn-outline-secondary" type="button" id="togglePass" aria-label="Show password">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-box-arrow-in-right me-1"></i> Login
            </button>
        </form>

    </div>
</section>

<script>
document.getElementById("togglePass").addEventListener("click", function () {
    const input = document.getElementById("password");
    const show = input.type === "password";
    input.type = show ? "text" : "password";
    this.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
});
</script>
</body>
</html>
