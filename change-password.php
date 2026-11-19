<?php
require_once __DIR__ . '/includes/init.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $row = db_one('SELECT password_hash FROM users WHERE id = ?', [(int)$_SESSION['user_id']]);

    if (!csrf_valid()) {
        $errors[] = 'Session expired. Please try again.';
    } elseif (!$row || !password_verify($current, $row['password_hash'])) {
        $errors[] = 'Current password is incorrect.';
    } elseif (strlen($new) < 8 || strlen($new) > 72) {
        $errors[] = 'New password must be 8 to 72 characters.';
    } elseif ($new !== $confirm) {
        $errors[] = 'New password and confirm password do not match.';
    } else {
        db_update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], (int)$_SESSION['user_id']);
        log_activity('Profile', 'Password changed');
        $success = 'Password changed successfully.';
    }
}

$pageTitle = 'Change Password';
include 'header.php';
?>

<section class="app-page">
<div class="container-fluid p-0">

    <div class="mb-4">
        <h1 class="app-title">Change Password</h1>
        <p class="app-subtitle">Keep your account secure</p>
    </div>

    <div class="row">
        <div class="col-12 col-md-8 col-xl-5">
            <div class="app-card">

                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo e($success); ?></div>
                <?php endif; ?>
                <?php foreach ($errors as $err): ?>
                    <div class="alert alert-danger py-2"><?php echo e($err); ?></div>
                <?php endforeach; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Password</label>
                        <input type="password" name="new_password" class="form-control" required minlength="8" maxlength="72">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="8" maxlength="72">
                    </div>

                    <button type="submit" class="btn-app"><i class="bi bi-shield-lock me-1"></i> Update Password</button>
                </form>
            </div>
        </div>
    </div>

</div>
</section>

<?php include 'footer.php'; ?>
